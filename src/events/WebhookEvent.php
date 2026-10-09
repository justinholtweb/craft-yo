<?php

namespace justinholtweb\yo\events;

use craft\events\CancelableEvent;
use justinholtweb\yo\models\Message;

/**
 * Fired when the webhook channel takes a message, before the post is queued.
 *
 * `$payload` is the body in the configured format (Slack, Teams or plain JSON). Change it to add
 * fields, or set `isValid` to false to hold this one back.
 */
class WebhookEvent extends CancelableEvent
{
    public Message $message;

    /** @var array<string, mixed> */
    public array $payload = [];
}
