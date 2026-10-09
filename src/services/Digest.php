<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\yo\db\Table;
use justinholtweb\yo\jobs\SendDigest;
use justinholtweb\yo\models\Settings;
use justinholtweb\yo\Plugin;
use Throwable;
use yii\db\IntegrityException;

/**
 * The email digest: once a day or once a week, email each person what waited for them.
 *
 * Ported from craft-yarn's findings digest, the family's reference for scheduled email (see
 * "Scheduled digests" in Yarn's CLAUDE.md). The schedule, the durable marker, the claim that makes
 * a send happen once and the fallback trigger are Yarn's, unchanged. What differs is below "What
 * Yo reports": Yarn diffs a list of findings against the last run; Yo's items are rows in
 * `yo_email_queue`, so "new since last time" is simply "still in the table", and a row leaves the
 * table when its person's email is accepted.
 *
 * Three ways in, one way through:
 *
 * - `php craft yo/digest/send` from cron — the recommended route, every 15–60 minutes;
 * - the end of a web request, at most every five minutes, which queues {@see SendDigest};
 * - "Send a test email" in the settings, which never touches the marker or the rows.
 *
 * Nothing here hangs off `Gc::EVENT_RUN` — garbage collection runs on a dice roll, and a digest
 * that arrives on a dice roll is not a schedule.
 */
class Digest extends Component
{
    /** One row per digest the plugin sends. Yo has one. */
    public const HANDLE = 'email';

    public const RESULT_SENT = 'sent';
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_NOT_DUE = 'notDue';
    public const RESULT_ALREADY_SENT = 'alreadySent';
    public const RESULT_NOTHING_NEW = 'nothingNew';
    public const RESULT_FAILED = 'failed';

    /** How often the web fallback looks at the schedule at all. */
    public const WEB_CHECK_SECONDS = 300;

    private const WEB_CHECK_KEY = 'yo:digest:checked';
    private const QUEUED_KEY = 'yo:digest:queued:';

    // ------------------------------------------------------------------------------- schedule

    /** Now, in the system time zone — the one the digest hour is set in. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }

    /**
     * The period a moment falls in: `2026-10-09` for a daily digest, `2026-W41` (ISO week) for a
     * weekly one. One send per key, ever.
     */
    public function periodKey(DateTimeInterface $now): string
    {
        $now = $this->inSystemTime($now);

        return $this->settings()->digestFrequency === Settings::DIGEST_WEEKLY
            ? $now->format('o-\WW')
            : $now->format('Y-m-d');
    }

