<?php

namespace justinholtweb\yo\events;

use yii\base\Event;

/**
 * Fired once, the first time anything asks what channels exist.
 *
 * Add a class name, a config array, or an instantiated channel. Anything implementing
 * {@see \justinholtweb\yo\channels\ChannelInterface} will do.
 *
 * @see \justinholtweb\yo\services\Channels::EVENT_REGISTER_CHANNELS
 */
class RegisterChannelsEvent extends Event
{
    /** @var array<int|string, mixed> Channel classes, configs or instances. */
    public array $channels = [];
}
