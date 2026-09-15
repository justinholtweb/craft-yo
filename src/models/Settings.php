<?php

namespace justinholtweb\yo\models;

use Craft;
use craft\base\Model;

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

    public function rules(): array
    {
        return [
            [['pollInterval', 'autoDismissAfter', 'defaultTtl', 'maxPerRecipient'], 'integer', 'min' => 0],
            [['maxPerRecipient'], 'integer', 'min' => 1],
            [['defaultPosition'], 'in', 'range' => array_keys(self::positionOptions())],
            [['siteClassPrefix'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'],
        ];
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
