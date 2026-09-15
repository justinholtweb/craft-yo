<?php

namespace justinholtweb\yo\channels;

use Craft;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\models\Message;

/**
 * The front end.
 *
 * Off unless the site owner switches it on, and it never wants a message that did not ask for it
 * by name: a plugin announcing "entry saved" to whoever happens to be reading the blog is not a
 * feature.
 */
class SiteChannel extends BaseChannel
{
    public function handle(): string
    {
        return Message::CHANNEL_SITE;
    }

    public function label(): string
    {
        return Craft::t('yo', 'Front end');
    }

    public function wants(Message $message): bool
    {
        if (!Plugin::getInstance()->getSettings()->siteChannel) {
            return false;
        }

        return parent::wants($message);
    }

    protected function wantsByDefault(): bool
    {
        return false;
    }

    public function isQueued(): bool
    {
        return true;
    }
}
