---
title: The API
slug: api
order: 30
summary: Every event, every hook, and how to add a message type or a delivery channel of your own.
---

Yo is a plugin for plugins. Everything it does to a message is something another plugin can do
too, at the same seam, with the same result.

## Making Yo an optional dependency

The whole API is safe to call when Yo is installed but switched off — every method returns `false`
rather than throwing. To keep it optional entirely, guard the call site:

```php
use justinholtweb\yo\Yo;

if (class_exists(Yo::class) && Yo::isReady()) {
    Yo::success('Saved.');
}
```

Put `justinholtweb/craft-yo` in your `suggest`, not your `require`, and your plugin gains a panel
on the sites that have one and loses nothing on the sites that don't.

## Send-time events

### `Messages::EVENT_BEFORE_SEND`

Fired before anything is written. The message is mutable, and `$event->isValid = false` drops it.

```php
use justinholtweb\yo\services\Messages;
use justinholtweb\yo\events\MessageEvent;

Event::on(Messages::class, Messages::EVENT_BEFORE_SEND, function(MessageEvent $event) {
    // A site that has decided one chatty plugin has said enough.
    if ($event->message->plugin === 'someplugin' && $event->message->type === 'notice') {
        $event->isValid = false;
    }
});
```

This is the seam that makes Yo bearable on a site with thirty plugins: the site owner gets one
place to turn something down, rather than thirty settings screens.

### `Messages::EVENT_AFTER_SEND`

Fired once it has gone. `$event->channels` is where it went; `$event->deliveries` is how many
people it was queued for, or `-1` for a broadcast worked out on read.

### `Messages::EVENT_DEFINE_DELIVERY`

Fired with the resolved channel list, before anything is written. Add a handle to send a copy
somewhere else; remove one to hold it back.

```php
use justinholtweb\yo\events\DefineDeliveryEvent;

Event::on(Messages::class, Messages::EVENT_DEFINE_DELIVERY, function(DefineDeliveryEvent $event) {
    if ($event->message->type === 'error') {
        $event->channels[] = 'slack';
    }
});
```

## Receipt events

All four carry a `DeliveryEvent` with `$event->message`, `$event->delivery` and `$event->userId`.
The message carries the `context` array you set when you sent it, which is how you find whatever
you were doing at the time.

| Event | When |
| --- | --- |
| `EVENT_MESSAGE_SHOWN` | The first time this person's copy reached a screen |
| `EVENT_MESSAGE_READ` | They opened it |
| `EVENT_MESSAGE_DISMISSED` | They closed it |
| `EVENT_MESSAGE_ACTIONED` | They pressed a button — `$event->actionKey` says which |
| `EVENT_MESSAGE_EXPIRED` | It ran out of time before anybody looked (a `MessageEvent`) |

`EVENT_MESSAGE_ACTIONED` is the one worth building on. It turns a message into a control surface:

```php
Event::on(Messages::class, Messages::EVENT_MESSAGE_ACTIONED, function(DeliveryEvent $event) {
    match ($event->actionKey) {
        'retry' => $this->retry($event->message->context['jobId']),
        'ignore' => $this->suppress($event->message->context['ruleId'], $event->userId),
        default => null,
    };
});
```

## Registering a message type

```php
use justinholtweb\yo\services\Types;
use justinholtweb\yo\events\RegisterMessageTypesEvent;
use justinholtweb\yo\models\MessageType;

Event::on(Types::class, Types::EVENT_REGISTER_MESSAGE_TYPES, function(RegisterMessageTypesEvent $e) {
    $e->types['deployed'] = new MessageType([
        'handle' => 'deployed',
        'label' => 'Deployed',
        'color' => '#2f9e6b',
        'icon' => '<svg viewBox="0 0 24 24">…</svg>',
        'sticky' => true,
        'priority' => 15,
    ]);
});
```

Then `Yo::say('Live on production')->type('deployed')->send()`.

The icon is rendered verbatim in the control panel and never on the front end — what a warning
looks like on somebody's site is theirs to decide. `priority` sorts it in the panel; `sticky` says
whether a message of this type waits to be dismissed when the sender did not say.

A type registered without a key is re-keyed on its own handle, so a listener that appends rather
than assigns still works.

## Registering a channel

A channel is somewhere a message can end up. Yo ships three; the fourth is yours.

```php
use justinholtweb\yo\channels\BaseChannel;
use justinholtweb\yo\models\Message;

class SlackChannel extends BaseChannel
{
    public function handle(): string
    {
        return 'slack';
    }

    public function label(): string
    {
        return 'Slack';
    }

    /** Wait to be asked for by name — do not take everything by default. */
    protected function wantsByDefault(): bool
    {
        return false;
    }

    public function deliver(Message $message, array $userIds): void
    {
        MyPlugin::getInstance()->slack->post($message->title, $message->body);
    }
}
```

```php
use justinholtweb\yo\services\Channels;
use justinholtweb\yo\events\RegisterChannelsEvent;

Event::on(Channels::class, Channels::EVENT_REGISTER_CHANNELS, function(RegisterChannelsEvent $e) {
    $e->channels[] = SlackChannel::class;
});
```

Every plugin already sending Yos now reaches Slack, and none of them changed.

`isQueued()` is the other method worth knowing. It is `false` by default and means "I hand the
message on and have nothing left to track". The control panel and front-end channels return `true`
because Yo keeps their queue and their receipts. A transport almost never wants that.

A channel that throws is logged and stepped over — one broken transport cannot lose a message
everywhere else. A registration that is not a channel at all is logged and skipped.

## Template hooks

The panel invokes three, so a plugin can put its own markup inside it:

```php
Craft::$app->getView()->hook('yo.panel.footer', function(array &$context) {
    return '<a href="/admin/transport">Transport</a>';
});
```

| Hook | Where |
| --- | --- |
| `yo.panel.header` | In the panel's bar, after the count |
| `yo.panel.footer` | In the panel's footer, right-aligned |
| `yo.message.meta` | On every message, next to the sender and the time |

## Twig

| | |
| --- | --- |
| `craft.yo.init()` | Registers the assets and outputs the front-end container |
| `craft.yo.messages(drain = true)` | The waiting messages, as arrays |
| `craft.yo.count()` | How many are waiting, without taking them |
| `craft.yo.types()` | Every registered message type |
| `craft.yo.list(messages = null)` | Renders the list without the container |
| `craft.yo.say(title, body, type)` | Sends one to the front-end channel |

## Console

```sh
php craft yo/say "Deploy finished" --type=success --to=group:editors --body="v4.2.0"
php craft yo/say "Read this" --to=everyone --stick
php craft yo/channels
php craft yo/types
php craft yo/purge
```

## The wire shape

What the panel, the front-end template and every server patch all read:

```
uid, plugin, title, body, type, typeLabel, color, icon,
sticky, priority, dedupeKey, timestamp,
actions[] { label, url, action, params, key, primary, dismisses }
```
