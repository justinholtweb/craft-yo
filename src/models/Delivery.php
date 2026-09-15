<?php

namespace justinholtweb\yo\models;

use craft\base\Model;
use DateTime;

/**
 * One person's copy of one message.
 *
 * Yo separates the two because a message sent to a group is one thing that happened and N things
 * that have to be tracked. Read, dismissed and actioned are all facts about a *copy*.
 */
class Delivery extends Model
{
    public const STATE_QUEUED = 'queued';
    public const STATE_SHOWN = 'shown';
    public const STATE_READ = 'read';
    public const STATE_DISMISSED = 'dismissed';

    public ?int $id = null;
    public ?int $messageId = null;
    public string $messageUid = '';
    public ?int $userId = null;
    public string $channel = Message::CHANNEL_CP;
    public string $state = self::STATE_QUEUED;
    public ?string $actionKey = null;
    public ?DateTime $dateShown = null;
    public ?DateTime $dateRead = null;
    public ?DateTime $dateDismissed = null;

    /** @var Message|null The message this is a copy of, when it has been loaded alongside. */
    public ?Message $message = null;
}
