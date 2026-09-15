---
title: FAQ
slug: faq
order: 60
summary: What it costs, what it stores, why it polls, and whether your plugin has to depend on it.
---

## Is Yo free?

Yes. One edition, no paid tier, no trial. It is infrastructure — it is worth more the more plugins
use it, and a price would be a tax on exactly the thing that makes it useful.

## Does every plugin have to be rewritten to use it?

No. **Adopt Craft's own notices** is on by default, and every plugin that calls Craft's
`setNotice()`, `setSuccess()` or `setError()` shows up in the panel with no change to any of them.
Writing to Yo directly buys you types, buttons, recipients and receipts — but the floor is zero
work.

## Should my plugin require it?

Put it in `suggest`, not `require`, and guard the call site:

```php
if (class_exists(Yo::class) && Yo::isReady()) {
    Yo::success('Saved.');
}
```

Your plugin then gains a panel on the sites that have one and loses nothing on the sites that
don't.

## Does it replace Craft's notices?

It adopts them, which is different. Craft still sets them; Yo takes them off the session before the
layout renders and shows them in the panel instead. Turn adoption off and Craft's own toasts come
straight back.

## What does it store?

Two tables. `yo_messages` holds only messages addressed at somebody other than the person who
triggered them — everything else rides in the session and never costs a write. `yo_deliveries`
holds one row per person per message, created when something first happens to that copy, so a
message to four hundred people is one row until somebody reads it.

Nothing about an anonymous front-end visitor is stored at all: their messages live in their session
and nowhere else.

## Why does it poll instead of holding a stream open?

Because an open Server-Sent Events connection occupies a PHP-FPM worker for as long as the tab is
open. Five editors with three tabs each would exhaust a default pool without anybody doing anything
wrong.

Everything else about the panel is server-sent — dismissing, acting on and clearing all post and
get back rendered HTML that the client morphs into place. It is the connection that is short, not
the technique.

## Why DataStar and not Vue, or nothing?

Because the shape of a message is not fixed. A plugin can register its own type, with its own
colour and icon, and add buttons Yo has never seen. A client-side template would have to know about
all of that, and would drift the moment somebody added a type. Sending the rendered HTML means a
third-party type looks exactly as right as one of Yo's own.

DataStar is vendored — 34 KB, no CDN, no build step, no framework in your control panel.

## Can I put Yo's messages somewhere else — Slack, e-mail, a webhook?

Yes, and you do not have to touch any of the plugins already sending them. Write a channel, register
it from `Channels::EVENT_REGISTER_CHANNELS`, and route to it from `EVENT_DEFINE_DELIVERY`. See
[The API](api) for a complete Slack channel in about twenty lines.

## Can I stop one noisy plugin without uninstalling it?

Yes — `EVENT_BEFORE_SEND` can suppress any message from any sender, including one you did not
write. That seam exists precisely so a site with thirty plugins has one place to turn something
down rather than thirty settings screens.

## Can two people see different messages?

That is the normal case. Messages are addressed. A dismissal is a fact about one person's copy, so
one editor closing a broadcast leaves it on everybody else's screen.

## Where the panel sits — is that per person or per site?

Both, in the right order. The site sets where it starts; each person can drag it somewhere else,
and that is stored as their own preference. Moving it never moves it for anybody else.

## Does it work on the front end without JavaScript?

The messages render server-side, so they are in the HTML and readable. Dismissing them, and
anything arriving after the page did, needs JavaScript.

## Does it support GraphQL?

Not yet. Messages are per-person and per-session, which is an awkward fit for a cached GraphQL
response, and no one has needed it. If you do, say so.

## Who is the alien?

An original character, drawn for this plugin — a furry, snouted, wisecracking houseguest from a
faraway planet who will not stop shouting from the corner of your control panel. Any resemblance to
a certain 1980s sitcom is affectionate and entirely the point.
