# Yo — Craft CMS 5 plugin

## Project overview

A flash-message bus other plugins talk to. Distributed as `justinholtweb/craft-yo`.
**Free, single edition** — it is infrastructure, and a price would be a tax on the thing that makes
it valuable (other plugins adopting it).

Craft's `setNotice()` is one string, for one person, on the next page load, with no type beyond
notice and error, no buttons, no way to reach anybody else, and no way for the sender to learn
whether it was seen. Yo answers all of those once.

Themed as an 80s sitcom alien — an **original character**, not a licensed one. The mark is
`src/icon.jpg` vectorised: cool-white **#EAF6FF** line art on a steel-blue **#33608A** tile, with
**#7AA2C8** as the accent that does the work on dark ground. The nearest neighbour in the family is
`craft-comparer`'s **#2F6F9E**, which is enough bluer and darker to tell apart at icon size.

The drawing has no shout arcs, so the teal that carried them is gone from every surface.

## Tech stack

- PHP 8.2+, Craft CMS 5.3+, Yii2, Twig
- **DataStar 1.0.1**, vendored at `src/web/assets/dist/datastar.js` (copied from `craft-smoke`),
  registered as an ES module, and **aliased to `data-yo-ds-*`** — see below.

### DataStar is aliased, and must stay aliased

Yo loads DataStar on every CP page, and an unaliased DataStar treats *every* `data-{plugin}`
attribute in the document as its own. Craft's element index tables render `<td data-attr="section">`,
which DataStar's `attr` plugin evaluated as JavaScript — `ReferenceError: section is not defined`
on every element index, for every site with Yo installed (and any other plugin's `data-attr`,
`data-show`, `data-text`… markup was fair game too).

The vendored file is patched at its two alias helpers — the same hooks DataStar's official aliased
builds use: `W=e=>\`data-yo-ds-${e}\`` and `tt=e=>e.startsWith("yo-ds-")?e.slice(6):null`. So Yo's
templates write `data-yo-ds-on:click`, `data-yo-ds-show`, `data-yo-ds-signals`… and DataStar ignores
everything else on the page. **Re-apply the patch if DataStar is ever upgraded.**

DataStar 1.0 syntax: the event is the *key* — `data-yo-ds-on:click`, never `on-click` (that is an
unregistered plugin called `on-click`, silently ignored). `data-on-load` is gone; it is `data-init`.
Yo shipped 5.0.0 with `data-on-click` and `data-on-load`, so none of its DataStar click handlers
ever ran; buttons with `data-yo-post` worked only because `yo-cp.js` wires those itself.
- No build step. The CSS and JS in `dist/` are the sources.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\yo`
- Package: `justinholtweb/craft-yo`
- Handle: `yo`
- Asset alias: `@justinholtweb/yo/web/assets/dist` (one published directory, two bundles)

### The load-bearing decisions

**Two lanes, one API.** `Message::getIsStored()` decides: a message for the *current* request rides
in the session and never costs a write; anything addressed at somebody else is written down. There
is no setting for this and there must not be — getting it wrong either loses messages or writes a
row every time an entry saves.

**Fan-out on read.** A message to `everyone` or to a group is **one row** in `yo_messages` and no
delivery rows at all. `yo_deliveries` rows appear when something first happens to a person's copy.
Writing 400 rows to announce something to a 400-user site is the thing this avoids.

**The server renders every message, always.** Both the initial page render and every SSE patch go
through `services\Panel::render()`. A third-party type with its own colour and icon has to look
exactly as right as a built-in one, and it cannot if the browser owns the template.

**Poll, don't hold a stream open.** DataStar's natural shape is a long-lived `text/event-stream`.
Under PHP-FPM that is one worker per open control panel tab. `data-yo-ds-on-interval__duration.Ns`
polling plus single-shot SSE responses for every interaction gets the same behaviour without the
worker cost. Documented in `docs/configuration.md#why-it-polls` because it looks like a mistake
otherwise.

**Adoption is the reason to install it.** `services\Adopt` drains Craft's `cp-notification-*`
flashes before `_layouts/components/notifications.twig` reads them; `yo-cp.js` wraps
`Craft.cp.displayNotification` for the Ajax ones that never touch the session. Both halves are
needed — neither covers the other's case.

### Data model

Two tables, both hard deletes.

- `{{%yo_messages}}` — only messages that outlive the request. `sticky` is deliberately
  **nullable**: null means "whatever the type says", including after somebody edits the type.
  `channels` stores the *resolved* channel list from send time, so reading never re-runs routing.
- `{{%yo_deliveries}}` — one row per (message, user, channel), unique on that triple. State only
  moves forward (`queued` → `shown` → `read` → `dismissed`); a poll that re-shows something already
  read must not walk it back.

### Services

| Service | What it owns |
| --- | --- |
| `Messages` | The bus. `send()`, `forRecipient()`, the receipts, expiry. Every event lives here. |
| `Types` | The message-type registry, memoized, with `EVENT_REGISTER_MESSAGE_TYPES` |
| `Channels` | The channel registry, memoized, with `EVENT_REGISTER_CHANNELS` |
| `Panel` | The control panel panel: config, position preference, payload, render |
| `Frontend` | The front-end channel: payload, render (with site override), container |
| `Adopt` | Craft's own flashes |

`getMessageByUid()` is the authorisation boundary — every receipt goes through it, and it only
returns a message the asking user could already see. Nothing else checks.

