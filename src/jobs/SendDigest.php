<?php

namespace justinholtweb\yo\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\yo\Plugin;

/**
 * Sends the email digest if it is due. Queued by the web fallback trigger.
 *
 * Decides nothing itself: {@see \justinholtweb\yo\services\Digest::run()} checks the schedule and
 * claims the period, so a job that runs late, twice, or after cron already sent is a no-op.
 */
class SendDigest extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::getInstance()->digest->run();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('yo', 'Sending the Yo email digest');
    }

    public function getTtr(): int
    {
        // One email per person with something waiting.
        return 900;
    }
}
