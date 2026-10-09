<?php

namespace justinholtweb\yo\events;

use craft\events\CancelableEvent;

/**
 * Fired just before the email channel sends anything — one message, one person's digest, or a
 * test of either.
 *
 * Change who gets it, retitle it, add to what the templates see, or set `isValid` to false to
 * stop it. A cancelled digest drops that person's waiting items: the handler has decided they
 * should not get them, and holding them would only send them next time.
 */
class EmailEvent extends CancelableEvent
{
    /** @var string[] Email addresses. */
    public array $recipients = [];

    public string $subject = '';

    /** @var array<string, mixed> What the `_emails/*.twig` templates receive. */
    public array $variables = [];

    /** True for a digest, false for a single message. */
    public bool $isDigest = false;

    /** True for "Send a test". */
    public bool $isTest = false;

    /** @var int|null The person a digest is for. Null for a single message to several people. */
    public ?int $userId = null;
}
