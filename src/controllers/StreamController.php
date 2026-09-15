<?php

namespace justinholtweb\yo\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\yo\helpers\DatastarHelper;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The panel's whole conversation with the server.
 *
 * Every action answers in DataStar's SSE wire format: an element patch for the list and a signal
 * patch for the count. Nothing here returns JSON for the browser to render, and nothing here
 * renders markup the browser then has to reconcile — the server sends the list, the browser morphs
 * it in. That is the entire reason DataStar is in this plugin: a message list that changes shape
 * (buttons, bodies, types a third-party plugin registered) is a bad fit for client-side templating
 * and a good fit for sending the HTML that already exists.
 */
class StreamController extends Controller
{
    /**
     * Anonymous is allowed because the front-end channel serves visitors who are not signed in.
     * The control panel channel is guarded per-action instead — see `channel()`.
     */
    protected array|bool|int $allowAnonymous = [
        'poll' => self::ALLOW_ANONYMOUS_LIVE,
        'dismiss' => self::ALLOW_ANONYMOUS_LIVE,
        'read' => self::ALLOW_ANONYMOUS_LIVE,
        'act' => self::ALLOW_ANONYMOUS_LIVE,
        'clear' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    /** Hands back everything waiting, and how many that is. */
    public function actionPoll(): Response
    {
        $channel = $this->channel();

        return $this->patch($channel);
    }

    public function actionDismiss(): Response
    {
        $this->requirePostRequest();

        $channel = $this->channel();
        Plugin::getInstance()->messages->dismiss($this->uid(), $this->userId(), $channel);

        return $this->patch($channel, drain: false);
    }

    public function actionRead(): Response
    {
        $this->requirePostRequest();

        $channel = $this->channel();
        Plugin::getInstance()->messages->markRead($this->uid(), $this->userId(), $channel);

        return $this->patch($channel, drain: false);
    }

    /**
     * Records a button press, tells the sending plugin about it, and — if the button asked to —
     * dismisses the message.
     *
     * A button with an `action` of its own is posted by the browser separately. This endpoint is
     * about the receipt, not about doing the work, and keeping the two apart is what lets a
     * sender hook `EVENT_MESSAGE_ACTIONED` without also having to own an endpoint.
     */
    public function actionAct(): Response
    {
        $this->requirePostRequest();

        $channel = $this->channel();
        $key = (string)$this->request->getRequiredParam('key');

        Plugin::getInstance()->messages->actioned($this->uid(), $this->userId(), $key, $channel);

        return $this->patch($channel, drain: false);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $channel = $this->channel();
        Plugin::getInstance()->messages->clearFor($this->userId(), $channel);

        return $this->patch($channel, drain: false);
    }

    /** Moves the panel, and remembers where, for this person only. */
    public function actionPosition(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireLogin();

        $position = (string)$this->request->getRequiredParam('position');

        if (!array_key_exists($position, \justinholtweb\yo\models\Settings::positionOptions())) {
            throw new ForbiddenHttpException('Not a position the panel has.');
        }

        Plugin::getInstance()->panel->savePositionForCurrentUser($position);

        return DatastarHelper::response(['signals' => ['yoPosition' => $position]]);
    }

    /** Sends a Yo to whoever asked for it. The settings screen's "try it" button. */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireLogin();

        $type = (string)($this->request->getParam('type') ?: 'notice');

        \justinholtweb\yo\Yo::say(Craft::t('yo', 'Yo!'))
            ->body(Craft::t('yo', 'That is what one looks like. It came from the settings screen.'))
            ->type($type)
            ->from('yo')
            ->action(Craft::t('yo', 'Nice'), key: 'nice', primary: true)
            ->send();

        return $this->patch(Message::CHANNEL_CP);
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * The channel this request is about, having checked the caller is allowed to ask.
     */
    private function channel(): string
    {
        $channel = (string)($this->request->getParam('channel') ?: Message::CHANNEL_CP);

        if ($channel === Message::CHANNEL_CP) {
            // A guest has no control panel messages, and letting the request through anyway would
            // hand them whatever happens to be in a shared session.
            $this->requireLogin();
        } elseif ($channel === Message::CHANNEL_SITE) {
            if (!Plugin::getInstance()->getSettings()->siteChannel) {
                throw new ForbiddenHttpException('The front-end channel is switched off.');
            }
        } else {
            throw new ForbiddenHttpException('Not a channel messages can be read from.');
        }

        return $channel;
    }

    private function uid(): string
    {
        // `getRequiredParam`, not `getRequiredBodyParam`: DataStar puts the page's signals in the
        // body and takes no other passengers, so the uid arrives in the query string.
        return (string)$this->request->getRequiredParam('uid');
    }

    private function userId(): ?int
    {
        return Craft::$app->getUser()->getId();
    }

    /**
     * The one response shape every action returns: the rendered list, plus the count as a signal.
     */
    private function patch(string $channel, bool $drain = true): Response
    {
        $plugin = Plugin::getInstance();

        if ($channel === Message::CHANNEL_CP) {
            $messages = $plugin->panel->payload($drain);

            return DatastarHelper::response([
                'elements' => [
                    'selector' => '#yo-messages',
                    'mode' => 'inner',
                    'html' => $plugin->panel->render($messages),
                ],
                'signals' => [
                    'yoCount' => count($messages),
                    'yoHasMessages' => $messages !== [],
                ],
            ]);
        }

        $messages = $plugin->frontend->payload($drain);

        return DatastarHelper::response([
            'elements' => [
                'selector' => '#yo-site-messages',
                'mode' => 'inner',
                'html' => $plugin->frontend->render($messages),
            ],
            'signals' => [
                'yoCount' => count($messages),
                'yoHasMessages' => $messages !== [],
            ],
        ]);
    }
}
