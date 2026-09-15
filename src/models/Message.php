<?php

namespace justinholtweb\yo\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use DateTime;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\Yo;

/**
 * A message, and the fluent builder other plugins write against.
 *
 * Everything is optional except the headline. A sender that wants Craft's flash behaviour writes
 * one line; a sender that wants a sticky, targeted, actionable message with a receipt writes six.
 *
 * ```php
 * Yo::say('Import finished')
 *     ->body('412 entries in, 3 skipped.')
 *     ->success()
 *     ->action('Review the log', url: '/admin/transport/logs/88')
 *     ->to('group:editors')
 *     ->dedupe('transport:import')
 *     ->send();
 * ```
 */
class Message extends Model
{
    /** Everyone with control panel access. */
    public const AUDIENCE_EVERYONE = 'everyone';

    /** Whoever is making the current request. The default, and the cheap one. */
    public const AUDIENCE_CURRENT = 'current';

    public const CHANNEL_CP = 'cp';
    public const CHANNEL_SITE = 'site';

    /** @var string A UUID. Stable across the queue, the panel and any receipt. */
    public string $uid = '';

    /** @var string The handle of the plugin that sent it. Set for you; see Messages::send(). */
    public string $plugin = '';

    /** @var string The headline. The only required field. */
    public string $title = '';

    /** @var string A sentence or two under the headline. Plain text; markup is escaped. */
    public string $body = '';

    /** @var string A message type handle. Unknown handles fall back to `notice`. */
    public string $type = MessageType::NOTICE;

    /** @var MessageAction[] */
    public array $actions = [];

    /** @var string[] Channel handles. Empty means "ask the channels which of them want it". */
    public array $channels = [];

    /**
     * @var string Who it is for: `current`, `everyone`, `user:<id>`, `group:<handle>`.
     */
    public string $audience = self::AUDIENCE_CURRENT;

    /** @var bool|null Whether it waits to be dismissed. Null defers to the type. */
    public ?bool $sticky = null;

    /** @var int|null Seconds before it stops being offered. Null defers to the settings default. */
    public ?int $ttl = null;

    /** @var int Higher sorts first within the panel. */
    public int $priority = 0;

    /**
     * @var string|null A sender-chosen key. A second message with the same key replaces the first
     *                  rather than stacking under it — which is how a progress message works, and
     *                  how a plugin that fires on every save stops filling somebody's screen.
     */
    public ?string $dedupeKey = null;

    /**
     * @var array<string, mixed> Anything the sender wants handed back to itself on the receipt
     *                           events. Never rendered.
     */
    public array $context = [];

    /** @var DateTime|null When it was sent. */
    public ?DateTime $dateCreated = null;

    /** @var int|null Row id, once stored. Transient messages never have one. */
    public ?int $id = null;

    public function init(): void
    {
        parent::init();

        if ($this->uid === '') {
            $this->uid = StringHelper::UUID();
        }

        if ($this->dateCreated === null) {
            $this->dateCreated = new DateTime();
        }
    }

    public function rules(): array
    {
        return [
            [['title'], 'required'],
            [['title'], 'string', 'max' => 255],
            [['type', 'audience'], 'string', 'max' => 64],
            [['plugin'], 'string', 'max' => 64],
            [['priority'], 'integer'],
            [['ttl'], 'integer', 'min' => 0],
            [['dedupeKey'], 'string', 'max' => 191],
            [['audience'], 'match', 'pattern' => '/^(everyone|current|user:\d+|group:[a-zA-Z][a-zA-Z0-9_]*)$/'],
        ];
    }

    // ------------------------------------------------------------------ the builder

