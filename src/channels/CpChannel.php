<?php

namespace justinholtweb\yo\channels;

use Craft;
use justinholtweb\yo\models\Message;

/**
 * The control panel panel. The default home of a Yo.
 */
class CpChannel extends BaseChannel
{
    public function handle(): string
    {
        return Message::CHANNEL_CP;
    }

    public function label(): string
    {
        return Craft::t('yo', 'Control panel');
    }

    public function isQueued(): bool
    {
        return true;
    }
}
