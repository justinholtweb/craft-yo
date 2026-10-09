<?php

namespace justinholtweb\yo\channels;

use Craft;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;

/**
 * One shared destination — a Slack or Teams channel, or your own endpoint — for the messages that
 * matter to the whole team.
 *
 * Off until an admin switches it on and saves a URL. It takes the same messages the email channel
 * would (named by the sender, or routed by type and addressed beyond the current request), and
 * posts each once, whoever it was addressed to: everybody who reads that channel sees it.
 */
class WebhookChannel extends BaseChannel
{
    public const HANDLE = 'webhook';

    public function handle(): string
    {
        return self::HANDLE;
    }

    public function label(): string
    {
        return Craft::t('yo', 'Webhook (Slack, Teams, JSON)');
    }

    public function wants(Message $message): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->webhookChannel || $settings->webhookUrlValue() === '') {
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
        Plugin::getInstance()->webhooks->dispatch($message);
    }
}
