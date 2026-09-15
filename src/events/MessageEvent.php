<?php

namespace justinholtweb\yo\events;

use justinholtweb\yo\models\Message;
use yii\base\Event;

/**
 * Fired around a send.
 *
 * On `EVENT_BEFORE_SEND` the message is still mutable and `$isValid = false` stops it — which is
 * how one plugin suppresses or rewrites another plugin's noise without either of them knowing
 * about the other.
 */
class MessageEvent extends Event
{
    public Message $message;

    /** @var bool Set false on EVENT_BEFORE_SEND to drop the message. */
    public bool $isValid = true;

    /** @var string[] The channels it went to. Only meaningful after the send. */
    public array $channels = [];

    /** @var int How many copies were made. Only meaningful after the send. */
    public int $deliveries = 0;
}
