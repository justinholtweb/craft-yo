# Release Notes for Yo

## 5.0.0 - 2026-08-27

Initial release.

### Added

- A message bus with one API for every plugin on the site: `Yo::success()`, `Yo::error()` and a
  fluent builder for everything else.
- Five message types — success, notice, tip, warning, error — each with its own colour, icon,
  stickiness and sort priority, and `Types::EVENT_REGISTER_MESSAGE_TYPES` for plugins that want
  their own.
- Three channels — the control panel, the front end and the Craft log — and
  `Channels::EVENT_REGISTER_CHANNELS` for plugins that want to add a fourth.
- Receipts: shown, read, dismissed and actioned, each an event carrying the sending plugin's own
  context back to it.
- Audiences: the current user, one user, a user group, or everybody. Broadcasts cost one row.
- A floating control panel panel, positionable to any of six anchors by dragging or from a menu,
  remembered per person.
- A dashboard widget listing what is waiting, without taking any of it.
- Front-end delivery with no stylesheet of its own: stable class names, an overridable template,
  and `{{ craft.yo.init() }}`.
- Adoption of Craft's own control panel notices, server-side and in the browser, so every plugin
  already installed gets Yo without a line of change.
- Dedupe keys, time to live, a per-person cap, and expiry swept with Craft's garbage collection.
- Console commands: `yo/say`, `yo/channels`, `yo/types`, `yo/purge`.
- Template hooks `yo.panel.header`, `yo.panel.footer` and `yo.message.meta`.
