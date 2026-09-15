<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\models\MessageType;
use justinholtweb\yo\Plugin;

/**
 * Picks up messages other plugins sent through Craft rather than through Yo.
 *
 * This is the reason Yo is worth installing on a site whose plugins have never heard of it. Craft
 * keeps control panel notifications in three flashes — `cp-notification-notice`, `-success` and
 * `-error` — which `_layouts/components/notifications.twig` reads and hands to
 * `Craft.cp.displayNotification()`. Draining them before that template renders moves every
 * plugin's notices into the panel with no change to any of them.
 *
 * What this cannot reach: notices returned in an Ajax response body (`$this->asSuccess()`), which
 * never touch the session at all. Those are caught on the browser side instead, by wrapping
 * `Craft.cp.displayNotification` — see `yo-cp.js`.
 */
class Adopt extends Component
{
    /** The flash keys Craft's own control panel notifications live under. */
    private const FLASHES = [
        'cp-notification-notice' => MessageType::NOTICE,
        'cp-notification-success' => MessageType::SUCCESS,
        'cp-notification-error' => MessageType::ERROR,
    ];

    /**
     * Moves Craft's pending control panel notifications into Yo.
     *
     * @return int How many were adopted.
     */
    public function drainCraftFlashes(): int
    {
        if (!Plugin::getInstance()->getSettings()->adoptCraftFlashes) {
            return 0;
        }

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || !$request->getIsCpRequest()) {
            return 0;
        }

        $session = Craft::$app->getSession();
        $adopted = 0;

        foreach (self::FLASHES as $key => $type) {
            // Delete on read. Leaving it behind means Craft's own toast fires as well and the
            // same sentence appears twice, in two different places, at slightly different times.
            $flash = $session->getFlash($key, null, true);

            if (!is_array($flash) || !isset($flash[0]) || !is_string($flash[0])) {
                continue;
            }

            $settings = is_array($flash[1] ?? null) ? $flash[1] : [];

            $message = new Message([
                'title' => $flash[0],
                'type' => $type,
                'plugin' => 'craft',
                'context' => ['adopted' => true, 'craftSettings' => $settings],
            ]);

            // Craft's own details string, when a plugin set one, is the closest thing it has to a
            // body — so it becomes one rather than being dropped.
            if (isset($settings['details']) && is_string($settings['details'])) {
                $message->body = $settings['details'];
            }

            if (Plugin::getInstance()->messages->send($message)) {
                $adopted++;
            }
        }

        return $adopted;
    }
}
