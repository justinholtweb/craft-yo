<?php

namespace justinholtweb\yo\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Yo settings.
 *
 * Nothing here is `required` — a fresh install has to be able to save any one of these without
 * every other field being filled in first.
 */
class Settings extends Model
{
    public const POSITION_TOP_LEFT = 'top-left';
    public const POSITION_TOP_CENTER = 'top-center';
    public const POSITION_TOP_RIGHT = 'top-right';
    public const POSITION_BOTTOM_LEFT = 'bottom-left';
    public const POSITION_BOTTOM_CENTER = 'bottom-center';
    public const POSITION_BOTTOM_RIGHT = 'bottom-right';

    public const EMAIL_IMMEDIATE = 'immediate';
    public const EMAIL_DIGEST = 'digest';

    public const DIGEST_DAILY = 'daily';
    public const DIGEST_WEEKLY = 'weekly';

    public const WEBHOOK_SLACK = 'slack';
    public const WEBHOOK_TEAMS = 'teams';
    public const WEBHOOK_JSON = 'json';

    /** @var bool Whether the floating panel is registered on control panel pages at all. */
    public bool $cpPanel = true;

    /**
     * @var string Where the panel starts life. Each person can drag it somewhere else, and that
     *             choice is theirs — this is only the position they have not overridden yet.
     */
    public string $defaultPosition = self::POSITION_BOTTOM_RIGHT;

    /** @var bool Whether the alien is on screen when the panel is empty. */
    public bool $idleMascot = true;

    /**
     * @var bool Whether Yo adopts messages other plugins send through Craft's own flash session
     *           (`setNotice()` / `setError()`).
     *
     * On, every plugin on the site gets Yo without knowing Yo exists. Off, only plugins that call
     * Yo directly appear in the panel and Craft's own notices are left alone.
     */
    public bool $adoptCraftFlashes = true;

    /** @var int How often the panel asks for new messages, in seconds. 0 turns polling off. */
    public int $pollInterval = 10;

    /** @var int Seconds a non-sticky message stays on screen before it fades. */
    public int $autoDismissAfter = 6;

    /** @var int Default time to live, in seconds. 0 means it never expires on its own. */
    public int $defaultTtl = 604800;

    /** @var int The most messages one person can have waiting. Oldest are dropped past this. */
    public int $maxPerRecipient = 50;

    /** @var bool Whether the front-end channel is switched on. */
    public bool $siteChannel = false;

    /**
     * @var bool Whether Yo splices its container into front-end HTML by itself.
     *
     * Off by default, deliberately. The front-end markup is the site owner's to place and style,
     * and a plugin that silently injects a div into somebody's careful layout has made a decision
     * that was not its to make. `{% do craft.yo.init() %}` is one line.
     */
    public bool $siteAutoInject = false;

    /** @var string The CSS class prefix on front-end markup. */
    public string $siteClassPrefix = 'yo';

    /** @var bool Whether every send is also written to the logs. */
    public bool $logMessages = false;

    // ------------------------------------------------------------------ email channel
    // Nothing here is `required` either. An email channel that is switched off, or a webhook with
    // no URL, simply sends nothing.

    /** @var bool Whether the `email` channel is switched on. */
    public bool $emailChannel = false;

    /**
     * @var string[]|string Message types the email channel takes without being asked by name.
     *                      `*` is every type, including ones a plugin registers later.
     */
    public array|string $emailTypes = ['error', 'warning'];

    /** @var string `immediate` — one email per message — or `digest`, one per person per period. */
    public string $emailMode = self::EMAIL_IMMEDIATE;

    /** @var string `daily` or `weekly`. */
    public string $digestFrequency = self::DIGEST_DAILY;

    /** @var int ISO day of the week for a weekly digest: 1 is Monday, 7 is Sunday. */
    public int $digestWeekday = 1;

    /** @var int Hour of the day, 0–23, in the system time zone, after which the digest is due. */
    public int $digestHour = 8;

    /**
     * @var bool Also check whether the digest is due at the end of web requests (at most every five
     *           minutes) and queue it, for sites with no cron job running `yo/digest/send`.
     */
    public bool $digestWebTrigger = true;

    // ------------------------------------------------------------------ webhook channel

    /** @var bool Whether the `webhook` channel is switched on. */
    public bool $webhookChannel = false;

    /** @var string A Slack or Teams incoming-webhook URL, or your own endpoint (or `$ENV`). */
    public string $webhookUrl = '';

    /** @var string `slack`, `teams` or `json` — the shape of the body. */
    public string $webhookFormat = self::WEBHOOK_SLACK;

