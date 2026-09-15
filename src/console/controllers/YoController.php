<?php

namespace justinholtweb\yo\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\Yo;
use yii\console\ExitCode;

/**
 * Yo from the command line.
 */
class YoController extends Controller
{
    /** @var string Who the message is for: `everyone`, `user:<id>` or `group:<handle>`. */
    public string $to = Message::AUDIENCE_EVERYONE;

    /** @var string The message type. */
    public string $type = 'notice';

    /** @var string A second line. */
    public string $body = '';

    /** @var bool Whether it waits to be dismissed. */
    public bool $stick = false;

    public function options($actionID): array
    {
        return match ($actionID) {
            'say' => ['to', 'type', 'body', 'stick'],
            default => [],
        };
    }

    /**
     * Sends a message.
     *
     * A console run has no current user, so this addresses everybody unless told otherwise —
     * a message to "the current user" from a cron job reaches nobody.
     *
     *     php craft yo/say "Deploy finished" --type=success --to=group:editors
     */
    public function actionSay(string $title): int
    {
        $message = Yo::say($title)
            ->body($this->body)
            ->type($this->type)
            ->to($this->to)
            ->from('yo');

        if ($this->stick) {
            $message->sticky();
        }

        if (!$message->send()) {
            $this->stderr("The message was not sent — something cancelled it, or it did not validate.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Sent to {$this->to}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Drops stored messages that are past their time to live.
     */
    public function actionPurge(): int
    {
        $purged = Plugin::getInstance()->messages->purgeExpired();

        $this->stdout("Purged $purged expired " . ($purged === 1 ? 'message' : 'messages') . ".\n");

        return ExitCode::OK;
    }

    /**
     * Lists the registered channels — including any another plugin added.
     */
    public function actionChannels(): int
    {
        foreach (Plugin::getInstance()->channels->getAllChannels() as $channel) {
            $this->stdout(str_pad($channel->handle(), 14), Console::FG_YELLOW);
            $this->stdout($channel->label());
            $this->stdout($channel->isQueued() ? "  (queued by Yo)\n" : "  (transport)\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }

    /**
     * Lists the registered message types.
     */
    public function actionTypes(): int
    {
        foreach (Plugin::getInstance()->types->getAllTypes() as $type) {
            $this->stdout(str_pad($type->handle, 14), Console::FG_YELLOW);
            $this->stdout(str_pad($type->label, 16));
            $this->stdout($type->color);
            $this->stdout($type->sticky ? "  sticky\n" : "\n", Console::FG_GREY);
        }

        return ExitCode::OK;
    }
}
