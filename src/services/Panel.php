<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use justinholtweb\yo\models\Delivery;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;

/**
 * Everything the floating control panel panel needs, and the one place its markup is built.
 *
 * The panel is rendered twice by two different routes — once into the page, and again into every
 * server-sent patch — so both go through `render()`. Two implementations of the same list is how a
 * patched message ends up looking subtly unlike the ones already on screen.
 */
class Panel extends Component
{
    /** The user preference key holding where somebody dragged the panel to. */
    public const POSITION_PREF = 'yo.position';

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        return [
            'position' => $this->positionForCurrentUser(),
            'pollInterval' => $settings->pollInterval,
            'autoDismissAfter' => $settings->autoDismissAfter,
            'idleMascot' => $settings->idleMascot,
            'adoptCraftFlashes' => $settings->adoptCraftFlashes,
        ];
    }

    /**
     * Where this person's panel sits.
     *
     * A dragged position is a preference, not a setting: two people sharing a control panel do not
     * share a screen, and one of them moving the alien must not move it for the other.
     */
    public function positionForCurrentUser(): string
    {
        $default = Plugin::getInstance()->getSettings()->defaultPosition;

        // No console guard here. `getIsConsoleRequest()` answers about the *application*, and the
        // user component exists in a console run — guarding on it made every console-side read
        // return the default no matter what was stored. The session is the thing consoles lack,
        // and preferences are not in the session.
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return $default;
        }

        $stored = $user->getPreference(self::POSITION_PREF);

        return is_string($stored) && $stored !== '' ? $stored : $default;
    }

    public function savePositionForCurrentUser(string $position): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null) {
            return false;
        }

        Craft::$app->getUsers()->saveUserPreferences($user, [self::POSITION_PREF => $position]);

        return true;
    }

    /**
     * The messages waiting for whoever is asking, as the wire shapes the panel reads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function payload(bool $drain = true): array
    {
        $userId = Craft::$app->getUser()->getId();

        $deliveries = Plugin::getInstance()->messages->forRecipient(Message::CHANNEL_CP, $userId, $drain);

        $wire = [];

        foreach ($deliveries as $delivery) {
            $wire[] = $delivery->message->toWire();

            // Handing it over is the moment it was shown. Doing this here rather than in the
            // browser means a message that arrived while somebody had the tab closed is still
            // recorded as delivered when they come back.
            Plugin::getInstance()->messages->markShown($delivery->messageUid, $userId);
        }

        return $wire;
    }

    /**
     * The panel's message list, rendered.
     *
     * @param array<int, array<string, mixed>>|null $messages
     */
    public function render(?array $messages = null): string
    {
        $messages ??= $this->payload();

        $view = Craft::$app->getView();

        return $view->renderTemplate('yo/_panel-messages', [
            'messages' => $messages,
            'config' => $this->config(),
        ], $view::TEMPLATE_MODE_CP);
    }
}