    /** @var string Optional. When set, each post carries an `X-Yo-Signature` HMAC of its body. */
    public string $webhookSecret = '';

    /** @var string[]|string Message types the webhook takes without being asked by name. `*` is every type. */
    public array|string $webhookTypes = ['error'];

    /**
     * @var bool Config-file only: let the webhook reach private, loopback and link-local hosts (a
     *           self-hosted Mattermost on the LAN). The scheme and no-redirect rules still hold.
     */
    public bool $allowPrivateWebhookHosts = false;

    public function rules(): array
    {
        return [
            [['pollInterval', 'autoDismissAfter', 'defaultTtl', 'maxPerRecipient'], 'integer', 'min' => 0],
            [['maxPerRecipient'], 'integer', 'min' => 1],
            [['defaultPosition'], 'in', 'range' => array_keys(self::positionOptions())],
            [['siteClassPrefix'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'],
            [['emailMode'], 'in', 'range' => [self::EMAIL_IMMEDIATE, self::EMAIL_DIGEST]],
            [['digestFrequency'], 'in', 'range' => [self::DIGEST_DAILY, self::DIGEST_WEEKLY]],
            [['digestWeekday'], 'integer', 'min' => 1, 'max' => 7],
            [['digestHour'], 'integer', 'min' => 0, 'max' => 23],
            [['webhookFormat'], 'in', 'range' => [self::WEBHOOK_SLACK, self::WEBHOOK_TEAMS, self::WEBHOOK_JSON]],
            [['webhookUrl', 'webhookSecret'], 'string', 'max' => 2000],
            [['webhookUrl'], 'validateWebhookUrl'],
            [['emailTypes', 'webhookTypes'], 'validateTypes', 'skipOnEmpty' => false],
        ];
    }

    /**
     * Only the shape is checked here. Where the host resolves is checked at send time, every time,
     * because DNS can change between a save and a send.
     */
    public function validateWebhookUrl(string $attribute): void
    {
        $url = $this->webhookUrlValue();

        if ($url === '') {
            return;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

        if (!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST)) {
            $this->addError($attribute, Craft::t('yo', 'Only http:// and https:// webhook URLs are allowed.'));
        }
    }

    /**
     * A routing list is `*` or a list of handle-shaped strings. Handles nobody has registered are
     * allowed: the plugin that registers them may simply not be installed yet.
     */
    public function validateTypes(string $attribute): void
    {
        $value = $this->$attribute;

        if ($value === '*' || $value === '' || $value === null) {
            return;
        }

        if (!is_array($value)) {
            $this->addError($attribute, Craft::t('yo', 'Choose message types from the list.'));
            return;
        }

        foreach ($value as $handle) {
            if (!is_string($handle) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_\-]*$/', $handle)) {
                $this->addError($attribute, Craft::t('yo', 'Choose message types from the list.'));
                return;
            }
        }
    }

    /**
     * Whether the routing table sends this type to a channel.
     *
     * @param string $channel `email` or `webhook`.
     */
    public function routes(string $channel, string $typeHandle): bool
    {
        $value = match ($channel) {
            'email' => $this->emailTypes,
            'webhook' => $this->webhookTypes,
            default => [],
        };

        if ($value === '*') {
            return true;
        }

        return is_array($value) && in_array($typeHandle, $value, true);
    }

    /** The webhook URL with `$ENV` resolved; empty when unset or unresolved. */
    public function webhookUrlValue(): string
    {
        $url = trim((string)App::parseEnv($this->webhookUrl));

        return str_starts_with($url, '$') ? '' : $url;
    }

    /** The signing secret with `$ENV` resolved; empty when unset or unresolved. */
    public function webhookSecretValue(): string
    {
        $secret = trim((string)App::parseEnv($this->webhookSecret));

        return str_starts_with($secret, '$') ? '' : $secret;
    }

    /**
     * @return array<string, string>
     */
    public static function positionOptions(): array
    {
        return [
            self::POSITION_TOP_LEFT => Craft::t('yo', 'Top left'),
            self::POSITION_TOP_CENTER => Craft::t('yo', 'Top centre'),
            self::POSITION_TOP_RIGHT => Craft::t('yo', 'Top right'),
            self::POSITION_BOTTOM_LEFT => Craft::t('yo', 'Bottom left'),
            self::POSITION_BOTTOM_CENTER => Craft::t('yo', 'Bottom centre'),
            self::POSITION_BOTTOM_RIGHT => Craft::t('yo', 'Bottom right'),
        ];
    }
}
