<?php

namespace justinholtweb\yo\channels;

use Craft;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\models\Message;

/**
 * Writes every message it is given to Craft's log.
 *
 * It is also the shortest possible worked example of a transport channel: `wants()`, `deliver()`,
 * done. Anyone writing a Slack channel starts by reading this file.
 */
class LogChannel extends BaseChannel
{
    public function handle(): string
    {
        return 'log';
    }

    public function label(): string
    {
        return Craft::t('yo', 'Craft log');
    }

    public function wants(Message $message): bool
    {
        if ($message->channels !== [] && in_array($this->handle(), $message->channels, true)) {
            return true;
        }

        return Plugin::getInstance()->getSettings()->logMessages;
    }

    protected function wantsByDefault(): bool
    {
        return false;
    }

    public function deliver(Message $message, array $userIds): void
    {
        Craft::info(
            sprintf(
                '[%s] %s%s',
                $message->plugin !== '' ? $message->plugin : 'unknown',
                $message->title,
                $message->body !== '' ? ' — ' . $message->body : '',
            ),
            Plugin::LOG_CATEGORY,
        );
    }
}
