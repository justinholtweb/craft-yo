<?php

namespace justinholtweb\yo\channels;

use justinholtweb\yo\models\Message;

/**
 * Somewhere a message can end up.
 *
 * Yo ships five: the control panel panel, the front end, the log, email and a webhook (Slack,
 * Teams or JSON). A plugin registers another — SMS, a websocket, a pager — from
 * {@see \justinholtweb\yo\services\Channels::EVENT_REGISTER_CHANNELS} and every plugin already
 * sending Yos reaches it without a line of change.
 */
interface ChannelInterface
{
    /** Machine name. Unique across channels. */
    public function handle(): string;

    /** What a person sees in the settings screen. */
    public function label(): string;

    /**
     * Whether this channel wants a copy of the message.
     *
     * A message that named its channels is only offered to those; one that named none is offered
     * to every channel, and each decides for itself.
     */
    public function wants(Message $message): bool;

    /**
     * Whether Yo keeps the queue for this channel itself.
     *
     * True for the control panel and the front end: Yo writes the copy, the panel reads it, and
     * the read/dismiss receipts come back through Yo's own controller. False for a transport that
     * hands the message to something else and has nothing left to track.
     */
    public function isQueued(): bool;

    /**
     * Sends it.
     *
     * @param Message $message
     * @param int[] $userIds Who it is for. Empty for an unauthenticated front-end visitor.
     */
    public function deliver(Message $message, array $userIds): void;
}
