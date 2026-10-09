<?php

namespace justinholtweb\yo\console\controllers;

use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use DateTimeInterface;
use justinholtweb\yo\db\Table;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\services\Digest;
use yii\console\ExitCode;

/**
 * `php craft yo/digest/send` — the email digest, for cron.
 *
 * Run it as often as you like; it sends once per period, and only once the configured day and
 * hour have arrived:
 *
 *     0,15,30,45 * * * * php /path/to/craft yo/digest/send
 *
 * Exits 0 for every outcome that is not a fault — not due yet, already sent, nothing waiting — so
 * cron stays quiet. Exits non-zero when the send failed (the next run will try again).
 */
class DigestController extends Controller
{
    public $defaultAction = 'send';

    /** Send now, whatever the schedule says and even if this period's digest has gone. */
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'send') {
            $options[] = 'force';
        }

        return $options;
    }

    public function actionSend(): int
    {
        $result = Plugin::getInstance()->digest->run(null, $this->force);

        [$message, $colour, $code] = match ($result) {
            Digest::RESULT_SENT => ['Digest sent.', Console::FG_GREEN, ExitCode::OK],
            Digest::RESULT_NOTHING_NEW => ['Nothing was waiting, so no digest was sent.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_NOT_DUE => ['Not due yet.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_ALREADY_SENT => ['This period’s digest has already gone.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_DISABLED => ['The email digest is off: switch the email channel on and set it to digest in Yo’s settings.', Console::FG_GREY, ExitCode::OK],
            default => ['The digest could not be sent; see storage/logs/yo.log. The next run will try again.', Console::FG_RED, ExitCode::UNSPECIFIED_ERROR],
        };

        $this->stdout($message . "\n", $colour);

        return $code;
    }

    /** What the schedule says: last run, last send, next due, and how much is waiting. */
    public function actionStatus(): int
    {
        $digest = Plugin::getInstance()->digest;
        $settings = Plugin::getInstance()->getSettings();
        $state = $digest->state();
        $next = $digest->nextDueAt();
        $format = fn(?DateTimeInterface $date) => $date?->format('Y-m-d H:i T') ?? '—';
        $waiting = (new Query())->from(Table::EMAIL_QUEUE);

        $this->stdout(str_pad('Enabled', 18) . ($digest->isEnabled() ? 'yes' : 'no') . "\n");
        $this->stdout(str_pad('Schedule', 18) . $settings->digestFrequency . "\n");
        $this->stdout(str_pad('Waiting', 18) . $waiting->count() . ' for ' . (clone $waiting)->select('userId')->distinct()->count() . " people\n");
        $this->stdout(str_pad('Last period', 18) . ($state['period'] ?? '—') . "\n");
        $this->stdout(str_pad('Last run', 18) . $format($state['lastRunAt']) . ' ' . ($state['lastResult'] ?? '') . "\n");
        $this->stdout(str_pad('Last sent', 18) . $format($state['lastSentAt']) . "\n");
        $this->stdout(str_pad('Next due', 18) . $format($next) . "\n");

        return ExitCode::OK;
    }
}