    public function body(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function type(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function success(): static
    {
        return $this->type(MessageType::SUCCESS);
    }

    public function notice(): static
    {
        return $this->type(MessageType::NOTICE);
    }

    public function warning(): static
    {
        return $this->type(MessageType::WARNING);
    }

    public function error(): static
    {
        return $this->type(MessageType::ERROR);
    }

    public function tip(): static
    {
        return $this->type(MessageType::TIP);
    }

    /** Names the sender. Messages::send() fills this in when it can work the caller out. */
    public function from(string $pluginHandle): static
    {
        $this->plugin = $pluginHandle;
        return $this;
    }

    /**
     * Adds a button.
     *
     * @param string $label What it says.
     * @param string|null $url An ordinary link.
     * @param string|null $action A Craft action path to POST to instead.
     * @param array<string, mixed> $params Extra POST parameters for $action.
     * @param string $key A key handed back on the actioned receipt.
     */
    public function action(
        string $label,
        ?string $url = null,
        ?string $action = null,
        array $params = [],
        string $key = '',
        bool $primary = false,
        bool $dismisses = true,
    ): static {
        $this->actions[] = new MessageAction([
            'label' => $label,
            'url' => $url,
            'action' => $action,
            'params' => $params,
            'key' => $key,
            'primary' => $primary,
            'dismisses' => $dismisses,
        ]);

        return $this;
    }

    /** @param string|string[] $channels */
    public function channels(array|string $channels): static
    {
        $this->channels = (array)$channels;
        return $this;
    }

    public function cp(): static
    {
        return $this->channels([self::CHANNEL_CP]);
    }

    public function site(): static
    {
        return $this->channels([self::CHANNEL_SITE]);
    }

    /**
     * @param string|int|\craft\elements\User $audience A user, a user id, `everyone`, or one of
     *        the `user:<id>` / `group:<handle>` strings.
     */
    public function to(mixed $audience): static
    {
        if ($audience instanceof \craft\elements\User) {
            $this->audience = 'user:' . $audience->id;
        } elseif (is_int($audience)) {
            $this->audience = 'user:' . $audience;
        } else {
            $this->audience = (string)$audience;
        }

        return $this;
    }

    public function everyone(): static
    {
        return $this->to(self::AUDIENCE_EVERYONE);
    }

    public function sticky(bool $sticky = true): static
    {
        $this->sticky = $sticky;
        return $this;
    }

    public function ttl(int $seconds): static
    {
        $this->ttl = $seconds;
        return $this;
    }

    public function priority(int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function dedupe(string $key): static
    {
        $this->dedupeKey = $key;
        return $this;
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): static
    {
        $this->context = $context;
        return $this;
    }

    /** Hands the message to the bus. Returns false if a listener cancelled it. */
    public function send(): bool
    {
        return Yo::send($this);
    }

    // ------------------------------------------------------------------ derived

    /** The resolved type, falling back to `notice` for a handle nobody registered. */
    public function getMessageType(): MessageType
    {
        return Plugin::getInstance()->types->getTypeByHandle($this->type)
            ?? Plugin::getInstance()->types->getTypeByHandle(MessageType::NOTICE);
    }

    /** Whether this one waits to be dismissed. */
    public function getIsSticky(): bool
    {
        return $this->sticky ?? $this->getMessageType()->sticky;
    }

    public function getEffectiveTtl(): int
    {
        return $this->ttl ?? Plugin::getInstance()->getSettings()->defaultTtl;
    }

    /**
     * Whether the message needs a row.
     *
     * A message for whoever is making this request rides in the session and costs nothing. A
     * message for somebody else — or one that has to outlive a session — has to be written down.
     * There is no third answer, and no setting: getting this wrong either loses messages or
     * writes a row every time an entry is saved.
     */
    public function getIsStored(): bool
    {
        return $this->audience !== self::AUDIENCE_CURRENT;
    }

    /** Whether it is past its time to live. */
    public function getIsExpired(): bool
    {
        $ttl = $this->getEffectiveTtl();

        if ($ttl <= 0) {
            return false;
        }

        $created = $this->dateCreated ?? new DateTime();

        return (time() - $created->getTimestamp()) > $ttl;
    }

    /**
     * The wire shape: what the panel, the front-end markup and the SSE patches all read.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $type = $this->getMessageType();

        return [
            'uid' => $this->uid,
            'plugin' => $this->plugin,
            'title' => $this->title,
            'body' => $this->body,
            'type' => $type->handle,
            'typeLabel' => $type->label,
            'color' => $type->color,
            'icon' => $type->icon,
            'sticky' => $this->getIsSticky(),
            'priority' => $this->priority !== 0 ? $this->priority : $type->priority,
            'dedupeKey' => $this->dedupeKey,
            'timestamp' => $this->dateCreated?->getTimestamp(),
            'actions' => array_map(fn(MessageAction $a) => [
                'label' => $a->label,
                'url' => $a->url,
                'action' => $a->action,
                'params' => $a->params,
                'key' => $a->getKey(),
                'primary' => $a->primary,
                'dismisses' => $a->dismisses,
            ], $this->actions),
        ];
    }

    /**
     * Round-trips through the session and the messages table.
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'uid' => $this->uid,
            'plugin' => $this->plugin,
            'title' => $this->title,
            'body' => $this->body,
            'type' => $this->type,
            'audience' => $this->audience,
            'channels' => $this->channels,
            'sticky' => $this->sticky,
            'ttl' => $this->ttl,
            'priority' => $this->priority,
            'dedupeKey' => $this->dedupeKey,
            'context' => $this->context,
            'actions' => array_map(fn(MessageAction $a) => $a->toArray(), $this->actions),
            'dateCreated' => $this->dateCreated?->getTimestamp(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromStorage(array $data): self
    {
        $actions = $data['actions'] ?? [];
        unset($data['actions']);

        $created = $data['dateCreated'] ?? null;
        unset($data['dateCreated']);

        // A stored TTL of null must stay null — it means "whatever the setting says now", not
        // "whatever the setting said when it was sent".
        $message = new self($data);
        $message->actions = array_map(fn(array $a) => new MessageAction($a), $actions);

        if ($created !== null) {
            $message->dateCreated = DateTimeHelper::toDateTime($created) ?: new DateTime();
        }

        return $message;
    }
}
