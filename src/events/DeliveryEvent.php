<?php

namespace justinholtweb\yo\events;

use justinholtweb\yo\models\Delivery;
use justinholtweb\yo\models\Message;
use yii\base\Event;

/**
 * Fired when something happens to one person's copy of a message: it was shown, read, dismissed,
 * or one of its buttons was pressed.
 *
 * This is the half Craft's flash session has no answer for. The sending plugin gets its own
 * `$message->context` back, so it can find whatever it was doing when it sent the thing.
 */
class DeliveryEvent extends Event
{
    public Message $message;

    public Delivery $delivery;

    /** @var string|null Which button, on EVENT_MESSAGE_ACTIONED. */
    public ?string $actionKey = null;

    /** @var int|null Who it happened to. Null for an anonymous front-end visitor. */
    public ?int $userId = null;
}
