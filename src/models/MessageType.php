<?php

namespace justinholtweb\yo\models;

use craft\base\Model;

/**
 * A kind of message — its label, its colour and its icon.
 *
 * Yo ships five. A plugin can add its own from
 * {@see \justinholtweb\yo\services\Types::EVENT_REGISTER_MESSAGE_TYPES}, which is the whole point
 * of them being models rather than an enum: a deploy plugin that wants a "shipped" type in its own
 * green gets one without a fork.
 */
class MessageType extends Model
{
    public const SUCCESS = 'success';
    public const NOTICE = 'notice';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const TIP = 'tip';

    /** @var string Machine name, used on the wire and as a CSS class suffix. */
    public string $handle = '';

    /** @var string What a person sees. */
    public string $label = '';

    /** @var string Any CSS colour. Used for the accent bar in the control panel panel. */
    public string $color = '#8f8f9e';

    /**
     * @var string An inline SVG, or the empty string for none.
     *
     * Rendered verbatim into the control panel. Front-end markup never carries it — the site owner
     * decides what a warning looks like on their own pages.
     */
    public string $icon = '';

    /**
     * @var bool Whether a message of this type sticks around until it is dismissed, unless the
     *           message says otherwise. Errors do; a "saved" does not.
     */
    public bool $sticky = false;

    /** @var int Default priority for the type. Higher sorts first. */
    public int $priority = 0;

    public function rules(): array
    {
        return [
            [['handle', 'label'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'],
            [['color'], 'string', 'max' => 64],
        ];
    }
}
