---
title: Usage
slug: usage
order: 20
summary: Sending a message, addressing it, giving it buttons, and putting it on your front end.
---

## The one-liners

```php
use justinholtweb\yo\Yo;

Yo::success('Saved.');
Yo::notice('Nothing to do — everything was already up to date.');
Yo::tip('Your slowest template is _partials/nav.twig.');
Yo::warning('Three entries went live without a meta description.');
Yo::error('Could not reach the API.', 'Timed out after 30 seconds.');
```

Each takes a headline and an optional second line. Each goes to whoever is making the current
request, in the control panel, and each returns `true` unless something cancelled it.

## The builder

Anything more than a sentence starts with `Yo::say()` and ends with `->send()`. Nothing is sent
until `send()` is called.

```php
Yo::say('Import finished')
    ->body('412 entries in, 3 skipped. The skipped three had no title.')
    ->success()
    ->action('Review the log', url: '/admin/transport/logs/88')
    ->action('Run it again', action: 'transport/imports/run', params: ['id' => 88], key: 'rerun')
    ->to('group:editors')
    ->dedupe('transport:import')
    ->context(['importId' => 88])
    ->send();
```

| Method | What it does |
| --- | --- |
| `->body(string)` | A second line. Plain text; markup is escaped. |
| `->success()` `->notice()` `->tip()` `->warning()` `->error()` | Set the type. |
| `->type(string)` | Set any registered type, including one another plugin added. |
| `->from(string)` | Name the sending plugin. Worked out for you when you don't. |
| `->action(...)` | Add a button. See below. |
| `->to(mixed)` | A user element, a user id, `group:handle`, or `everyone`. |
| `->everyone()` | Everybody with control panel access. |
| `->cp()` `->site()` `->channels(array)` | Where it goes. Default: the control panel. |
| `->sticky(bool)` | Whether it waits to be dismissed. Default: whatever the type says. |
| `->ttl(int)` | Seconds before it stops being offered. `0` means never. |
| `->priority(int)` | Higher sorts first. |
| `->dedupe(string)` | A key. A later message with the same key replaces this one. |
| `->context(array)` | Anything you want handed back to yourself on the receipt events. |

## Buttons

```php
->action(
    label: 'Try again',
    url: null,                          // an ordinary link, or…
    action: 'shipper/sync/retry',       // …a Craft action path, posted
    params: ['shipmentId' => 41],
    key: 'retry',                       // what you get back on the receipt
    primary: true,                      // the emphasised one; at most one per message
    dismisses: true,                    // whether pressing it closes the message
)
```

A button is worth having whenever the message names something the person could do about it, which
is most error messages and about half the useful ones. Two is a sensible maximum.

## Who gets it

```php
Yo::say('Only you')->send();                       // whoever is making this request
Yo::say('One person')->to($user)->send();
Yo::say('A team')->to('group:editors')->send();
Yo::say('Everybody')->everyone()->send();
```

A message for the current request rides in the session and costs nothing. Anything else is written
down, and a message to a group or to everybody is **one row** — the receipts appear as people
actually read it, not four hundred rows the moment it is sent.

### From a queue job or the console

There is no current user in a console run, so a message addressed at one reaches nobody. Yo says so
in the log rather than pretending. Address it:

```php
Yo::say('Nightly import finished')->to($job->userId)->send();
```

## Not stacking up

A plugin that fires on every save will fill somebody's screen. Give it a dedupe key and the newest
message replaces the last one instead:

```php
Yo::say("Reindexing: $done of $total")->dedupe('search:reindex')->send();
```

## Learning what happened

```php
use justinholtweb\yo\services\Messages;
use justinholtweb\yo\events\DeliveryEvent;
use yii\base\Event;

Event::on(Messages::class, Messages::EVENT_MESSAGE_ACTIONED, function(DeliveryEvent $event) {
    if ($event->actionKey !== 'retry') {
        return;
    }

    MyPlugin::getInstance()->imports->retry($event->message->context['importId']);
});
```

`EVENT_MESSAGE_SHOWN`, `EVENT_MESSAGE_READ` and `EVENT_MESSAGE_DISMISSED` fire the same way. Every
one carries the message, so your own `context` comes back to you. See [The API](api) for the rest.

## On your front end

Switch the front-end channel on in the settings, then send messages that ask for it:

```php
Yo::say('Thanks — we have your booking.')->site()->send();
```

Put the container wherever the messages should appear:

```twig
{{ craft.yo.init() }}
```

That registers the behaviour and outputs a container. It is safe to call twice; the second call
outputs nothing.

### Styling it

