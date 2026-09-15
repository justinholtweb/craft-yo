---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: The panel is missing, the message never arrived, or it arrived twice.
---

## The panel is not there

- **Are you signed in?** It renders for authenticated control panel users only.
- **Is "Show the panel" on?** Settings → Plugins → Yo.
- **Is "Keep him on screen when there is nothing to say" off?** Then it appears only when a message
  is waiting. Send yourself one from the Try it buttons.
- **Did you edit the plugin's CSS or JS?** Craft publishes plugin assets to a hashed directory and
  keeps serving the old copy. Run `php craft clear-caches/cp-resources`.

## A message never arrived

- **Was it addressed at the current user from a console run or a queue job?** There is no current
  user there, so it reached nobody. Yo writes a warning to the log saying exactly that. Address it:
  `->to($user)` or `->everyone()`.
- **Did something cancel it?** `EVENT_BEFORE_SEND` listeners can drop a message. `send()` returns
  `false` when one did.
- **Did it validate?** A message with no title is refused, and so is an audience that is not
  `everyone`, `current`, `user:<id>` or `group:<handle>`. The errors go to the log.
- **Is it a front-end message with the front-end channel off?** Then no channel wanted it, and
  `send()` returned `false`.
- **Was it past its time to live?** A message with a short `ttl` sent to somebody who did not look
  in time is gone. `EVENT_MESSAGE_EXPIRED` fires as it goes.

## The same message appears again and again

Give it a dedupe key. A later message with the same key replaces the earlier one rather than
stacking under it:

```php
Yo::say("Reindexing: $done of $total")->dedupe('search:reindex')->send();
```

Without one, a plugin that sends on every element save will send on every element save.

## Everything appears twice

Craft's own toast and Yo's panel are both showing it. That means adoption is off, or the flash was
set in a way Yo cannot drain — an Ajax response body on a page where Yo's JS did not load, for
instance. Check **Adopt Craft's own notices**, and check the browser console for an error in
`yo-cp.js`.

## The panel appears but never updates

The panel polls. If it draws on load and then goes quiet:

- **Is the poll interval `0`?** Then it is behaving correctly.
- **Look at the network tab** for `yo/stream/poll`. A 400 usually means the CSRF token has expired
  along with the session — reload the page. A 403 means the request lost its session cookie.
- **Look at the console** for a DataStar error. The client is vendored at
  `src/web/assets/dist/datastar.js` and registered as an ES module; a browser that will not load
  ES modules will not run the panel at all.

## A button does nothing

A button with a `url` is a link and behaves like one. A button with an `action` posts to that Craft
action path — check the network tab, and check the action requires the permissions the person
pressing it actually has.

Either way, Yo files the receipt separately from doing the work: `EVENT_MESSAGE_ACTIONED` fires
even if your own action fails, because the person did press the button.

## The front-end markup looks unstyled

It is. Yo ships no front-end stylesheet on purpose — see [Usage](usage) for the class names to
write against, or drop a `yo/_messages.twig` into your own templates and take the markup over.

## Messages are piling up in the table

`php craft yo/purge` drops everything past its time to live. Craft's garbage collection does the
same on its own schedule. If they are not expiring, check **Default time to live** — `0` means
never.

## A third-party channel is breaking things

It cannot break a send: a channel that throws is logged under the `yo` category and stepped over.
Find it with:

```sh
php craft yo/channels
```

Anything not in that list either failed to register or is not a channel.
