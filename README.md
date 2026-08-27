<!-- statamic:hide -->
# Sidecar

> Edit your static site generator's markdown from the Statamic Control Panel.
<!-- /statamic:hide -->

You've got a static site generator. LaraDocs, Jigsaw, something in that family. Markdown files, front matter, maybe a `navigation.php`. It works.

Then you want to edit those docs in the Control Panel — tree, publish forms, Live Preview, the whole Statamic experience — without turning them into Statamic entries first.

That's Sidecar.

It opens the SSG's own files, lets you edit them, and writes them back in that SSG's format. No collections. No Stache copies. No "import your markdown into Statamic and hope for the best." Front matter stays front matter. Folders stay folders. `navigation.php` stays `navigation.php`.

Sidecar rides alongside your SSG. It doesn't replace it.

> **Experimental.** APIs and on-disk behavior may change.

## How it works

A **source** is a directory of markdown plus a **driver** that knows how that SSG thinks.

The driver owns everything that would otherwise be Statamic's job: where files live, how the tree is nested, what happens when you drag a page, how Live Preview renders. Sidecar never stores a tree of its own. It projects one from the SSG and writes rearranges back the same way.

You'll find your sources under **Content → Sidecar**. Each source gets its own nav item, its own blueprint (editable in the CP), and Live Preview against the SSG's own renderer.

First-party drivers ship for [LaraDocs](https://github.com/petebishwhip/laradocs) and [Jigsaw](https://jigsaw.tighten.com). Want Hugo? Write a driver. More on that below.

## Installation

```
composer require statamic/sidecar
php please install:sidecar
```

The install command sniffs for supported SSG packages, asks you which driver to use, and appends a source to `config/sidecar.php`. You can also publish the config yourself and do it by hand:

```
php artisan vendor:publish --tag=sidecar-config
```

## Configuration

Each source is a handle, a driver, and a directory:

```php
// config/sidecar.php

'sources' => [
    'docs' => [
        'driver' => 'laradocs',
        'directory' => base_path('docs'),
        // 'title' => 'Documentation',
        // 'read_only' => false,
        // 'blueprint' => 'sidecar.docs',
        // 'preview_url' => '/!/sidecar/laradocs/live-preview',
    ],
],
```

| Key | What it does |
| --- | --- |
| `driver` | `laradocs`, `jigsaw`, or a handle you registered |
| `directory` | Absolute path to the markdown |
| `title` | CP nav label (defaults to the driver's title) |
| `read_only` | Browse and preview only — no saving |
| `blueprint` | Optional override handle; otherwise the driver's default, which you can customize in the CP |
| `preview_url` | Live Preview endpoint override |

Multiple sources are fine. Docs in one folder, a changelog in another, whatever. Each gets its own nav item.

## Drivers

This is the part that actually matters. Drivers teach Sidecar how *this* SSG stores structure.

### LaraDocs

Nesting is real folders. `guide/routing.md` lives under `guide/`. A section is `guide/_index.md`. Sibling order lives in `order` front matter. Drag the tree, Sidecar moves files and rewrites `order`. That's it.

**`group:` is a sidebar bucket, not the section name.** Set it on a root page or a section index and LaraDocs treats it as a tab/sidebar grouping. Don't put `group: Recipes` on `recipes/_index.md` titled Recipes — you'll get Recipes → Recipes → Recipes. Leave `group` off the index, or use a parent name that can hold more than one section (`Core`, `Docs`). Nested pages can still use `group:` for the page eyebrow. That doesn't nest them.

If `heroicons` is installed, the Icon field is a picker and stores the kebab-case name LaraDocs expects (`icon: rocket`).

### Jigsaw

Docs stay flat on disk. The hierarchy lives in `navigation.php`. Saving the tree rewrites that file and never relocates markdown — Jigsaw doesn't want your files moving around.

```php
'docs' => [
    'driver' => 'jigsaw',
    'directory' => base_path('source/docs'),
    'navigation' => base_path('navigation.php'),
    'url_prefix' => 'docs',
],
```

## Custom drivers

Got an SSG that isn't LaraDocs or Jigsaw? Teach Sidecar how it works.

```php
use Statamic\Sidecar\Facades\Sidecar;

Sidecar::extend('hugo', function ($app, array $config, string $handle) {
    return new App\Sidecar\HugoDriver($config, $handle);
});

Sidecar::pair('some/hugo-package', 'hugo');
```

`extend()` registers the driver. `pair()` tells `install:sidecar` which Composer package it belongs to, so the command can detect it.

Implement `Statamic\Sidecar\Contracts\Driver` or extend `Statamic\Sidecar\Drivers\Driver`. The driver owns paths, tree projection, and how a rearrange gets persisted. Sidecar never writes a tree of its own — if it isn't in the SSG's format, it doesn't exist.

## Requirements

- PHP 8.2+
- Statamic 6
