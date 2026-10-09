<?php

namespace justinholtweb\yo\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;

/**
 * Emails one message to the people it was addressed to.
 *
 * Queued rather than sent inline: `send()` is called from inside other plugins' saves, and an SMTP
 * round trip per recipient is not something a save should wait for.
 */
class SendEmail extends BaseJob
{
    /** @var array<string, mixed> The message, as `Message::toStorage()` left it. */
    public array $message = [];

    /** @var int[] */
    public array $userIds = [];

    public function execute($queue): void
    {
        Plugin::getInstance()->email->sendMessage(Message::fromStorage($this->message), $this->userIds);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('yo', 'Emailing a Yo');
    }
}
