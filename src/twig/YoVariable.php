<?php

namespace justinholtweb\yo\twig;

use Craft;
use craft\helpers\Html;
use craft\helpers\Json;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\models\MessageType;
use justinholtweb\yo\Plugin;
use Twig\Markup;

/**
 * `craft.yo.*`.
 *
 * The front-end half of the plugin, and the smallest possible thing to put in a layout:
 *
 * ```twig
 * {{ craft.yo.init() }}
 * ```
 *
 * Everything else here exists for sites that would rather write the markup themselves.
 */
class YoVariable
{
    /**
     * Registers the assets and returns the container. Put it where the messages should appear.
     *
     * Safe to call twice — the second call returns nothing rather than a second container.
     */
    public function init(): Markup
    {
        return $this->raw(Plugin::getInstance()->frontend->container());
    }

    /**
     * The front-end script's configuration, for a site that bundles `yo-site.js` itself rather than
     * letting Yo load it (turn off “Load the front-end script for me”).
     *
     * ```twig
     * <body {{ craft.yo.runtimeConfig() }}>
     * ```
     *
     * The bundled script's `YoSite.start()` reads it, so the class prefix and the auto-dismiss
     * delay still come from Yo's settings. It is the same array the injected `window.YoSiteConfig`
     * is built from, minus the visitor's CSRF token — site-specific, never visitor-specific, so the
     * page stays cacheable. Renders nothing while the front-end channel is off, and never throws.
     */
    public function runtimeConfig(): Markup
    {
        try {
            $plugin = Plugin::getInstance();

            if (!$plugin->getSettings()->siteChannel) {
                return $this->raw('');
            }

            return $this->raw(Html::renderTagAttributes([
                'data-yo-site-config' => Json::encode($plugin->frontend->runtimeConfig()),
            ]));
        } catch (\Throwable $e) {
            Craft::warning('Yo could not render its front-end config: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->raw('');
        }
    }

    /**
     * The messages waiting for this visitor, as plain arrays.
     *
     * For a site building its own markup. Note that reading them marks them as shown, exactly as
     * the container does — asking for the list *is* the delivery.
     *
     * @return array<int, array<string, mixed>>
     */
    public function messages(bool $drain = true): array
    {
        return Plugin::getInstance()->frontend->payload($drain);
    }

    /** How many are waiting, without taking them. */
    public function count(): int
    {
        return Plugin::getInstance()->messages->countFor(
            Craft::$app->getUser()->getId(),
            Message::CHANNEL_SITE,
        );
    }

    /**
     * The registered message types, including any a plugin added.
     *
     * @return MessageType[]
     */
    public function types(): array
    {
        return Plugin::getInstance()->types->getAllTypes();
    }

    /** Renders the list on its own, without the container. */
    public function list(?array $messages = null): Markup
    {
        return $this->raw(Plugin::getInstance()->frontend->render($messages));
    }

    /**
     * Sends a message from a template.
     *
     * Rare, and deliberately unglamorous — the API a plugin uses is `Yo::say()` in PHP. This is
     * here for the template that has genuinely just found something worth saying.
     */
    public function say(string $title, string $body = '', string $type = MessageType::NOTICE): bool
    {
        return (new Message([
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'channels' => [Message::CHANNEL_SITE],
        ]))->send();
    }

    private function raw(string $html): Markup
    {
        return new Markup($html, Craft::$app->charset);
    }
}
