<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;
use justinholtweb\yo\web\assets\site\YoSiteAsset;

/**
 * The front-end channel.
 *
 * Yo ships no front-end stylesheet, on purpose. What a warning looks like on somebody's site is a
 * design decision, and a plugin that arrives with its own opinion about border radius is a plugin
 * that gets ripped out. What Yo does ship is: stable class names, a template you can override
 * outright, and the behaviour — arriving, dismissing, acting — already wired.
 */
class Frontend extends Component
{
    /** The template a site can drop into its own templates directory to take the markup over. */
    public const OVERRIDE_TEMPLATE = 'yo/_messages';

    private bool $_initialised = false;

    /**
     * The messages waiting for this visitor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payload(bool $drain = true): array
    {
        $userId = Craft::$app->getUser()->getId();
        $deliveries = Plugin::getInstance()->messages->forRecipient(Message::CHANNEL_SITE, $userId, $drain);

        $wire = [];

        foreach ($deliveries as $delivery) {
            $wire[] = $delivery->message->toWire();
            Plugin::getInstance()->messages->markShown($delivery->messageUid, $userId, Message::CHANNEL_SITE);
        }

        return $wire;
    }

    /**
     * Renders the list.
     *
     * A `yo/_messages.twig` in the site's own templates wins outright — not a partial override, not
     * a block, the whole thing. Anything less and the plugin ends up arguing with the designer
     * about markup it should never have had a view on.
     *
     * @param array<int, array<string, mixed>>|null $messages
     */
    public function render(?array $messages = null): string
    {
        $messages ??= $this->payload();

        $view = Craft::$app->getView();
        $variables = [
            'messages' => $messages,
            'prefix' => Plugin::getInstance()->getSettings()->siteClassPrefix,
        ];

        if ($view->doesTemplateExist(self::OVERRIDE_TEMPLATE, View::TEMPLATE_MODE_SITE)) {
            return $view->renderTemplate(self::OVERRIDE_TEMPLATE, $variables, View::TEMPLATE_MODE_SITE);
        }

        return $view->renderTemplate('yo/site/_messages', $variables, View::TEMPLATE_MODE_CP);
    }

    /**
     * Registers the assets and hands back the container.
     *
     * Idempotent: calling `craft.yo.init()` in a layout and again in a template that extends it
     * must not put two containers on the page, each polling.
     */
    public function container(): string
    {
        if ($this->_initialised) {
            return '';
        }

        $this->_initialised = true;

        $settings = Plugin::getInstance()->getSettings();
        $view = Craft::$app->getView();
        $view->registerAssetBundle(YoSiteAsset::class);

        $view->registerJs(
            'window.YoSiteConfig = ' . Json::encode([
                'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
                'csrfTokenValue' => Craft::$app->getRequest()->getCsrfToken(),
                'prefix' => $settings->siteClassPrefix,
                'autoDismissAfter' => $settings->autoDismissAfter,
            ]) . ';',
            View::POS_HEAD,
        );

        $prefix = $settings->siteClassPrefix;
        $poll = $settings->pollInterval;
        $pollUrl = UrlHelper::actionUrl('yo/stream/poll', ['channel' => Message::CHANNEL_SITE]);

        // The container carries its own behaviour as DataStar attributes rather than being wired
        // up by a script that has to find it first. It works the moment it is in the DOM, wherever
        // the site owner chose to put it.
        $attributes = [
            'id' => 'yo-site-messages',
            'class' => $prefix,
            'role' => 'status',
            'aria-live' => 'polite',
            'data-signals' => Json::encode(['yoCount' => 0, 'yoHasMessages' => false]),
            'data-on-load' => "@get('{$pollUrl}')",
        ];

        if ($poll > 0) {
            $attributes["data-on-interval__duration.{$poll}s"] = "@get('{$pollUrl}')";
        }

        return Html::tag('div', $this->render(), $attributes);
    }

    /** Whether `init()` has already run this request. */
    public function isInitialised(): bool
    {
        return $this->_initialised;
    }

    public function reset(): void
    {
        $this->_initialised = false;
    }
}