    /** When the digest for the period containing `$now` becomes due. */
    public function dueAt(DateTimeInterface $now): DateTimeImmutable
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now);

        if ($settings->digestFrequency === Settings::DIGEST_WEEKLY) {
            $now = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), $settings->digestWeekday);
        }

        return $now->setTime($settings->digestHour, 0);
    }

    /** Whether the digest is switched on at all: the email channel, in digest mode. */
    public function isEnabled(): bool
    {
        $settings = $this->settings();

        return $settings->emailChannel && $settings->emailMode === Settings::EMAIL_DIGEST;
    }

    /** Whether a scheduled run at `$now` would send (or at least try to). */
    public function isDue(?DateTimeInterface $now = null): bool
    {
        $now = $this->inSystemTime($now ?? $this->now());

        return $this->isEnabled()
            && $now >= $this->dueAt($now)
            && $this->state()['period'] !== $this->periodKey($now);
    }

    /**
     * When the next scheduled digest will go — for the settings screen. A digest that is due now
     * and has not gone yet answers with now.
     */
    public function nextDueAt(?DateTimeInterface $now = null): ?DateTimeImmutable
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if ($this->state()['period'] !== $this->periodKey($now)) {
            $due = $this->dueAt($now);

            return $due > $now ? $due : $now;
        }

        $next = $now->modify($this->settings()->digestFrequency === Settings::DIGEST_WEEKLY ? '+1 week' : '+1 day');

        return $this->dueAt($next);
    }

    // --------------------------------------------------------------------------- the triggers

    /**
     * The scheduled send. Idempotent: call it as often as you like, it sends once per period.
     *
     * @param bool $force Ignore the schedule and the "already sent" marker — `--force` on the
     *                    console command. Still records the send.
     * @return string One of the `RESULT_*` constants.
     */
    public function run(?DateTimeInterface $now = null, bool $force = false): string
    {
        $now = $this->inSystemTime($now ?? $this->now());

        if (!$force && !$this->isEnabled()) {
            return self::RESULT_DISABLED;
        }

        if (!$force && $now < $this->dueAt($now)) {
            return self::RESULT_NOT_DUE;
        }

        $period = $this->periodKey($now);
        $state = $this->state();

        if (!$force && ($state['period'] === $period || !$this->claim($period, $state['period']))) {
            return self::RESULT_ALREADY_SENT;
        }

        try {
            $result = $this->deliver($state['lastSentAt']);
        } catch (Throwable $e) {
            $this->release($period, $state['period']);
            Craft::error('Could not send the Yo email digest: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->record(null, self::RESULT_FAILED);
        }

        if ($result['people'] === 0) {
            return $this->record($period, self::RESULT_NOTHING_NEW);
        }

        if ($result['handled'] === 0) {
            // Give the period back, so the next run tries again rather than the day going by
            // without a digest because the mail server was down for five minutes at eight.
            $this->release($period, $state['period']);

            return $this->record(null, self::RESULT_FAILED);
        }

        return $this->record($period, self::RESULT_SENT, $result['sent']);
    }

    /**
     * The fallback for sites with no cron job: called at the end of web requests, looks at the
     * schedule at most every {@see WEB_CHECK_SECONDS}, and queues {@see SendDigest} when due.
     */
    public function queueIfDue(?DateTimeInterface $now = null): bool
    {
        if (!$this->isEnabled() || !$this->settings()->digestWebTrigger) {
            return false;
        }

        $cache = Craft::$app->getCache();

        // `add()` only writes when the key is absent, so of several requests landing together
        // one goes on to look at the schedule.
        if (!$cache->add(self::WEB_CHECK_KEY, 1, self::WEB_CHECK_SECONDS)) {
            return false;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if (!$this->isDue($now)) {
            return false;
        }

        if (!$cache->add(self::QUEUED_KEY . $this->periodKey($now), 1, 3600)) {
            return false;
        }

        Craft::$app->getQueue()->push(new SendDigest());

        return true;
    }

    // ------------------------------------------------------------------------ durable marker

    /**
     * The marker: which period was last claimed, and how the last run went.
     *
     * @return array{period: string|null, lastRunAt: DateTime|null, lastSentAt: DateTime|null, lastResult: string|null, lastCount: int}
     */
    public function state(): array
    {
        $row = $this->row();

        if ($row === null) {
            try {
                Db::insert(Table::DIGESTS, ['handle' => self::HANDLE, 'lastCount' => 0]);
            } catch (IntegrityException) {
                // Somebody else made it between our read and our write. Theirs will do.
            }

            $row = $this->row() ?? [];
        }

        return [
            'period' => isset($row['period']) && $row['period'] !== '' ? (string)$row['period'] : null,
            'lastRunAt' => DateTimeHelper::toDateTime($row['lastRunAt'] ?? null) ?: null,
            'lastSentAt' => DateTimeHelper::toDateTime($row['lastSentAt'] ?? null) ?: null,
            'lastResult' => $row['lastResult'] ?? null,
            'lastCount' => (int)($row['lastCount'] ?? 0),
        ];
    }

    /**
     * Moves the marker onto `$period`, but only if it still says `$previous`. Of two runs racing
     * for the same period, the database lets exactly one of them through.
     */
    private function claim(string $period, ?string $previous): bool
    {
        return Db::update(
            Table::DIGESTS,
            ['period' => $period],
            ['handle' => self::HANDLE, 'period' => $previous],
        ) === 1;
    }

    /** Hands a claimed period back after a failure, so the next run tries again. */
    private function release(string $period, ?string $previous): void
    {
        Db::update(Table::DIGESTS, ['period' => $previous], ['handle' => self::HANDLE, 'period' => $period]);
    }

    /**
     * Writes down how a run went. `$period` is null when nothing should change but the result —
     * a failure leaves the period as it was.
     */
    private function record(?string $period, string $result, ?int $count = null): string
    {
        $now = new DateTimeImmutable();
        $columns = [
            'lastRunAt' => Db::prepareDateForDb($now),
            'lastResult' => $result,
        ];

        if ($period !== null) {
            $columns['period'] = $period;
        }

        if ($result === self::RESULT_SENT) {
            $columns['lastSentAt'] = Db::prepareDateForDb($now);
            $columns['lastCount'] = (int)$count;
        }

        Db::update(Table::DIGESTS, $columns, ['handle' => self::HANDLE]);

        if ($result === self::RESULT_FAILED) {
            Craft::warning('The Yo email digest was not sent; the next run will try again.', Plugin::LOG_CATEGORY);
        } else {
            Craft::info("Yo email digest: $result.", Plugin::LOG_CATEGORY);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function row(): ?array
    {
        return (new Query())->from([Table::DIGESTS])->where(['handle' => self::HANDLE])->one() ?: null;
    }

    /** Shared by `Install` and the migration that adds the table to an existing install. */
    public static function createTable(Migration $migration): void
    {
        $migration->createTable(Table::DIGESTS, [
            'id' => $migration->primaryKey(),
            'handle' => $migration->string(64)->notNull(),
            // The period last claimed: `2026-10-09` or `2026-W41`. Null until the first run.
            'period' => $migration->string(32),
            'lastRunAt' => $migration->dateTime(),
            'lastSentAt' => $migration->dateTime(),
            'lastResult' => $migration->string(32),
            'lastCount' => $migration->integer()->notNull()->defaultValue(0),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::DIGESTS, ['handle'], true);
    }

    // ======================================================================== What Yo reports
    //
    // Yarn diffs findings against a "seen" list. Yo's items are queue rows: everything still in
    // `yo_email_queue` is new, and a person's rows leave it when their email is accepted.

    /**
     * Sends every person with something waiting their digest.
     *
     * Rows are snapshotted by id first, so a message filed while this runs waits for the next
     * digest rather than being deleted unsent. A person whose email fails keeps their rows for the
     * next run; a person a handler cancelled, or who is no longer an active account with an
     * address, has theirs dropped.
     *
     * @return array{people:int, sent:int, handled:int}
     */
    private function deliver(?DateTime $since): array
    {
        $upTo = (int)(new Query())->from(Table::EMAIL_QUEUE)->max('id');

        if ($upTo === 0) {
            return ['people' => 0, 'sent' => 0, 'handled' => 0];
        }

        $byUser = [];

        foreach (Plugin::getInstance()->email->pending(null, $upTo) as $row) {
            $byUser[(int)$row['userId']][] = $row;
        }

        $users = User::find()->id(array_keys($byUser))->status(null)->indexBy('id')->all();
        $sent = 0;
        $handled = 0;

        foreach ($byUser as $userId => $rows) {
            $user = $users[$userId] ?? null;

            $result = $user !== null && $user->getStatus() === User::STATUS_ACTIVE && $user->email
                ? Plugin::getInstance()->email->sendDigest($user, $rows, $since)
                : null;

            if ($result === 0) {
                continue;
            }

            if ($result === 1) {
                $sent++;
            }

            $handled++;
            Db::delete(Table::EMAIL_QUEUE, ['and', ['userId' => $userId], ['<=', 'id', $upTo]]);
        }

        return ['people' => count($byUser), 'sent' => $sent, 'handled' => $handled];
    }

    // ---------------------------------------------------------------------------------- misc

    private function inSystemTime(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
