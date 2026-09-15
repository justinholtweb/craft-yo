---
title: Configuration
slug: configuration
order: 40
summary: Every setting, what it costs, and the two that are off by default on purpose.
---

Settings live at **Settings → Plugins → Yo**. None of them is required — a fresh install can save
any one without the others being filled in.

## The panel

**Show the panel** — puts the floating panel on every control panel page. Off, messages still
arrive: the dashboard widget and the front-end channel keep working, there is just nothing
floating.

**Where it starts** — one of six anchors. Anyone can drag the panel somewhere else, and where they
drag it is stored as *their* preference. This setting is only the corner it starts in for somebody
who never has.

**Keep him on screen when there is nothing to say** — off, the panel appears only when a message is
waiting.

**Check for new messages every** — seconds between polls. `0` stops the panel polling, and messages
then appear on the next page load. See [why it polls](#why-it-polls) below.

**Fade a message after** — seconds a non-sticky message stays on screen. Sticky messages ignore it.
Hovering one cancels its countdown for good: a message you deliberately looked at should not vanish
the moment you look away.

## Other plugins

**Adopt Craft's own notices** — on by default, and the reason Yo is worth installing on a site
whose plugins have never heard of it. Craft keeps control panel notifications in three session
flashes; Yo drains them before the layout renders and turns each into a message. Notices returned
in Ajax response bodies never touch the session, so those are caught in the browser instead.

Turn it off and Craft's own toasts come back, and only plugins that call Yo directly appear in the
panel.

The screen also lists every registered message type and channel — including any another plugin
added. It is the fastest way to see what is actually installed.

## The front end

**Front-end channel** — off by default. Off, nothing a plugin sends can ever reach your public
templates, whatever it asks for.

**Put the container in for me** — off by default, and this one deserves an explanation. It splices
the container before `</body>` on every front-end page. Where messages sit on a page is a design
decision, and a plugin that silently injects a div into somebody's careful layout has made a
decision that was not its to make. `{{ craft.yo.init() }}` is one line and it goes where you want
it.

**Class prefix** — the class names on front-end markup. `yo` gives you `yo-message`,
`yo-message--error` and so on. Change it if it collides with your own naming.

## Housekeeping

**Default time to live** — seconds a stored message stays offerable. `0` means forever. A message
can override it with `->ttl()`. Expired messages go out with Craft's own garbage collection, or on
demand with `php craft yo/purge`.

Note that a null time to live on a stored message follows this setting *as it is now*, not as it
was when the message was sent. Lowering it retires old messages; raising it brings back ones that
had gone quiet but were never purged.

**Most messages one person can be holding** — past this, the oldest are dropped. A plugin in a loop
should not be able to fill somebody's screen.

**Write every message to the log** — switches the `log` channel on for everything, not just
messages that asked for it. Useful while working out which plugin is being noisy.

## Why it polls

The panel asks the server for new messages on an interval. It does not hold a Server-Sent Events
connection open, and that is deliberate: an open SSE connection occupies a PHP-FPM worker for as
long as the tab is open. A five-person editorial team with three tabs each would exhaust a default
pool without anybody doing anything wrong.

Everything else about the panel *is* server-sent — dismissing, acting on and clearing a message all
post and get back rendered HTML. It is the connection that is short, not the technique.

If ten seconds is more often than you need, raise it. If you would rather messages only appeared on
page loads, set it to `0`.

## What is stored

Two tables.

`yo_messages` holds only messages that outlive the request — anything addressed at somebody other
than the person who triggered it. A message for the current request rides in the session and never
costs a write, which is why a plugin can call Yo on every save without anyone regretting it.

`yo_deliveries` holds one row per person per message per channel, created when something first
*happens* to that person's copy. A message to four hundred people is one row until somebody reads
it.

Nothing about a visitor is stored for the front-end channel beyond what Craft already knows: an
anonymous visitor's messages live in their session and nowhere else.
