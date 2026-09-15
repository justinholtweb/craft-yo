<?php

namespace justinholtweb\yo\services;

use Craft;
use craft\base\Component;
use justinholtweb\yo\events\RegisterMessageTypesEvent;
use justinholtweb\yo\models\MessageType;

/**
 * The registry of message types.
 *
 * @property-read MessageType[] $allTypes
 */
class Types extends Component
{
    /**
     * @event RegisterMessageTypesEvent Fired when the type list is first assembled.
     *
     * ```php
     * Event::on(Types::class, Types::EVENT_REGISTER_MESSAGE_TYPES, function(RegisterMessageTypesEvent $e) {
     *     $e->types['deployed'] = new MessageType([
     *         'handle' => 'deployed',
     *         'label' => 'Deployed',
     *         'color' => '#2f9e6b',
     *         'sticky' => true,
     *     ]);
     * });
     * ```
     */
    public const EVENT_REGISTER_MESSAGE_TYPES = 'registerMessageTypes';

    /** @var MessageType[]|null Keyed by handle. */
    private ?array $_types = null;

    /**
     * @return MessageType[] Keyed by handle.
     */
    public function getAllTypes(): array
    {
        if ($this->_types !== null) {
            return $this->_types;
        }

        $event = new RegisterMessageTypesEvent(['types' => $this->defaultTypes()]);
        $this->trigger(self::EVENT_REGISTER_MESSAGE_TYPES, $event);

        // Re-key on the handle so a listener that appended without a key still lands somewhere
        // findable, and so a listener that keyed it wrongly cannot make getTypeByHandle() lie.
        $types = [];

        foreach ($event->types as $type) {
            if ($type instanceof MessageType && $type->handle !== '') {
                $types[$type->handle] = $type;
            }
        }

        return $this->_types = $types;
    }

    public function getTypeByHandle(string $handle): ?MessageType
    {
        return $this->getAllTypes()[$handle] ?? null;
    }

    /** Drops the memoized list. Used by the tests, and after a settings change. */
    public function reset(): void
    {
        $this->_types = null;
    }

    /**
     * @return MessageType[]
     */
    private function defaultTypes(): array
    {
        return [
            MessageType::SUCCESS => new MessageType([
                'handle' => MessageType::SUCCESS,
                'label' => Craft::t('yo', 'Success'),
                'color' => '#5FBF8B',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m4 12.5 5 5L20 6.5"/></svg>',
                'sticky' => false,
                'priority' => 0,
            ]),
            MessageType::NOTICE => new MessageType([
                'handle' => MessageType::NOTICE,
                'label' => Craft::t('yo', 'Notice'),
                'color' => '#3FD0C9',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6v.1"/></svg>',
                'sticky' => false,
                'priority' => 0,
            ]),
            MessageType::TIP => new MessageType([
                'handle' => MessageType::TIP,
                'label' => Craft::t('yo', 'Tip'),
                'color' => '#E9A33A',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.4.3.5.7.5 1.1h6c0-.4.1-.8.5-1.1A6 6 0 0 0 12 3Z"/></svg>',
                'sticky' => false,
                'priority' => -10,
            ]),
            MessageType::WARNING => new MessageType([
                'handle' => MessageType::WARNING,
                'label' => Craft::t('yo', 'Warning'),
                'color' => '#F0A93A',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4.5 21 19H3l9-14.5Z"/><path d="M12 10v4M12 17v.1"/></svg>',
                'sticky' => true,
                'priority' => 10,
            ]),
            MessageType::ERROR => new MessageType([
                'handle' => MessageType::ERROR,
                'label' => Craft::t('yo', 'Error'),
                'color' => '#E4574C',
                'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.8 8.8 6.4 6.4M15.2 8.8l-6.4 6.4"/></svg>',
                'sticky' => true,
                'priority' => 20,
            ]),
        ];
    }
}
