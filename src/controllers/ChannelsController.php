<?php

namespace justinholtweb\yo\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\yo\Plugin;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * The settings screen's "Send a test" buttons for the email and webhook channels.
 *
 * Admin-only, POST, CSRF-checked by Craft. Both send using the *saved* settings, never anything in
 * the request: taking a URL from the request would turn the webhook button into an SSRF probe for
 * anyone who can reach it. The email test goes to the person pressing the button.
 */
class ChannelsController extends Controller
{
    /** One test per user per channel per this many seconds. */
    public const TEST_COOLDOWN = 30;

    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        // Settings are an admin screen; the test only reads them, so admin changes need not be on.
        $this->requireAdmin(false);

        $channel = (string)$this->request->getRequiredBodyParam('channel');

        if (!in_array($channel, ['email', 'webhook'], true)) {
            throw new BadRequestHttpException('Unknown channel.');
        }

        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();

        if ($channel === 'webhook' && $plugin->getSettings()->webhookUrlValue() === '') {
            return $this->asFailure(Craft::t('yo', 'Save a webhook URL first.'));
        }

        if ($channel === 'email' && !$user?->email) {
            return $this->asFailure(Craft::t('yo', 'Your account has no email address to send a test to.'));
        }

        if (!Craft::$app->getCache()->add("yo:test:$channel:" . ($user->id ?? 0), 1, self::TEST_COOLDOWN)) {
            return $this->asFailure(Craft::t('yo', 'A test was sent a moment ago. Give it half a minute.'));
        }

        if ($channel === 'webhook') {
            $result = $plugin->webhooks->sendTest();

            return $result === true
                ? $this->asSuccess(Craft::t('yo', 'Webhook sent.'))
                : $this->asFailure(Craft::t('yo', 'Webhook failed: {reason}', ['reason' => (string)$result]));
        }

        try {
            $sent = $plugin->email->sendTest($user);
        } catch (Throwable $e) {
            Craft::error('Could not send a test email: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            $sent = 0;
        }

        return $sent > 0
            ? $this->asSuccess(Craft::t('yo', 'Test email sent to {email}.', ['email' => $user->email]))
            : $this->asFailure(Craft::t('yo', 'The test email could not be sent. Check Craft’s email settings and storage/logs/yo.log.'));
    }
}
