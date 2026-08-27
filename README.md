<!-- statamic:hide -->
# Sidecar

> Edit your static site generator's markdown from the Statamic Control Panel.
<!-- /statamic:hide -->

Sidecar opens an SSG's own files in Statamic — tree, publish forms, Live Preview — and writes them back in that SSG's format. No entries, no Stache copies, no conversion. Front matter, folders, and `navigation.php` stay native.

> **Experimental.** APIs and on-disk behavior may change.

## Features

- Sources appear under **Content → Sidecar**
- Nested tree (when the driver supports it) and a flat listing
- Publish forms with per-source blueprints you can edit in the CP
- Live Preview against the SSG's own renderer
- First-party drivers for [LaraDocs](https://github.com/petebishwhip/laradocs) and [Jigsaw](https://jigsaw.tighten.com)
- Install command that detects compatible packages and writes the source config
- Third-party drivers via `Sidecar::extend()` / `Sidecar::pair()`

## Installation

```
composer require statamic/sidecar
php please install:sidecar
```

The command looks for supported SSG packages, lets you pick a driver, and appends a source to `config/sidecar.php`. You can also publish and edit that file yourself:

```
php artisan vendor:publish --tag=sidecar-config
```

## Configuration

Each source is a directory plus a driver:

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

| Key | Purpose |
| --- | --- |
| `driver` | `laradocs`, `jigsaw`, or a handle you registered |
| `directory` | Absolute path to the markdown |
| `title` | CP nav label (defaults to the driver's title) |
| `read_only` | Browse and preview only |
| `blueprint` | Optional override handle; otherwise the driver's default, customizable in the CP |
| `preview_url` | Live Preview endpoint override |

Multiple sources are fine. Each gets its own nav item.

## Drivers

### LaraDocs

Nesting is real folders (`guide/routing.md`, sections as `guide/_index.md`). Sibling order is `order` front matter. Saving the tree moves files and rewrites `order`.

`group:` on a **root** page or section index is a tab/sidebar **bucket**, not the section's own name. Don't set `group: Recipes` on `recipes/_index.md` titled Recipes — you'll get Recipes → Recipes → Recipes. Leave `group` off the index, or use a parent name that can hold more than one section (`Core`, `Docs`). Nested pages may still use `group:` for the page eyebrow; it doesn't nest them.

If `heroicons` is installed, the Icon field is a picker and stores the kebab-case name LaraDocs expects (`icon: rocket`).

### Jigsaw

Docs stay flat on disk. Hierarchy lives in `navigation.php`. Saving the tree rewrites that file and never relocates markdown.

```php
'docs' => [
    'driver' => 'jigsaw',
    'directory' => base_path('source/docs'),
    'navigation' => base_path('navigation.php'),
    'url_prefix' => 'docs',
],
```

## Custom drivers

```php
use Statamic\Sidecar\Facades\Sidecar;

Sidecar::extend('hugo', function ($app, array $config, string $handle) {
    return new App\Sidecar\HugoDriver($config, $handle);
});

Sidecar::pair('some/hugo-package', 'hugo');
```

`pair()` is what `install:sidecar` uses to detect the package. Implement `Statamic\Sidecar\Contracts\Driver` or extend `Statamic\Sidecar\Drivers\Driver`. The driver owns paths, tree projection, and how a rearrange is persisted. Sidecar never writes a tree of its own.

## Requirements

- PHP 8.2+
- Statamic 6
