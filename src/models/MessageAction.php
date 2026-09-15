<?php

namespace justinholtweb\yo\models;

use craft\base\Model;

/**
 * A button on a message.
 *
 * Two shapes: a link (`url`), or a POST back to the plugin that sent the message (`action`, a
 * Craft action path). The second is what makes a Yo more than a toast — "Undo", "Retry",
 * "Run it again" — and it is why {@see \justinholtweb\yo\services\Messages::EVENT_MESSAGE_ACTIONED}
 * exists: the sending plugin gets told which button was pressed, on which message, by whom.
 */
class MessageAction extends Model
{
    public string $label = '';

    /** @var string|null An ordinary URL. Mutually exclusive with $action. */
    public ?string $url = null;

    /** @var string|null A Craft action path, e.g. `transport/imports/retry`. Posted, not linked. */
    public ?string $action = null;

    /** @var array<string, mixed> Extra POST parameters for $action. */
    public array $params = [];

    /** @var bool Whether pressing it dismisses the message. */
    public bool $dismisses = true;

    /** @var bool Whether it is the emphasised button. At most one per message, by convention. */
    public bool $primary = false;

    /** @var string A key the sender recognises, handed back on EVENT_MESSAGE_ACTIONED. */
    public string $key = '';

    public function rules(): array
    {
        return [
            [['label'], 'required'],
            [['url', 'action'], 'string'],
        ];
    }

    /**
     * The key a receipt is filed under. Falls back to the label so a sender that never set one
     * still gets something it can branch on.
     */
    public function getKey(): string
    {
        return $this->key !== '' ? $this->key : $this->label;
    }
}
