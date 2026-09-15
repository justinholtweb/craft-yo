<?php

namespace justinholtweb\yo\channels;

use justinholtweb\yo\models\Message;
use yii\base\BaseObject;

/**
 * The half of a channel nobody wants to write twice.
 */
abstract class BaseChannel extends BaseObject implements ChannelInterface
{
    public function wants(Message $message): bool
    {
        if ($message->channels === []) {
            return $this->wantsByDefault();
        }

        return in_array($this->handle(), $message->channels, true);
    }

    /**
     * Whether a message that named no channels should reach this one.
     *
     * The two built-in queues say yes; a transport that would e-mail somebody every time an entry
     * saves should say no and wait to be asked for by name.
     */
    protected function wantsByDefault(): bool
    {
        return true;
    }

    public function isQueued(): bool
    {
        return false;
    }

    public function deliver(Message $message, array $userIds): void
    {
    }
}
