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

## Email

**Email channel** — off by default. On, messages can be emailed to the people they are addressed
to: a group's members, one user, or — for `everyone` — everyone who can reach the control panel.
Only active accounts with an address are emailed. Never a list typed into settings: the audience
decides, exactly as it does for the panel.

**Email these types** — the routing table. A message whose type is ticked goes to email *if* it
named no channels of its own and it is addressed beyond the current request. A message to whoever
is making the request is on their screen already, so "Entry saved" — and every adopted Craft notice
— never lands in an inbox. **All** includes types a plugin registers later. A plugin can always ask
for email by name, routed or not: `->channels(['cp', 'email'])`.

**Send** — *each message as it is sent* (queued, one email per person) or *a digest*: one email per
person per period listing everything that waited for them.

**How often / Day of the week / Hour** — the digest schedule, in the system time zone. The digest
goes at the first run on or after that hour, so a missed hour still sends later the same day.

The schedule is run by:

```sh
# Every 15 minutes is plenty; it sends once per period whatever the frequency.
*/15 * * * * php /path/to/craft yo/digest/send
```

`php craft yo/digest/status` shows the last run, the last send, what is waiting and when the next
one is due. `--force` sends now regardless of the schedule.

**Check from web requests too** — for sites with no cron job. At most every five minutes, the end
of a web request looks at the schedule and, when the digest is due, queues it. It never runs on
Craft's garbage collection, which fires on a dice roll.

Two runs racing for the same period cannot both send: the first to move the marker in `yo_digests`
wins. If the mail server refuses every digest, the period is handed back and the next run tries
again; one person's failure keeps their items for the next digest without holding up anybody else.

**Send a test email** — to you, using the *saved* settings. In digest mode it shows what your next
digest would say (or a stand-in) and never counts as the real one.

## Slack, Teams or a webhook

**Webhook channel** — off by default, and it does nothing until a URL is saved.

**Webhook URL** — a Slack or Microsoft Teams incoming-webhook URL, or your own endpoint. Use an
environment variable (`$YO_WEBHOOK_URL`): the URL *is* the credential. Only an admin can set it;
nothing a plugin sends, and nothing in a request, can point it anywhere else.

Every post goes through the same SSRF guard the rest of the family uses: `http`/`https` only, no
credentials in the URL, every address the host resolves to must be public, the connection is pinned
to those addresses so DNS cannot be rebound between the check and the connect, and redirects are
never followed. A LAN-only receiver (a self-hosted Mattermost) can be allowed from
`config/yo.php` with `'allowPrivateWebhookHosts' => true`; the other rules still apply.

**Format** — Slack (Block Kit), Microsoft Teams (an Adaptive Card), or plain JSON:

```json
{"event":"yo.message","uid":"…","type":"error","plugin":"transport","site":"My site",
 "title":"Import failed","body":"…","url":"https://…","links":[{"label":"Open the log","url":"https://…"}],
 "at":"2026-10-09T12:00:00+00:00"}
```

**Signing secret** — optional, `$ENV` supported. When set, each post carries `X-Yo-Timestamp` and
`X-Yo-Signature: sha256=<hex>`, an HMAC-SHA256 of `timestamp.body`. Verify it on your receiver and
reject old timestamps. Slack and Teams ignore it.

**Post these types** — the same routing rule as email. Each routed message is posted **once**,
whoever it was addressed to — so everybody who can read that channel sees it. Route accordingly.

Posts are queued. A network error or a non-2xx is retried twice; a URL the guard refuses is logged
and not retried. Link buttons on a message become links in the post; buttons that POST a Craft
action cannot work from a chat message, so they are left out.

**Send a test post** — a sample to the *saved* URL. Save first.

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

Two tables, and two more for the email digest.

`yo_messages` holds only messages that outlive the request — anything addressed at somebody other
than the person who triggered it. A message for the current request rides in the session and never
costs a write, which is why a plugin can call Yo on every save without anyone regretting it.

`yo_deliveries` holds one row per person per message per channel, created when something first
*happens* to that person's copy. A message to four hundred people is one row until somebody reads
it.

`yo_email_queue` holds messages waiting for somebody's next digest — one row per person, deleted
when their digest is accepted by the mailer (or when their account is). Rows still waiting after 35
days, because the digest was switched off with something in it, go out with garbage collection.
`yo_digests` is the digest's one-row "last sent" marker.

Nothing about a visitor is stored for the front-end channel beyond what Craft already knows: an
anonymous visitor's messages live in their session and nowhere else.
