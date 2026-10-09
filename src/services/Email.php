<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use justinholtweb\yo\db\Table;
use justinholtweb\yo\events\EmailEvent;
use justinholtweb\yo\helpers\Mailer;
use justinholtweb\yo\jobs\SendEmail;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\models\Settings;
use justinholtweb\yo\Plugin;

/**
 * The email channel's half of the work: who a message reaches, and what lands in their inbox.
 *
 * Two modes. **Immediate** queues one {@see SendEmail} job per message, which sends one email per
 * person. **Digest** writes the message into `yo_email_queue` — one row per person — and
 * {@see Digest} sends each person one email a day (or week) listing everything that waited.
 *
 * Recipients are the people the message is addressed to, never a list typed into settings: a
 * message to `group:editors` emails the editors, a message to `everyone` emails everyone who can
 * reach the control panel. Only active accounts with an address are emailed.
 */
class Email extends Component
{
    /** @see EmailEvent Change the recipients, subject or variables, or cancel. */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    /** Most messages listed in one digest. The rest are a count and a link. */
    public const MAX_DIGEST_ITEMS = 25;

    /**
     * Waiting digest rows older than this are swept with Craft's rubbish. Only matters on a site
     * that switched the digest off with something still waiting.
     */
    public const QUEUE_RETENTION_DAYS = 35;

    // ------------------------------------------------------------------------------ dispatch

    /**
     * Hands a message to email, in whichever mode is set. Called by the email channel.
     *
     * @param int[] $userIds The ids the bus resolved. Empty for `everyone`, which is worked out here.
     * @return int How many people it was queued for.
     */
    public function dispatch(Message $message, array $userIds): int
    {
        $ids = $this->recipientIds($message, $userIds);

        if ($ids === []) {
            return 0;
        }

        if (Plugin::getInstance()->getSettings()->emailMode === Settings::EMAIL_DIGEST) {
            return $this->enqueue($message, $ids);
        }

        Craft::$app->getQueue()->push(new SendEmail([
            'message' => $message->toStorage(),
            'userIds' => $ids,
        ]));

        return count($ids);
    }

    /**
     * Who a message reaches by email: active accounts with an address.
     *
     * `everyone` means everyone who can reach the control panel — the same people the panel
     * shows it to — not every front-end member account on the site.
     *
     * @param int[] $userIds
     * @return int[]
     */
    public function recipientIds(Message $message, array $userIds): array
    {
        $query = User::find()->status(User::STATUS_ACTIVE)->email(':notempty:');

        if ($message->audience === Message::AUDIENCE_EVERYONE) {
            $query->can('accessCp');
        } elseif ($userIds === []) {
            return [];
        } else {
            $query->id(array_values(array_unique(array_map('intval', $userIds))));
        }

        return array_map('intval', $query->ids());
    }

    // ----------------------------------------------------------------------------- immediate

    /**
     * Emails one message to some people, now. What {@see SendEmail} runs.
     *
     * @param int[] $userIds
     * @return int How many recipients the mailer accepted it for.
     */
    public function sendMessage(Message $message, array $userIds, bool $test = false): int
    {
        if ($userIds === []) {
            return 0;
        }

        $users = User::find()
            ->id($userIds)
            ->status($test ? null : User::STATUS_ACTIVE)
            ->all();

        $recipients = array_values(array_filter(array_map(fn(User $user) => (string)$user->email, $users)));

        if ($recipients === []) {
            return 0;
        }

        $event = new EmailEvent([
            'recipients' => $recipients,
            'subject' => $this->messageSubject($message, $test),
            'variables' => $this->messageVariables($message, $test),
            'isTest' => $test,
        ]);

        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return 0;
        }

