<?php

namespace justinholtweb\yo\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\yo\Plugin;
use RuntimeException;
use yii\queue\RetryableJobInterface;

/**
 * Posts one message to the webhook.
 *
 * The URL is read from the saved settings when the job runs, never stored in the job: the queue
 * table is not where a Slack URL — which is itself the credential — should sit.
 *
 * A refusal from the SSRF guard is final. A network error or a non-2xx is tried again, twice.
 */
class PostWebhook extends BaseJob implements RetryableJobInterface
{
    public const MAX_ATTEMPTS = 3;

    /** @var array<string, mixed> The body, already in the configured format. */
    public array $payload = [];

    public function execute($queue): void
    {
        $webhooks = Plugin::getInstance()->webhooks;
        $url = Plugin::getInstance()->getSettings()->webhookUrlValue();

        if ($url === '') {
            return;
        }

        $target = $webhooks->target($url);

        if (is_string($target)) {
            Craft::error('Yo refused to post a webhook: ' . $target, Plugin::LOG_CATEGORY);
            return;
        }

        $result = $webhooks->post($url, $this->payload);

        if ($result !== true) {
            throw new RuntimeException('Yo could not post a webhook: ' . $result);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('yo', 'Posting a Yo to the webhook');
    }

    public function getTtr(): int
    {
        return 60;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }
}
