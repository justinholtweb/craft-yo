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
            // The DataStar attribute prefix the markup is written for: `data-yo-ds-` for Yo's own
            // copy, or whatever the site's own DataStar answers to. See `datastarAttribute()`.
            'ds' => $this->datastarAttribute(),
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

        // Off, the site bundles yo-site.js itself and Yo writes nothing into the page but the
        // container below — no script tag, no inline config. See `runtimeConfig()`.
        if ($settings->siteInjectRuntime) {
            $view = Craft::$app->getView();
            $view->registerAssetBundle(YoSiteAsset::class);

            $view->registerJs(
                'window.YoSiteConfig = ' . Json::encode($this->injectedConfig(Craft::$app->getRequest()->getCsrfToken())) . ';',
                View::POS_HEAD,
            );
        }

        $prefix = $settings->siteClassPrefix;
        $poll = $settings->pollInterval;
        $pollUrl = UrlHelper::actionUrl('yo/stream/poll', ['channel' => Message::CHANNEL_SITE]);
        $ds = $this->datastarAttribute();

        // The container carries its own behaviour as DataStar attributes rather than being wired
        // up by a script that has to find it first. It works the moment it is in the DOM, wherever
        // the site owner chose to put it.
        $attributes = [
            'id' => 'yo-site-messages',
            'class' => $prefix,
            'role' => 'status',
            'aria-live' => 'polite',
            $ds . 'signals' => Json::encode(['yoCount' => 0, 'yoHasMessages' => false]),
            $ds . 'init' => "@get('{$pollUrl}')",
        ];

        if ($poll > 0) {
            $attributes[$ds . "on-interval__duration.{$poll}s"] = "@get('{$pollUrl}')";
        }

        return Html::tag('div', $this->render(), $attributes);
    }

    /**
     * What the front-end script needs to know about this site, for both ways of loading it: the
     * inline `window.YoSiteConfig` Yo injects, and the `data-yo-site-config` attribute
     * `craft.yo.runtimeConfig()` writes for a site that bundles `yo-site.js` itself. One method, so
     * the two cannot drift.
     *
     * Site-specific, never visitor-specific: a page carrying it stays cacheable. That is why the
     * CSRF token is not here — the injected path adds it (`injectedConfig()`), and a bundled
     * runtime fetches it from `sessionInfoUrl` (Craft's own `users/session-info`) when it needs it.
     *
     * @return array<string, mixed>
     */
    public function runtimeConfig(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        return [
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'prefix' => $settings->siteClassPrefix,
            'autoDismissAfter' => $settings->autoDismissAfter,
            'sessionInfoUrl' => UrlHelper::actionUrl('users/session-info'),
        ];
    }

    /**
     * The inline config Yo injects: `runtimeConfig()` plus this visitor's CSRF token, which is
     * what the message templates' DataStar click handlers send as `X-CSRF-Token`.
     *
     * @return array<string, mixed>
     */
    public function injectedConfig(string $csrfTokenValue): array
    {
        return $this->runtimeConfig() + ['csrfTokenValue' => $csrfTokenValue];
    }

    /**
     * The attribute prefix the front-end markup's DataStar attributes are written with.
     *
     * Yo's vendored DataStar is aliased and only reads `data-yo-ds-*`; while Yo loads it, that is
     * the only right answer. A site that bundles the script supplies DataStar itself — Yo's copy or
     * its own — and `siteDatastarAlias` says which dialect that one speaks.
     */
    public function datastarAttribute(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->siteInjectRuntime) {
            return 'data-yo-ds-';
        }

        $alias = trim($settings->siteDatastarAlias);

        return $alias === '' ? 'data-' : "data-{$alias}-";
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