        return Mailer::send($event->recipients, $event->subject, 'yo/_emails/message', $event->variables);
    }

    // -------------------------------------------------------------------------------- digest

    /**
     * Files a message for each person's next digest.
     *
     * A message with a dedupe key replaces that sender's earlier one with the same key, exactly as
     * it does in the panel — a progress message should arrive once, at its latest.
     *
     * @param int[] $userIds
     */
    public function enqueue(Message $message, array $userIds): int
    {
        if ($message->dedupeKey !== null) {
            Db::delete(Table::EMAIL_QUEUE, [
                'plugin' => $message->plugin,
                'dedupeKey' => $message->dedupeKey,
                'userId' => $userIds,
            ]);
        }

        $links = Webhooks::links($message);
        $now = Db::prepareDateForDb(new DateTime());
        $rows = [];

        foreach ($userIds as $userId) {
            $rows[] = [
                (int)$userId,
                $message->uid,
                $message->plugin,
                $message->getMessageType()->handle,
                $message->title,
                $message->body,
                $links[0]['url'] ?? null,
                $message->dedupeKey,
                Db::prepareDateForDb($message->dateCreated ?? new DateTime()),
                $now,
                StringHelper::UUID(),
            ];
        }

        Db::batchInsert(
            Table::EMAIL_QUEUE,
            ['userId', 'messageUid', 'plugin', 'type', 'title', 'body', 'url', 'dedupeKey', 'dateCreated', 'dateUpdated', 'uid'],
            $rows,
        );

        return count($rows);
    }

    /**
     * What is waiting for digests, oldest first.
     *
     * @param int|null $upToId Only rows at or below this id — so a message filed while a digest
     *                         is being sent waits for the next one rather than vanishing.
     * @return array<int, array<string, mixed>>
     */
    public function pending(?int $userId = null, ?int $upToId = null): array
    {
        $query = (new Query())
            ->from(Table::EMAIL_QUEUE)
            ->orderBy(['dateCreated' => SORT_ASC, 'id' => SORT_ASC]);

        if ($userId !== null) {
            $query->andWhere(['userId' => $userId]);
        }

        if ($upToId !== null) {
            $query->andWhere(['<=', 'id', $upToId]);
        }

        return $query->all();
    }

    /**
     * Emails one person their digest.
     *
     * @param array<int, array<string, mixed>> $rows Their waiting rows, oldest first.
     * @return int|null 1 sent, 0 failed, null when an event handler cancelled it.
     */
    public function sendDigest(User $user, array $rows, ?DateTime $since, bool $test = false): ?int
    {
        if ($user->email === null || $user->email === '') {
            return 0;
        }

        $variables = $this->digestVariables($rows, $since, $test);

        $event = new EmailEvent([
            'recipients' => [$user->email],
            'subject' => $this->digestSubject(count($rows), $test),
            'variables' => $variables,
            'isDigest' => true,
            'isTest' => $test,
            'userId' => $user->id,
        ]);

        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return null;
        }

        return Mailer::send($event->recipients, $event->subject, 'yo/_emails/digest', $event->variables) > 0 ? 1 : 0;
    }

    /**
     * "Send a test email" — to the person pressing the button, in the mode that is set. A digest
     * test shows what their next digest would say (or a sample), and never consumes anything.
     */
    public function sendTest(User $user): int
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->emailMode === Settings::EMAIL_DIGEST) {
            $rows = $this->pending($user->id);

            if ($rows === []) {
                $rows = [[
                    'plugin' => 'yo',
                    'type' => 'notice',
                    'title' => Craft::t('yo', 'Yo! This is a test.'),
                    'body' => Craft::t('yo', 'Nothing is waiting for your next digest, so here is a stand-in.'),
                    'url' => null,
                    'dateCreated' => Db::prepareDateForDb(new DateTime()),
                ]];
            }

            return (int)$this->sendDigest($user, $rows, Plugin::getInstance()->digest->state()['lastSentAt'], true);
        }

        $message = new Message([
            'title' => Craft::t('yo', 'Yo! This is a test.'),
            'body' => Craft::t('yo', 'If you can read this, messages routed to email will reach you here.'),
            'type' => 'notice',
            'plugin' => 'yo',
        ]);

        return $this->sendMessage($message, [(int)$user->id], true);
    }

    /** Drops waiting rows past {@see QUEUE_RETENTION_DAYS}. Called from Craft's garbage collection. */
    public function purgeStale(): int
    {
        return Db::delete(Table::EMAIL_QUEUE, [
            '<', 'dateCreated', Db::prepareDateForDb(new DateTime('-' . self::QUEUE_RETENTION_DAYS . ' days')),
        ]);
    }

    // ----------------------------------------------------------------------------- the email

    private function messageSubject(Message $message, bool $test): string
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();

        return ($test ? Craft::t('yo', '[Test]') . ' ' : '') . '[' . $site . '] ' . $message->title;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageVariables(Message $message, bool $test): array
    {
        $type = $message->getMessageType();

        return [
            'siteName' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'isTest' => $test,
            'title' => $message->title,
            'body' => $message->body,
            'plugin' => $message->plugin,
            'typeLabel' => $type->label,
            'color' => $type->color,
            'links' => Webhooks::links($message),
            'cpUrl' => UrlHelper::cpUrl(),
        ];
    }

    private function digestSubject(int $count, bool $test): string
    {
        $site = Craft::$app->getSites()->getPrimarySite()->getName();

        return ($test ? Craft::t('yo', '[Test]') . ' ' : '') . Craft::t('yo', '{count, plural, =1{One message} other{# messages}} from {site}', [
            'count' => $count,
            'site' => $site,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function digestVariables(array $rows, ?DateTime $since, bool $test): array
    {
        $types = Plugin::getInstance()->types;
        $items = [];

        foreach (array_slice($rows, 0, self::MAX_DIGEST_ITEMS) as $row) {
            $type = $types->getTypeByHandle((string)$row['type']) ?? $types->getTypeByHandle('notice');

            $items[] = [
                'title' => (string)$row['title'],
                'body' => (string)($row['body'] ?? ''),
                'plugin' => (string)($row['plugin'] ?? ''),
                'typeLabel' => $type?->label ?? (string)$row['type'],
                'color' => $type?->color ?? '#8f8f9e',
                'url' => $row['url'] ?? null,
            ];
        }

        return [
            'siteName' => Craft::$app->getSites()->getPrimarySite()->getName(),
            'isTest' => $test,
            'items' => $items,
            'count' => count($rows),
            'more' => max(0, count($rows) - self::MAX_DIGEST_ITEMS),
            'since' => $since,
            'cpUrl' => UrlHelper::cpUrl(),
        ];
    }

    // ---------------------------------------------------------------------------- the table

    /** Shared by `Install` and the migration that adds the table to an existing install. */
    public static function createQueueTable(Migration $migration): void
    {
        $migration->createTable(Table::EMAIL_QUEUE, [
            'id' => $migration->primaryKey(),
            'userId' => $migration->integer()->notNull(),
            'messageUid' => $migration->char(36)->notNull(),
            'plugin' => $migration->string(64)->notNull()->defaultValue(''),
            'type' => $migration->string(64)->notNull()->defaultValue('notice'),
            'title' => $migration->string(255)->notNull(),
            'body' => $migration->text(),
            'url' => $migration->text(),
            'dedupeKey' => $migration->string(191),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::EMAIL_QUEUE, ['userId']);
        $migration->createIndex(null, Table::EMAIL_QUEUE, ['plugin', 'dedupeKey']);
        $migration->createIndex(null, Table::EMAIL_QUEUE, ['dateCreated']);

        // Somebody who no longer has an account has no digest to wait for.
        $migration->addForeignKey(null, Table::EMAIL_QUEUE, ['userId'], CraftTable::USERS, ['id'], 'CASCADE', null);
    }
}