There is no stylesheet. The markup is plain, the class names are stable, and they are yours:

```
.yo-message
.yo-message--success   .yo-message--notice   .yo-message--tip
.yo-message--warning   .yo-message--error
.yo-message__title
.yo-message__text
.yo-message__actions
.yo-message__action    .yo-message__action--primary
.yo-message__dismiss
```

Change `yo` to something else in the settings if it collides with your own naming.

### Or write the markup yourself

Drop a `yo/_messages.twig` into your own templates directory and it wins outright — not a partial
override, the whole list:

```twig
{% for message in messages %}
    <aside class="alert alert--{{ message.type }}" data-yo-message data-yo-uid="{{ message.uid }}">
        <h3>{{ message.title }}</h3>
        {% if message.body %}<p>{{ message.body }}</p>{% endif %}
    </aside>
{% endfor %}
```

You get `messages` and `prefix`. Keep `data-yo-message` and `data-yo-uid` on each one if you want
auto-dismissal to keep working.

### Or take the data and do your own thing

```twig
{% set messages = craft.yo.messages() %}
```

Asking for the list *is* the delivery — they are marked as shown. Pass `false` to peek without
taking them.

### Bundling the script yourself

If all of your JavaScript ships from a CDN or your own bundle, take Yo's front-end script with it
instead of letting `craft.yo.init()` add its own `<script>` tags.

1. Turn off **Load the front-end script for me** (`siteInjectRuntime`). `craft.yo.init()` then
   outputs the container and nothing else — no script tag, no inline `window.YoSiteConfig`.
2. Copy `yo-site.js` and `yo-site.mjs` from `vendor/justinholtweb/craft-yo/src/web/assets/dist/`
   into your source, side by side.
3. Decide where DataStar comes from — see below.
4. Write the configuration into the page, anywhere:

   ```twig
   <body {{ craft.yo.runtimeConfig() }}>
   ```

5. Start it:

   ```js
   import YoSite from './vendor/yo/yo-site.mjs';   // ES-module bundlers (Vite, Rollup, esbuild, webpack)
   // const YoSite = require('./vendor/yo/yo-site.js');   // CommonJS bundlers
   // or a plain <script src="…/yo-site.js">, then window.YoSite

   YoSite.start();
   ```

`YoSite.start()` with no arguments reads `data-yo-site-config`, so the class prefix and the
auto-dismiss delay still follow Yo's settings. Anything you pass — `YoSite.start({ autoDismissAfter: 3 })`
— wins over the page. `runtimeConfig()` renders nothing while the front-end channel is off.

The attribute carries nothing about the visitor, so the page stays cacheable — which is why the CSRF
token is not in it. The script fetches the token from Craft's own `users/session-info` once, and
only on a page with a container on it, before it posts anything.

`window.YoSite` and `window.YoSiteConfig` are set either way; the message templates' click handlers
read the token from `window.YoSiteConfig`. If a page ends up with both Yo's copy and yours, the
first one loaded wins and the second hands back the same instance.

**Re-copy the files when you update Yo.** A bundled copy is yours; Composer will not touch it.

#### DataStar

`yo-site.js` does not use DataStar itself — the container and the messages carry DataStar
attributes, and DataStar on the page does the polling and the clicks. So the script neither
imports DataStar nor needs to be handed it. What matters is that exactly **one** DataStar runs on
the page and that the markup is written in its dialect:

- **Your site has no DataStar of its own.** Copy Yo's `datastar.js` as well and import it before
  `yo-site.mjs` (`import './vendor/yo/datastar.js';`). Leave **DataStar alias** at `yo-ds` — Yo's
  copy is aliased to `data-yo-ds-*` and ignores every other `data-*` attribute on your pages.
- **Your site already runs DataStar.** Do not import Yo's copy: two copies on one page both answer
  every `datastar-fetch` event, so each applies the other's server responses. Set **DataStar alias**
  to whatever yours reads — empty for a stock build (`data-on:click`), or your build's alias
  (`star` for `data-star-on:click`) — and Yo writes its markup for your DataStar instead.
  It must be **DataStar 1.0** (Yo is built against 1.0.1): the markup uses 1.0's `data-init`,
  `data-on:click`, `data-on-interval__duration` and `@get`/`@post` with `headers`, and the server
  answers with 1.0's `datastar-patch-elements` / `datastar-patch-signals` events. Release
  candidates and 0.x use different names and will silently do nothing.

If you override `yo/_messages.twig`, write DataStar attributes as `{{ ds }}on:click`, not
`data-yo-ds-on:click` — `ds` is the prefix for whichever DataStar the page runs.
