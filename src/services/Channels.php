<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use justinholtweb\yo\channels\ChannelInterface;
use justinholtweb\yo\channels\CpChannel;
use justinholtweb\yo\channels\LogChannel;
use justinholtweb\yo\channels\SiteChannel;
use justinholtweb\yo\events\RegisterChannelsEvent;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;
use Throwable;

/**
 * The registry of delivery channels.
 *
 * @property-read ChannelInterface[] $allChannels
 */
class Channels extends Component
{
    /**
     * @event RegisterChannelsEvent Fired when the channel list is first assembled.
     *
     * ```php
     * Event::on(Channels::class, Channels::EVENT_REGISTER_CHANNELS, function(RegisterChannelsEvent $e) {
     *     $e->channels[] = MySlackChannel::class;
     * });
     * ```
     */
    public const EVENT_REGISTER_CHANNELS = 'registerChannels';

    /** @var ChannelInterface[]|null Keyed by handle. */
    private ?array $_channels = null;

    /**
     * @return ChannelInterface[] Keyed by handle.
     */
    public function getAllChannels(): array
    {
        if ($this->_channels !== null) {
            return $this->_channels;
        }

        $event = new RegisterChannelsEvent([
            'channels' => [
                CpChannel::class,
                SiteChannel::class,
                LogChannel::class,
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_CHANNELS, $event);

        $channels = [];

        foreach ($event->channels as $config) {
            $channel = $this->resolve($config);

            if ($channel !== null) {
                $channels[$channel->handle()] = $channel;
            }
        }

        return $this->_channels = $channels;
    }

    public function getChannelByHandle(string $handle): ?ChannelInterface
    {
        return $this->getAllChannels()[$handle] ?? null;
    }

    /**
     * The channels that want a given message.
     *
     * @return ChannelInterface[]
     */
    public function channelsFor(Message $message): array
    {
        return array_filter(
            $this->getAllChannels(),
            fn(ChannelInterface $channel) => $channel->wants($message),
        );
    }

    public function reset(): void
    {
        $this->_channels = null;
    }

    /**
     * A channel is registered as a class name, a config array or an instance. A registration that
     * cannot be turned into one is logged and skipped rather than thrown: a broken third-party
     * channel must not be able to take down every message on the site.
     */
    private function resolve(mixed $config): ?ChannelInterface
    {
        if ($config instanceof ChannelInterface) {
            return $config;
        }

        // Not `Component::createComponent()`: that helper insists the class implements Craft's
        // own `ComponentInterface`, which a channel has no reason to. `Craft::createObject()` is
        // the same container without the extra demand.
        try {
            if (is_string($config)) {
                $config = ['class' => $config];
            }

            // Craft's own component configs say `type`; Yii's say `class`. Take either.
            if (is_array($config) && isset($config['type']) && !isset($config['class'])) {
                $config['class'] = $config['type'];
                unset($config['type']);
            }

            $channel = Craft::createObject($config);
        } catch (Throwable $e) {
            Craft::error('Could not create a Yo channel: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }

        if (!$channel instanceof ChannelInterface) {
            Craft::error(
                'A registered Yo channel does not implement ChannelInterface: ' . get_debug_type($channel),
                Plugin::LOG_CATEGORY,
            );

            return null;
        }

        return $channel;
    }
}
