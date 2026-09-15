<?php

namespace justinholtweb\yo;

use justinholtweb\yo\models\Message;
use justinholtweb\yo\models\MessageType;
use justinholtweb\yo\services\Channels;
use justinholtweb\yo\services\Messages;
use justinholtweb\yo\services\Types;

/**
 * The front door.
 *
 * A plugin that wants to send a message does not need to know about services, events, channels or
 * either of the two queues underneath. It needs this:
 *
 * ```php
 * use justinholtweb\yo\Yo;
 *
 * Yo::success('Saved.');
 * Yo::error('Could not reach the API.', 'Timed out after 30 seconds.');
 *
 * Yo::say('Import finished')
 *     ->body('412 entries in, 3 skipped.')
 *     ->success()
 *     ->action('Review the log', url: '/admin/transport/logs/88')
 *     ->to('group:editors')
 *     ->send();
 * ```
 *
 * Every method here is safe to call when Yo is present but switched off — they return false
 * rather than throwing. To keep Yo an *optional* dependency, guard the call site itself:
 *
 * ```php
 * if (class_exists(Yo::class) && Yo::isReady()) {
 *     Yo::success('Saved.');
 * }
 * ```
 *
 * A plugin should be able to send Yos without making Yo the price of admission for a site that
 * does not want the panel.
 */
final class Yo
{
    /**
     * Starts a message. Nothing is sent until `->send()` is called.
     */
    public static function say(string $title): Message
    {
        return new Message(['title' => $title]);
    }

    public static function success(string $title, string $body = ''): bool
    {
        return self::quick($title, $body, MessageType::SUCCESS);
    }

    public static function notice(string $title, string $body = ''): bool
    {
        return self::quick($title, $body, MessageType::NOTICE);
    }

    public static function warning(string $title, string $body = ''): bool
    {
        return self::quick($title, $body, MessageType::WARNING);
    }

    public static function error(string $title, string $body = ''): bool
    {
        return self::quick($title, $body, MessageType::ERROR);
    }

    public static function tip(string $title, string $body = ''): bool
    {
        return self::quick($title, $body, MessageType::TIP);
    }

    /** Sends a message somebody else built. */
    public static function send(Message $message): bool
    {
        if (!self::isReady()) {
            return false;
        }

        return Plugin::getInstance()->messages->send($message);
    }

    /**
     * Whether Yo is installed and switched on.
     *
     * Call it before doing expensive work to build a message; do not call it before sending one,
     * since every method here already checks.
     */
    public static function isReady(): bool
    {
        return Plugin::getInstance() !== null;
    }

    public static function messages(): ?Messages
    {
        return Plugin::getInstance()?->messages;
    }

    public static function types(): ?Types
    {
        return Plugin::getInstance()?->types;
    }

    public static function channels(): ?Channels
    {
        return Plugin::getInstance()?->channels;
    }

    private static function quick(string $title, string $body, string $type): bool
    {
        if (!self::isReady()) {
            return false;
        }

        return (new Message([
            'title' => $title,
            'body' => $body,
            'type' => $type,
        ]))->send();
    }
}
