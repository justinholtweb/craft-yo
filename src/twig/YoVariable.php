<?php

namespace justinholtweb\yo\twig;

use Craft;
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
