<?php

namespace justinholtweb\yo\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\yo\models\Message;
use justinholtweb\yo\Plugin;

/**
 * A dashboard widget listing what the floating panel is holding.
 *
 * The panel is the plugin's answer to "a message just arrived". This is the answer to "what have I
 * been told lately" — the same messages, sitting still, on a screen somebody chose to visit. It
 * shows them without draining the queue: reading the dashboard is not the same as dismissing
 * everything on it.
 */
class YoWidget extends Widget
{
    /** @var int How many to list. */
    public int $limit = 10;

    /** @var bool Whether to show which plugin sent each one. */
    public bool $showSender = true;

    public static function displayName(): string
    {
        return Craft::t('yo', 'Yo');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/yo/icon-mask.svg') ?: null;
    }

    public static function maxColspan(): ?int
    {
        return 2;
    }

    public function getTitle(): ?string
    {
        return Craft::t('yo', 'Yo');
    }

    public function getBodyHtml(): ?string
    {
        $messages = Plugin::getInstance()->messages->forRecipient(
            Message::CHANNEL_CP,
            Craft::$app->getUser()->getId(),
            drain: false,
        );

        return Craft::$app->getView()->renderTemplate('yo/_widget', [
            'messages' => array_slice(array_map(fn($d) => $d->message->toWire(), $messages), 0, $this->limit),
            'showSender' => $this->showSender,
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('yo/_widget-settings', [
            'widget' => $this,
        ]);
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            [['limit'], 'integer', 'min' => 1, 'max' => 50],
        ]);
    }
}