## Traps found while building this

- **`Component::createComponent()` demands `craft\base\ComponentInterface`.** A plugin's own
  interface cannot be used with it. `Craft::createObject()` is the same container without the extra
  demand — that is why `services\Channels::resolve()` uses it directly.
- **`User::getPreferences()` returns `[]` for a user who cannot access the control panel.** So a
  panel position stored for a user without `accessCp` reads back as the default, silently. The
  integration checks grant the fixture group `accesscp` for exactly this reason, and a check
  without it passes for the wrong reason.
- **`Request::getIsConsoleRequest()` answers about the *application*, not the request.** Using it
  to guard `getUser()` made every console-side read return a default. The thing a console run
  lacks is the **session** (`craft\console\Application::getSession()` throws by design); the user
  component exists and can be given an identity with `setIdentity()`. Guard the session, not the
  user.
- **`display: grid` beats the `hidden` attribute.** `[hidden]` is only a UA-stylesheet
  `display: none`. The move menu was open on every page load until `.yo-move[hidden]` was added.
- **DataStar posts the page's signals as the body and takes no other passengers.** There is no
  "and also send these fields" option, so message uids ride in the **query string** and the
  controller reads `getRequiredParam()`, not `getRequiredBodyParam()`.
- **`requireCpRequest()` reads the CP trigger in the URL path.** An action URL generated inside the
  control panel is `/admin/actions/…`; posting to the bare `/actions/…` form is a 400. The runtime
  checks learned this the hard way and now build every CP endpoint from `$CPA`.
- **A class whose parent is autoloaded cannot be early-bound**, so a test double declared after
  `exit()` in a single-file check script is never declared at all — "Class not found" with the
  class plainly visible in the file. Declare doubles above the checks.
- **`View::registerHtml($html, View::POS_END)`** is the right way to put the panel at the end of
  the body. Echoing from `EVENT_END_BODY` also works but only for templates that call `endBody()`.
- **The panel must not live inside `#content`.** The first ancestor with `overflow: hidden` clips
  it, and on a Craft entry screen that is most of them.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container.

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-yo/tests/integration/checks.php    # 95 checks
ddev exec bash -c 'find /var/www/craft-yo/src -name "*.php" -print0 | xargs -0 -n1 php -l'

cd ~/Sites/craft-yo
./tests/runtime/checks.sh                                       # 27 checks, over real HTTP
```

The split is not arbitrary. **A console run has no session**, so the session lane — which is the
common path, the one a plugin gets by calling `Yo::success()` and nothing else — cannot be reached
from `checks.php` at all. `checks.sh` signs in with `craft users/impersonate` (no password is typed
anywhere), drives the real endpoints with a cookie jar, and asserts the DataStar wire format line
by line, because that format is a contract between the controller and a vendored client and a
mismatch produces a panel that silently stops updating.

Both are idempotent and self-cleaning. `checks.php` makes its own user, group and second user,
tags every message it sends with `yo-checks` in the context, and deletes all of it in the
`Cleanup` section.

**After editing anything in `src/web/assets/dist/`, run
`ddev exec php craft clear-caches/cp-resources`** or Craft keeps serving the previously published
copy.

## The icon

`src/icon.svg` and `promos/assets/{icon,watermark}.svg` are traced from `src/icon.jpg` — one
even-odd path each, because every stroke in the drawing is a closed outline and the counters are
holes in the same path. There is no potrace or ImageMagick on this Mac; the tracer that made them
is not checked in, so **re-running it means writing it again** — treat the SVGs as the source and
edit those.

`src/icon-mask.svg` is *not* a reduction of that trace. The drawing is ~60 strokes of fur, and at
the 18px the control panel nav renders a mask icon at, all of it welds into a grey lozenge. The
mask is drawn to the trace's proportions and keeps only what survives that size: ears, brow, snout,
two nostrils, two pupils.

## Screenshots

Seed the demo messages first, capture, then clear them — the harness is shared, and five
permanent messages addressed at everybody turn up in every other plugin's screenshots:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-yo/tests/fixtures/demo.php seed
cd ~/Sites/plugin-shots && node capture.mjs /tmp/yoshots spec.json
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-yo/tests/fixtures/demo.php clear
```

The senders in the fixture are real plugins from the family on purpose: a screenshot whose
messages all come from `yo` proves the opposite of the pitch.

The panel is `position: fixed`, and that harness hides every fixed `body > div` that is not CP
chrome. Its spec already accepted a `keep` key but never implemented it — that was added during
this build, so `"keep": ["#yo-panel"]` now works. Without it the panel is invisible in every shot.
`#yo-panel` was also added to the harness's own noise list, so *other* plugins' screenshots are
not photobombed by it; `keep` overrides that for Yo's own shots.

## Plugin Store promos

`promos/` renders the seven 1920×1080 marketing images:

```sh
./promos/build.sh          # all slides
./promos/build.sh "2 5"    # just those two
```

`assets/watermark.svg` is the alien's head and tufts with the tile stripped — the tile is a solid
rounded square and at watermark scale it reads as a grey box across the slide.

## Coding conventions

- `Craft::t('yo', '…')` for user-facing strings; `src/translations/en/yo.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — the settings screen's "try it" buttons post over
  DataStar for this reason
- Never mark a plugin setting `required`
- Nothing in the front-end channel may ship a colour, a border or a stylesheet
