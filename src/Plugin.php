<?php

namespace justinholtweb\yo;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Gc;
use craft\web\Application as WebApplication;
use craft\web\Response;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use justinholtweb\yo\models\Settings;
use justinholtweb\yo\services\Adopt;
use justinholtweb\yo\services\Channels;
use justinholtweb\yo\services\Digest;
use justinholtweb\yo\services\Email;
use justinholtweb\yo\services\Frontend;
use justinholtweb\yo\services\Messages;
use justinholtweb\yo\services\Panel;
use justinholtweb\yo\services\Types;
use justinholtweb\yo\services\Webhooks;
use justinholtweb\yo\twig\YoVariable;
use justinholtweb\yo\web\assets\cp\YoCpAsset;
use justinholtweb\yo\widgets\YoWidget;
use Throwable;
use yii\base\Event;

/**
 * Yo — a flash-message bus for Craft plugins.
 *
 * Craft has `setNotice()`. It is one string, for one person, on the next page load, with no type
 * beyond notice and error, no buttons, no way to reach anybody else, and no way for the plugin
 * that sent it to ever learn whether it was seen. Yo is the layer that answers all of those, once,
 * so that thirty plugins do not each answer them differently.
 *
 * @property-read Messages $messages
 * @property-read Types $types
 * @property-read Channels $channels
 * @property-read Panel $panel
 * @property-read Frontend $frontend
 * @property-read Adopt $adopt
 * @property-read Email $email
 * @property-read Digest $digest
 * @property-read Webhooks $webhooks
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'yo';

    /**
     * Template hooks the panel invokes, for plugins that want to put something in it.
     *
     * ```php
     * Craft::$app->getView()->hook('yo.panel.footer', function(array &$context) {
     *     return '<a href="/admin/transport">Transport</a>';
     * });
     * ```
     */
    public const HOOK_PANEL_HEADER = 'yo.panel.header';
    public const HOOK_PANEL_FOOTER = 'yo.panel.footer';
    public const HOOK_MESSAGE_META = 'yo.message.meta';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'messages' => Messages::class,
                'types' => Types::class,
                'channels' => Channels::class,
                'panel' => Panel::class,
                'frontend' => Frontend::class,
                'adopt' => Adopt::class,
                'email' => Email::class,
                'digest' => Digest::class,
                'webhooks' => Webhooks::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerTwig();
        $this->registerWidget();
        $this->registerGarbageCollection();
        $this->registerDigestFallback();

        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return;
        }

        if ($request->getIsCpRequest()) {
            $this->registerCpPanel();
            return;
        }

        $this->registerSiteInjection();
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('yo/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'positions' => Settings::positionOptions(),
            'types' => $this->types->getAllTypes(),
            'channels' => $this->channels->getAllChannels(),
            'typeOptions' => array_map(
                fn($type) => ['value' => $type->handle, 'label' => $type->label],
                array_values($this->types->getAllTypes()),
            ),
            'digestState' => $this->safeDigestState(),
            'digestNext' => $this->digest->isEnabled() && $this->safeDigestState() !== null ? $this->digest->nextDueAt() : null,
        ]);
    }

    /**
     * The digest marker for the settings screen, or null before the migration that adds its table
     * has run — the settings screen must still open then.
     *
     * @return array<string, mixed>|null
     */
    private function safeDigestState(): ?array
    {
        try {
            return Craft::$app->getPlugins()->isPluginUpdatePending($this) ? null : $this->digest->state();
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------ control panel

    /**
     * Puts the panel on every control panel page.
     *
     * `EVENT_BEFORE_RENDER_PAGE_TEMPLATE` rather than `EVENT_BEFORE_RENDER_TEMPLATE`: the latter
     * fires for every partial too, and the flash drain below has to happen exactly once, before
     * anything renders. Firing it per-partial would adopt a notice on the first sub-template and
     * then find nothing on the rest — which works, right up until a page renders no partials.
     */
    private function registerCpPanel(): void
    {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function() {
            if (Craft::$app->getUser()->getIsGuest()) {
                return;
            }

            // Adoption happens whether or not the panel is drawn: a site that has switched the
            // panel off still wants the dashboard widget and the front end to see these.
            try {
                $this->adopt->drainCraftFlashes();
            } catch (Throwable $e) {
                Craft::error('Could not adopt Craft’s flashes: ' . $e->getMessage(), self::LOG_CATEGORY);
            }

            if (!$this->getSettings()->cpPanel) {
                return;
            }

            $view = Craft::$app->getView();
            $view->registerAssetBundle(YoCpAsset::class);

            $view->registerJs(
                'window.YoConfig = ' . Json::encode($this->cpConfig()) . ';',
                View::POS_HEAD,
            );

            // The panel goes at the end of the body, outside the control panel's own stacking
            // contexts. Anywhere inside `#content` it would be clipped by the first ancestor with
            // `overflow: hidden`, which on a Craft entry screen is most of them.
            try {
                $view->registerHtml(
                    $view->renderTemplate('yo/_panel', [
                        'config' => $this->panel->config(),
                        'messages' => $this->panel->payload(),
                    ], View::TEMPLATE_MODE_CP),
                    View::POS_END,
                    'yo-panel',
                );
            } catch (Throwable $e) {
                Craft::error('Could not render the Yo panel: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function cpConfig(): array
    {
        return $this->panel->config() + [
            'pollUrl' => UrlHelper::actionUrl('yo/stream/poll'),
            'dismissUrl' => UrlHelper::actionUrl('yo/stream/dismiss'),
            'positionUrl' => UrlHelper::actionUrl('yo/stream/position'),
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfTokenValue' => Craft::$app->getRequest()->getCsrfToken(),
        ];
    }

    // ------------------------------------------------------------------ front end

    /**
     * Splices the front-end container into HTML responses, for sites that asked for that rather
     * than calling `craft.yo.init()` themselves.
     *
     * Off by default. See `Settings::$siteAutoInject` for why.
     */
    private function registerSiteInjection(): void
    {
        Event::on(Response::class, Response::EVENT_AFTER_PREPARE, function(Event $event) {
            $settings = $this->getSettings();

            if (!$settings->siteChannel || !$settings->siteAutoInject) {
                return;
            }

            try {
                $this->inject($event->sender);
            } catch (Throwable $e) {
                Craft::error('Could not inject the Yo container: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    private function inject(Response $response): void
    {
        if ($this->frontend->isInitialised() || $response->getStatusCode() >= 500) {
            return;
        }

        if (!str_contains(strtolower((string)$response->getHeaders()->get('content-type')), 'text/html')) {
            return;
        }

        $html = $response->content;

        if (!is_string($html) || $html === '') {
            return;
        }

        $position = strripos($html, '</body>');

        if ($position === false) {
            return;
        }

        $response->content = substr_replace($html, $this->frontend->container(), $position, 0);

        // Craft stamps the length during prepare, before this runs. A stale one truncates the page
        // at the byte the container was added at, which reads as a broken template.
        $headers = $response->getHeaders();

        if ($headers->get('content-length') !== null) {
            $headers->set('content-length', (string)strlen($response->content));
        }
    }

    // ------------------------------------------------------------------ registration

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('yo', YoVariable::class);
        });
    }

    private function registerWidget(): void
    {
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = YoWidget::class;
        });
    }

    /**
     * Expired messages are swept with Craft's own rubbish rather than on a schedule of Yo's own.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            try {
                $this->messages->purgeExpired();
                $this->email->purgeStale();
            } catch (Throwable $e) {
                Craft::error('Could not purge expired Yos: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * The email digest's fallback trigger, for sites with no cron job running `yo/digest/send`.
     *
     * At the end of a web request rather than on `Gc::EVENT_RUN`: garbage collection runs on a
     * dice roll, which makes for a schedule nobody can predict. What runs here is a cache read;
     * the schedule is consulted at most every five minutes and the work happens in a queue job.
     */
    private function registerDigestFallback(): void
    {
        $settings = $this->getSettings();

        if (!$settings->emailChannel || $settings->emailMode !== Settings::EMAIL_DIGEST || !$settings->digestWebTrigger) {
            return;
        }

        Event::on(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST, function() {
            try {
                // Before the migration that adds the marker table has run, there is nowhere to
                // read the schedule from.
                if (!Craft::$app->getIsInstalled() || Craft::$app->getPlugins()->isPluginUpdatePending($this)) {
                    return;
                }

                $this->digest->queueIfDue();
            } catch (Throwable $e) {
                Craft::warning('Could not check the Yo digest schedule: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }
}
