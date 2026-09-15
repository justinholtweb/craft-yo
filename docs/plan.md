# Yo — build notes

Not published. This file has no front matter, which is how the docs sync leaves it off the
marketing site.

## The idea

Every plugin in the family reaches for `Craft::$app->getSession()->setNotice()` and every one of
them hits the same four walls: one string, one person, one page load, no way to know it was seen.
Rather than each solving it privately, one plugin solves it once and the rest talk to that.

That makes Yo a plugin *for* plugins, which is a different product from a notifications plugin. The
things that matter are the seams, not the panel:

- a sender writes one line and gets something reasonable
- a sender that wants more gets recipients, buttons and receipts
- a *third party* can add a type, add a destination, or suppress somebody else's message
- a site with Yo installed and nothing else changed is already better off

The last one is the load-bearing feature. Adoption of Craft's own flashes is what makes the plugin
worth installing before anybody has written a line against it.

## Decisions worth keeping

**Two lanes, one API.** A message for the current request rides in the session; anything addressed
elsewhere is written down. There is no setting for this and there should not be: getting it wrong
either loses messages or writes a row every time an entry saves.

**Fan-out on read.** A broadcast is one row. Delivery rows appear when something happens to a
copy — shown, read, dismissed, actioned — not when the message is sent.

**Poll, don't hold a stream.** DataStar's natural shape is a long-lived SSE connection. Under
PHP-FPM that is a worker per open tab. Everything else is server-sent; only the connection is
short.

**Render on the server.** A third-party type with its own colour and icon has to look exactly as
right as a built-in one, and it cannot if the browser owns the template.

## Not built

- GraphQL. Per-person, per-session data in a cached response is an awkward fit.
- Digest e-mail. It belongs in a channel plugin, not here.
- Per-user mute rules in the CP. `EVENT_BEFORE_SEND` is the seam; a UI over it is a second plugin.
- A "message centre" element index. The dashboard widget covers the 90% case.
