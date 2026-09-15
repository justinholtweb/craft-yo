<?php

namespace justinholtweb\yo\events;

use justinholtweb\yo\models\Message;
use yii\base\Event;

/**
 * Fired once per send, after the default routing has been worked out and before anything is
 * written.
 *
 * `$channels` is the list of channel handles the message is about to go to. Add to it to send a
 * copy somewhere else; remove from it to hold one back. This is the seam a transport plugin —
 * Slack, e-mail, a webhook — attaches to.
 */
class DefineDeliveryEvent extends Event
{
    public Message $message;

    /** @var string[] Channel handles, mutable. */
    public array $channels = [];
}
