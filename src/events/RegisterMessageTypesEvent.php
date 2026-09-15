<?php

namespace justinholtweb\yo\events;

use justinholtweb\yo\models\MessageType;
use yii\base\Event;

/**
 * Fired once, the first time anything asks what message types exist.
 *
 * @see \justinholtweb\yo\services\Types::EVENT_REGISTER_MESSAGE_TYPES
 */
class RegisterMessageTypesEvent extends Event
{
    /** @var MessageType[] Keyed by handle. */
    public array $types = [];
}
