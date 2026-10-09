# Yo for Craft CMS 5

**A flash-message bus that plugins talk to.**

Craft has `setNotice()`. It is one string, for one person, on the next page load, with no type
beyond notice and error, no buttons, no way to reach anybody else, and no way for the plugin that
sent it to ever learn whether it was seen.

Yo is the layer that answers all of those — once — so that thirty plugins do not each answer them
differently. It is a plugin for plugins.

```php
use justinholtweb\yo\Yo;

Yo::success('Saved.');

Yo::say('Import finished')
    ->body('412 entries in, 3 skipped.')
    ->success()
    ->action('Review the log', url: '/admin/transport/logs/88')
    ->to('group:editors')
    ->dedupe('transport:import')
    ->send();
```

Messages arrive in a floating control panel panel you can drag wherever you like, on your own
front end in markup you style yourself, in the inboxes of the people they are addressed to, in a
Slack or Teams channel, or anywhere else a plugin has taught Yo to reach.

**Free. One edition. No paid tier.** It is infrastructure — it is worth more the more plugins use
it, and a price would be a tax on that.

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

## Installation

```sh
composer require justinholtweb/craft-yo
php craft plugin/install yo
```

Or find **Yo** in the Craft Plugin Store.

## What you get on day one, without changing any plugin

Switch **Adopt Craft's own notices** on — it is on by default — and every plugin on the site that
calls `setNotice()`, `setSuccess()` or `setError()` shows up in the panel instead of Craft's toast.
They do not know Yo exists. They do not have to.

## What you get if you write to it

| | Craft's flash | Yo |
| --- | --- | --- |
| Types | notice, error | five, and a plugin can register its own |
| Buttons | — | any number, links or POSTs |
| Recipients | whoever is making the request | anyone, a group, or everybody |
| Lifetime | the next page load | until dismissed, or a time to live |
| Repeats | stacks up | a dedupe key replaces the earlier one |
| Did they see it? | no answer | shown / read / dismissed / actioned, each an event |
| Where | the control panel | the control panel, the front end, email, Slack/Teams/a webhook, or a channel you wrote |

## Email, Slack and webhooks

Two more channels ship in the box, both off until an admin switches them on in Yo's settings:

- **Email** goes to the people a message is addressed to — a group's members, one user, or for
  `everyone`, everyone who can reach the control panel. Either one email per message, or a
  **daily or weekly digest** per person (`php craft yo/digest/send` on cron, with a web-request
  fallback for sites without one).
- **Webhook** posts to one shared destination: a Slack or Microsoft Teams incoming webhook, or
  plain JSON for your own receiver, optionally HMAC-signed. The URL is admin-set only (an
  environment variable is best — the URL is the credential), and every post goes through the
  family's SSRF guard: public addresses only, pinned at connect time, redirects never followed.

Each has a per-type routing table — "errors and warnings to email, errors to Slack" — that applies
to messages addressed beyond the current request. A plugin can also ask for either by name:

```php
Yo::say('Nightly import failed')->error()->to('group:editors')->channels(['cp', 'email', 'webhook'])->send();
```

## Events

Everything is a seam. Nothing in Yo is a special case another plugin could not have built.

```php
use justinholtweb\yo\services\Messages;
use justinholtweb\yo\events\DeliveryEvent;

// Learn what became of a message you sent.
Event::on(Messages::class, Messages::EVENT_MESSAGE_ACTIONED, function(DeliveryEvent $e) {
    if ($e->actionKey === 'retry') {
        MyPlugin::getInstance()->imports->retry($e->message->context['importId']);
    }
});
```

| Event | What it is for |
| --- | --- |
| `Messages::EVENT_BEFORE_SEND` | Edit or suppress a message — including somebody else's |
| `Messages::EVENT_AFTER_SEND` | Know that one went, and where |
| `Messages::EVENT_DEFINE_DELIVERY` | Route a message to a channel it never asked for |
| `Messages::EVENT_MESSAGE_SHOWN` | It reached a screen |
| `Messages::EVENT_MESSAGE_READ` | Somebody opened it |
| `Messages::EVENT_MESSAGE_DISMISSED` | Somebody closed it |
| `Messages::EVENT_MESSAGE_ACTIONED` | Somebody pressed one of its buttons |
| `Messages::EVENT_MESSAGE_EXPIRED` | It ran out of time before anybody looked |
| `Types::EVENT_REGISTER_MESSAGE_TYPES` | Add a type with its own colour, icon and stickiness |
| `Channels::EVENT_REGISTER_CHANNELS` | Add a destination of your own — SMS, a pager, a websocket |
| `Email::EVENT_BEFORE_SEND` | Change or cancel an email or one person's digest |
| `Webhooks::EVENT_BEFORE_POST` | Change or hold back a webhook post |

Three template hooks let a plugin put its own markup inside the panel: `yo.panel.header`,
`yo.panel.footer`, `yo.message.meta`.

## The front end

Yo ships **no front-end stylesheet**, deliberately. What a warning looks like on your site is a
design decision, and a plugin that arrives with an opinion about border radius is a plugin that
gets ripped out.

```twig
{{ craft.yo.init() }}
```

That registers the behaviour and puts a container where you put the tag. Style
`.yo-message`, `.yo-message--error` and friends however you like — or drop a
`yo/_messages.twig` into your own templates directory and take the markup over outright.

## Ajax without the Ajax

The panel updates itself with [DataStar](https://data-star.dev), vendored — no CDN, no build step,
no framework in your control panel. Dismissing, acting on and clearing a message all post to the
server and get back rendered HTML, which the client morphs into place. There is no client-side
template of a message anywhere, so a type a third-party plugin registered looks exactly as right as
one of Yo's own.

## Console

```sh
php craft yo/say "Deploy finished" --type=success --to=group:editors
php craft yo/channels     # every channel, including ones other plugins added
php craft yo/types        # every message type, same
php craft yo/purge        # drop messages past their time to live
php craft yo/digest/send  # the email digest, for cron — sends once per period
php craft yo/digest/status
```

## Documentation

Full docs at [justinholt.com/plugins/craft-yo](https://justinholt.com/plugins/craft-yo/docs).

## Who is the alien

An original character, drawn for this plugin: a furry, snouted, wisecracking houseguest from a
faraway planet who will not stop shouting from the corner of your control panel. Any resemblance
to a certain 1980s sitcom is affectionate and entirely the point.

## License

The Craft License. See `LICENSE.md`.
