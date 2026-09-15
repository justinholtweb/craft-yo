---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and the one setting that makes it useful before you write any code.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

Nothing else. No queue, no cache driver, no external service, no build step.

## Install

```sh
composer require justinholtweb/craft-yo
php craft plugin/install yo
```

Or find **Yo** in the Craft Plugin Store and install it from there.

Installing creates two tables and nothing else. It does not change any existing plugin's
behaviour, and it does not send anything.

## It is useful before you write anything

Open **Settings → Plugins → Yo**. **Adopt Craft's own notices** is on by default, and it is the
setting that earns the install:

> Every plugin that calls `setNotice()` or `setError()` shows up in the panel instead of Craft's
> toast — with no change to any of them.

Craft keeps control panel notifications in three session flashes. Yo drains them before the layout
renders them, and turns each into a message of its own. The plugin that sent it never finds out.

Craft also returns notices in Ajax response bodies, which never touch the session. Yo catches those
on the browser side, by wrapping the one function every one of them goes through.

So: install it, load a page that saves something, and the panel is already doing work.

## The panel

The floating panel appears at the bottom right of every control panel page. Drag it by its bar and
it snaps to the nearest of six anchors — three across the top, three across the bottom — and stays
there for you, and only for you. The move button in its bar does the same thing without a mouse
gesture.

Collapse it with the chevron. Turn it off entirely with **Show the panel**, and the dashboard
widget and the front-end channel keep working.

## Try it

The settings screen has five buttons at the bottom, one per type. They send you a message. If one
appears in the panel, everything on this page is working.

## Front end

The front-end channel is **off** until you switch it on, and even then only messages that ask for
it by name go there. See [Usage](usage) for the one line of Twig that puts messages on your site,
and [Configuration](configuration) for what the switches do.
