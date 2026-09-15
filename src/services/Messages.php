<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use justinholtweb\yo\channels\ChannelInterface;
use justinholtweb\yo\db\Table;
use justinholtweb\yo\events\DefineDeliveryEvent;
use justinholtweb\yo\events\DeliveryEvent;
use justinholtweb\yo\events\MessageEvent;
use justinholtweb\yo\models\Delivery;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\records\DeliveryRecord;
use justinholtweb\yo\records\MessageRecord;
use Throwable;

/**
 * The bus.
 *
 * Everything a sending plugin does goes through `send()`, and everything a reading surface does
 * goes through `forRecipient()`. The two lanes underneath — a session queue for the person making
 * the request, a table for everybody else — are an implementation detail neither side can see.
 */
class Messages extends Component
{
    /**
     * @event MessageEvent Fired before a message is sent. Cancellable, and the message is still
     *        mutable: this is where one plugin edits or suppresses another plugin's message.
     */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    /** @event MessageEvent Fired after a message has been handed to every channel that wanted it. */
    public const EVENT_AFTER_SEND = 'afterSend';

    /**
     * @event DefineDeliveryEvent Fired once per send with the resolved channel list, before
     *        anything is written. Add a handle to send a copy elsewhere; remove one to hold it
     *        back.
     */
    public const EVENT_DEFINE_DELIVERY = 'defineDelivery';

    /** @event DeliveryEvent Fired the first time a person's copy reaches their screen. */
    public const EVENT_MESSAGE_SHOWN = 'messageShown';

    /** @event DeliveryEvent Fired when somebody opens a message. */
    public const EVENT_MESSAGE_READ = 'messageRead';

    /** @event DeliveryEvent Fired when somebody dismisses a message. */
    public const EVENT_MESSAGE_DISMISSED = 'messageDismissed';

    /** @event DeliveryEvent Fired when somebody presses one of a message's buttons. */
    public const EVENT_MESSAGE_ACTIONED = 'messageActioned';

    /** @event MessageEvent Fired when a stored message is dropped for being past its time to live. */
    public const EVENT_MESSAGE_EXPIRED = 'messageExpired';

    /** The session key the current-request lane lives under. */
    public const SESSION_KEY = 'yo.queue';

    /** @var bool Whether a console warning about `current` has already been logged this run. */
    private bool $_warnedAboutConsole = false;

    // ---------------------------------------------------------------------- sending

    /**
     * Sends a message.
     *
     * @return bool False if a `EVENT_BEFORE_SEND` listener cancelled it, or it did not validate.
     */
    public function send(Message $message): bool
    {
        if ($message->plugin === '') {
            $message->plugin = $this->callingPlugin();
        }

        if (!$message->validate()) {
            Craft::warning(
                'Refused a Yo that did not validate: ' . Json::encode($message->getErrors()),
                Plugin::LOG_CATEGORY,
            );

            return false;
        }

        $event = new MessageEvent(['message' => $message]);
        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return false;
        }

        // A listener is allowed to swap the message out entirely, not just edit it in place.
        $message = $event->message;

        $channels = $this->resolveChannels($message);

        if ($channels === []) {
            return false;
        }

        $queued = array_filter($channels, fn(ChannelInterface $c) => $c->isQueued());
        $direct = array_filter($channels, fn(ChannelInterface $c) => !$c->isQueued());

        $userIds = $this->resolveUserIds($message);
        $deliveries = 0;

        if ($queued !== []) {
            $deliveries = $this->queue($message, array_keys($queued));
        }

        foreach ($direct as $channel) {
            try {
                $channel->deliver($message, $userIds);
            } catch (Throwable $e) {
                // One broken transport must not lose the message everywhere else.
                Craft::error(
                    "The '{$channel->handle()}' Yo channel threw: " . $e->getMessage(),
                    Plugin::LOG_CATEGORY,
                );
            }
        }

        $after = new MessageEvent([
            'message' => $message,
            'channels' => array_keys($channels),
            'deliveries' => $deliveries,
        ]);

        $this->trigger(self::EVENT_AFTER_SEND, $after);

