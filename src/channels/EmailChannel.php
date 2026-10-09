<?php

namespace justinholtweb\yo\channels;

use Craft;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;

/**
 * Email: one message per message, or one digest per person per day or week.
 *
 * Off until an admin switches it on. Then it takes:
 *
 * - any message that names `email` among its channels, and
 * - a message that names no channels, when its type is in the routing table **and** it is
 *   addressed beyond the current request (`everyone`, a group, a user). A message to whoever is
 *   making the request is on their screen already — "Entry saved" in an inbox is noise, and every
 *   adopted Craft notice is one of those.
 *
 * It goes to the people the message is addressed to, never a list in settings.
 */
class EmailChannel extends BaseChannel
{
    public const HANDLE = 'email';

    public function handle(): string
    {
        return self::HANDLE;
    }

    public function label(): string
    {
        return Craft::t('yo', 'Email');
    }

    public function wants(Message $message): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->emailChannel) {
            return false;
        }

        if ($message->channels !== []) {
            return in_array(self::HANDLE, $message->channels, true);
        }

        return $message->getIsStored() && $settings->routes(self::HANDLE, $message->getMessageType()->handle);
    }

    protected function wantsByDefault(): bool
    {
        return false;
    }

    public function deliver(Message $message, array $userIds): void
    {
        Plugin::getInstance()->email->dispatch($message, $userIds);
    }
}