        return true;
    }

    /**
     * The channels a message is going to, after the channels have had their say and after
     * `EVENT_DEFINE_DELIVERY`.
     *
     * @return ChannelInterface[] Keyed by handle.
     */
    public function resolveChannels(Message $message): array
    {
        $channels = Plugin::getInstance()->channels->channelsFor($message);

        $event = new DefineDeliveryEvent([
            'message' => $message,
            'channels' => array_keys($channels),
        ]);

        $this->trigger(self::EVENT_DEFINE_DELIVERY, $event);

        $resolved = [];

        foreach ($event->channels as $handle) {
            $channel = Plugin::getInstance()->channels->getChannelByHandle($handle);

            if ($channel !== null) {
                $resolved[$handle] = $channel;
            }
        }

        return $resolved;
    }

    /**
     * Puts a message where its readers will find it.
     *
     * @param string[] $channelHandles
     * @return int How many people it was queued for. -1 means "everyone, worked out on read".
     */
    private function queue(Message $message, array $channelHandles): int
    {
        if (!$message->getIsStored()) {
            return $this->queueInSession($message, $channelHandles);
        }

        return $this->queueInDatabase($message, $channelHandles);
    }

    /**
     * @param string[] $channelHandles
     */
    private function queueInSession(Message $message, array $channelHandles): int
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            // There is no "current user" in a console run or a queue job, so there is nowhere for
            // this to go. Saying so once is worth more than a message that silently evaporates.
            if (!$this->_warnedAboutConsole) {
                $this->_warnedAboutConsole = true;
                Craft::warning(
                    'A Yo addressed to the current user was sent from a console request, where '
                    . 'there is no current user. Address it — ->to($user) or ->everyone() — for it '
                    . 'to reach anybody.',
                    Plugin::LOG_CATEGORY,
                );
            }

            return 0;
        }

        $stored = $message->toStorage();
        $stored['channels'] = $channelHandles;

        $queue = $this->sessionQueue();

        if ($message->dedupeKey !== null) {
            $queue = array_values(array_filter(
                $queue,
                fn(array $row) => ($row['dedupeKey'] ?? null) !== $message->dedupeKey,
            ));
        }

        $queue[] = $stored;

        $max = Plugin::getInstance()->getSettings()->maxPerRecipient;

        if (count($queue) > $max) {
            $queue = array_slice($queue, -$max);
        }

        Craft::$app->getSession()->set(self::SESSION_KEY, $queue);

        return 1;
    }

    /**
     * @param string[] $channelHandles
     */
    private function queueInDatabase(Message $message, array $channelHandles): int
    {
        if ($message->dedupeKey !== null) {
            $this->deleteStoredWhere([
                'dedupeKey' => $message->dedupeKey,
                'plugin' => $message->plugin,
            ]);
        }

        $record = new MessageRecord();
        $record->plugin = $message->plugin;
        $record->title = $message->title;
        $record->body = $message->body;
        $record->type = $message->type;
        $record->audience = $message->audience;
        $record->channels = Json::encode($channelHandles);
        $record->actions = Json::encode(array_map(fn($a) => $a->toArray(), $message->actions));
        $record->sticky = $message->sticky;
        $record->ttl = $message->ttl;
        $record->priority = $message->priority;
        $record->dedupeKey = $message->dedupeKey;
        $record->context = Json::encode($message->context);
        $record->uid = $message->uid;
        $record->save(false);

        $message->id = $record->id;

        // No delivery rows here on purpose. A message to everyone is one row, and the receipts
        // appear as people actually read it — which is the difference between announcing
        // something to a 400-user site and writing 400 rows to announce it.
        return $message->audience === Message::AUDIENCE_EVERYONE || str_starts_with($message->audience, 'group:')
            ? -1
            : 1;
    }

    // ---------------------------------------------------------------------- reading

    /**
     * Everything waiting for one person on one channel.
     *
     * Non-sticky session messages are drained as they are handed out — they have now been shown,
     * and a poll two seconds later must not show them again. Sticky ones stay until dismissed.
     *
     * @return Delivery[]
     */
    public function forRecipient(string $channel, ?int $userId = null, bool $drain = true): array
    {
        $deliveries = array_merge(
            $this->fromSession($channel, $drain),
            $this->fromDatabase($channel, $userId),
        );

        // Precomputed, because the effective priority falls back to the message type and
        // resolving that inside a comparator costs a registry lookup per comparison.
        $rank = [];

        foreach ($deliveries as $i => $delivery) {
            $message = $delivery->message;
            $rank[$i] = [
                $message->priority !== 0 ? $message->priority : $message->getMessageType()->priority,
                $message->dateCreated?->getTimestamp() ?? 0,
            ];
        }

        $keyed = array_keys($deliveries);
        usort($keyed, fn(int $a, int $b) => $rank[$b][0] <=> $rank[$a][0] ?: $rank[$b][1] <=> $rank[$a][1]);
        $deliveries = array_map(fn(int $i) => $deliveries[$i], $keyed);

        $max = Plugin::getInstance()->getSettings()->maxPerRecipient;

        return array_slice($deliveries, 0, $max);
    }

    /**
     * @return Delivery[]
     */
    private function fromSession(string $channel, bool $drain): array
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return [];
        }

        $queue = $this->sessionQueue();

        if ($queue === []) {
            return [];
        }

        $deliveries = [];
        $keep = [];

        foreach ($queue as $row) {
            $rowChannels = $row['channels'] ?? [];
            unset($row['channels']);

            $message = Message::fromStorage($row);
            $message->channels = $rowChannels;

            if (!in_array($channel, $rowChannels, true)) {
                $keep[] = $row + ['channels' => $rowChannels];
                continue;
            }

            if ($message->getIsExpired()) {
                $this->trigger(self::EVENT_MESSAGE_EXPIRED, new MessageEvent(['message' => $message]));
                continue;
            }

            $deliveries[] = new Delivery([
                'messageUid' => $message->uid,
                'channel' => $channel,
                'state' => Delivery::STATE_SHOWN,
                'message' => $message,
            ]);

            // A sticky message has to survive being handed out, or dismissing it would be the
            // only way it could ever leave the screen — including a page reload it never saw.
            if (!$drain || $message->getIsSticky()) {
                $keep[] = $row + ['channels' => $rowChannels];
            }
        }

        if ($drain) {
            Craft::$app->getSession()->set(self::SESSION_KEY, array_values($keep));
        }

        return $deliveries;
    }

    /**
     * @return Delivery[]
     */
    private function fromDatabase(string $channel, ?int $userId): array
    {
        if ($userId === null) {
            // Stored messages are addressed to accounts. An anonymous front-end visitor has none,
            // so there is nothing here for them — only the session lane.
            return [];
        }

        $audiences = [Message::AUDIENCE_EVERYONE, 'user:' . $userId];

        foreach ($this->groupHandlesFor($userId) as $handle) {
            $audiences[] = 'group:' . $handle;
        }

        $rows = (new Query())
            ->select(['m.*'])
            ->from(['m' => Table::MESSAGES])
            ->where(['m.audience' => $audiences])
            ->andWhere([
                'not exists',
                (new Query())
                    ->from(['d' => Table::DELIVERIES])
                    ->where('[[d.messageId]] = [[m.id]]')
                    ->andWhere([
                        'd.userId' => $userId,
                        'd.channel' => $channel,
                        'd.state' => Delivery::STATE_DISMISSED,
                    ]),
            ])
            ->orderBy(['m.priority' => SORT_DESC, 'm.dateCreated' => SORT_DESC])
            ->all();

        $deliveries = [];

        foreach ($rows as $row) {
            $channels = Json::decodeIfJson($row['channels']) ?: [];

            if (!in_array($channel, (array)$channels, true)) {
                continue;
            }

            $message = $this->messageFromRow($row);

            if ($message->getIsExpired()) {
                $this->trigger(self::EVENT_MESSAGE_EXPIRED, new MessageEvent(['message' => $message]));
                $this->deleteStoredWhere(['id' => $row['id']]);
                continue;
            }

            $deliveries[] = new Delivery([
                'messageId' => $message->id,
                'messageUid' => $message->uid,
                'userId' => $userId,
                'channel' => $channel,
                'state' => Delivery::STATE_QUEUED,
                'message' => $message,
            ]);
        }

        return $deliveries;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function messageFromRow(array $row): Message
    {
        $message = Message::fromStorage([
            'uid' => $row['uid'],
            'plugin' => $row['plugin'],
            'title' => $row['title'],
            'body' => (string)$row['body'],
            'type' => $row['type'],
            'audience' => $row['audience'],
            'sticky' => $row['sticky'] === null ? null : (bool)$row['sticky'],
            'ttl' => $row['ttl'] === null ? null : (int)$row['ttl'],
            'priority' => (int)$row['priority'],
            'dedupeKey' => $row['dedupeKey'],
            'context' => (array)(Json::decodeIfJson($row['context']) ?: []),
            'actions' => (array)(Json::decodeIfJson($row['actions']) ?: []),
            'dateCreated' => $row['dateCreated'],
        ]);

        $message->id = (int)$row['id'];
        $message->channels = (array)(Json::decodeIfJson($row['channels']) ?: []);

        return $message;
    }

    // ---------------------------------------------------------------------- receipts

    public function markShown(string $messageUid, ?int $userId, string $channel = Message::CHANNEL_CP): bool
    {
        return $this->receipt($messageUid, $userId, $channel, Delivery::STATE_SHOWN, 'dateShown', self::EVENT_MESSAGE_SHOWN);
    }

    public function markRead(string $messageUid, ?int $userId, string $channel = Message::CHANNEL_CP): bool
    {
        return $this->receipt($messageUid, $userId, $channel, Delivery::STATE_READ, 'dateRead', self::EVENT_MESSAGE_READ);
    }

    public function dismiss(string $messageUid, ?int $userId, string $channel = Message::CHANNEL_CP): bool
    {
        $this->dropFromSession($messageUid);

        return $this->receipt($messageUid, $userId, $channel, Delivery::STATE_DISMISSED, 'dateDismissed', self::EVENT_MESSAGE_DISMISSED);
    }

    /**
     * Files the receipt for a button press, then dismisses the message if the button said to.
     */
    public function actioned(string $messageUid, ?int $userId, string $actionKey, string $channel = Message::CHANNEL_CP): bool
    {
        $message = $this->getMessageByUid($messageUid, $userId, $channel);

        if ($message === null) {
            return false;
        }

        $delivery = $this->deliveryFor($message, $userId, $channel);
        $delivery->actionKey = $actionKey;

        if ($delivery->id !== null || $message->getIsStored()) {
            $this->persistDelivery($delivery, Delivery::STATE_READ, 'dateRead');
        }

        $this->trigger(self::EVENT_MESSAGE_ACTIONED, new DeliveryEvent([
            'message' => $message,
            'delivery' => $delivery,
            'actionKey' => $actionKey,
            'userId' => $userId,
        ]));

        foreach ($message->actions as $action) {
            if ($action->getKey() === $actionKey && $action->dismisses) {
                $this->dismiss($messageUid, $userId, $channel);
                break;
            }
        }

        return true;
    }

    /** Dismisses everything currently waiting for one person on one channel. */
    public function clearFor(?int $userId, string $channel = Message::CHANNEL_CP): int
    {
        $cleared = 0;

        foreach ($this->forRecipient($channel, $userId, false) as $delivery) {
            if ($this->dismiss($delivery->messageUid, $userId, $channel)) {
                $cleared++;
            }
        }

        return $cleared;
    }

    private function receipt(
        string $messageUid,
        ?int $userId,
        string $channel,
        string $state,
        string $dateAttribute,
        string $eventName,
    ): bool {
        $message = $this->getMessageByUid($messageUid, $userId, $channel);

        if ($message === null) {
            return false;
        }

        $delivery = $this->deliveryFor($message, $userId, $channel);

        // A session message has no row to write to, and does not need one: it belongs to one
        // person, in one session, and dismissing it removes it outright.
        if ($message->getIsStored() && $userId !== null) {
            $this->persistDelivery($delivery, $state, $dateAttribute);
        } else {
            $delivery->state = $state;
        }

        $this->trigger($eventName, new DeliveryEvent([
            'message' => $message,
            'delivery' => $delivery,
            'userId' => $userId,
        ]));

        return true;
    }

    private function persistDelivery(Delivery $delivery, string $state, string $dateAttribute): void
    {
        $record = DeliveryRecord::findOne([
            'messageId' => $delivery->messageId,
            'userId' => $delivery->userId,
            'channel' => $delivery->channel,
        ]) ?? new DeliveryRecord([
            'messageId' => $delivery->messageId,
            'userId' => $delivery->userId,
            'channel' => $delivery->channel,
        ]);

        // States only move forward. A poll that re-shows a message somebody already read must not
        // walk `read` back to `shown`.
        $order = [
            Delivery::STATE_QUEUED => 0,
            Delivery::STATE_SHOWN => 1,
            Delivery::STATE_READ => 2,
            Delivery::STATE_DISMISSED => 3,
        ];

        if (($order[$state] ?? 0) >= ($order[$record->state] ?? 0)) {
            $record->state = $state;
        }

        $record->$dateAttribute = Db::prepareDateForDb(new DateTime());

        if ($delivery->actionKey !== null) {
            $record->actionKey = $delivery->actionKey;
        }

        $record->save(false);

        $delivery->id = $record->id;
        $delivery->state = $record->state;
    }

    private function deliveryFor(Message $message, ?int $userId, string $channel): Delivery
    {
        return new Delivery([
            'messageId' => $message->id,
            'messageUid' => $message->uid,
            'userId' => $userId,
            'channel' => $channel,
            'message' => $message,
        ]);
    }

    // ---------------------------------------------------------------------- lookups

    /**
     * One message, from whichever lane it is in, but only if this person can see it.
     *
     * Every receipt goes through here, which is what stops a crafted uid from reading somebody
     * else's message or dismissing it on their behalf.
     */
    public function getMessageByUid(string $uid, ?int $userId, string $channel = Message::CHANNEL_CP): ?Message
    {
        foreach ($this->forRecipient($channel, $userId, false) as $delivery) {
            if ($delivery->messageUid === $uid) {
                return $delivery->message;
            }
        }

        return null;
    }

    /** How many messages are waiting, for the badge on the collapsed panel. */
    public function countFor(?int $userId, string $channel = Message::CHANNEL_CP): int
    {
        return count($this->forRecipient($channel, $userId, false));
    }

    /**
     * Drops stored messages that are past their time to live.
     *
     * @return int How many went.
     */
    public function purgeExpired(): int
    {
        $rows = (new Query())->from(Table::MESSAGES)->all();
        $purged = 0;

        foreach ($rows as $row) {
            $message = $this->messageFromRow($row);

            if (!$message->getIsExpired()) {
                continue;
            }

            $this->trigger(self::EVENT_MESSAGE_EXPIRED, new MessageEvent(['message' => $message]));
            $this->deleteStoredWhere(['id' => $row['id']]);
            $purged++;
        }

        return $purged;
    }

    /** @param array<string, mixed> $condition */
    public function deleteStoredWhere(array $condition): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete(Table::MESSAGES, $condition)
            ->execute();
    }

    // ---------------------------------------------------------------------- plumbing

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sessionQueue(): array
    {
        $queue = Craft::$app->getSession()->get(self::SESSION_KEY);

        return is_array($queue) ? $queue : [];
    }

    private function dropFromSession(string $messageUid): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        $queue = array_values(array_filter(
            $this->sessionQueue(),
            fn(array $row) => ($row['uid'] ?? null) !== $messageUid,
        ));

        Craft::$app->getSession()->set(self::SESSION_KEY, $queue);
    }

    /**
     * @return int[]
     */
    private function resolveUserIds(Message $message): array
    {
        if ($message->audience === Message::AUDIENCE_CURRENT) {
            // The user component exists in a console run too, and may have been given an
            // identity. What a console run lacks is a session — which is `queueInSession()`'s
            // problem, not this method's.
            $id = Craft::$app->getUser()->getId();

            return $id !== null ? [$id] : [];
        }

        if (str_starts_with($message->audience, 'user:')) {
            return [(int)substr($message->audience, 5)];
        }

        if (str_starts_with($message->audience, 'group:')) {
            return User::find()
                ->groupId($this->groupIdByHandle(substr($message->audience, 6)))
                ->status(null)
                ->ids();
        }

        return [];
    }

    private function groupIdByHandle(string $handle): int
    {
        return Craft::$app->getUserGroups()->getGroupByHandle($handle)?->id ?? 0;
    }

    /**
     * @return string[]
     */
    private function groupHandlesFor(int $userId): array
    {
        $user = Craft::$app->getUsers()->getUserById($userId);

        if ($user === null) {
            return [];
        }

        return array_map(fn($group) => $group->handle, $user->getGroups());
    }

    /**
     * Works out which plugin is calling, so a sender does not have to say.
     *
     * Walks the stack for the first frame belonging to a class Craft knows is a plugin. It is a
     * convenience, not a security boundary — `->from()` overrides it, and nothing downstream
     * trusts it for anything but a label.
     */
    private function callingPlugin(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $class = $frame['class'] ?? null;

            if ($class === null || str_starts_with($class, 'justinholtweb\\yo\\')) {
                continue;
            }

            $handle = Craft::$app->getPlugins()->getPluginHandleByClass($class);

            if ($handle !== null) {
                return $handle;
            }
        }

        return '';
    }
}
