<?php

namespace Statamic\Sidecar\Drivers\VitePress;

use Statamic\Facades\File;
use Statamic\Support\Str;

/**
 * Reads and writes a VitePress `sidebar.json`. The file owns the docs
 * hierarchy — Sidecar's tree is a projection of it.
 *
 * Supports both the default-theme array form and the multi-sidebar object
 * form (`{"/guide/": [...], "/api/": [...]}`).
 */
class SidebarFile
{
    public static function load(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path) ?? '', true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function write(string $path, array $sidebar): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        File::put($path, static::export($sidebar));
    }

    public static function export(array $sidebar): string
    {
        return json_encode($sidebar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * The items array this source manages. Multi-sidebar objects are scoped
     * to `$key`; a missing key on an object form yields an empty list.
     */
    public static function items(array $sidebar, ?string $key = null): array
    {
        if ($sidebar === [] || array_is_list($sidebar)) {
            return $sidebar;
        }

        if ($key === null) {
            $key = array_key_first($sidebar);
        }

        $items = $sidebar[$key] ?? [];

        return is_array($items) ? $items : [];
    }

    /**
     * Write items back into the full sidebar structure, preserving sibling
     * keys when the file is a multi-sidebar object.
     */
    public static function setItems(array $sidebar, array $items, ?string $key = null): array
    {
        if ($key === null) {
            return $items;
        }

        if ($sidebar === [] || array_is_list($sidebar)) {
            return [$key => $items];
        }

        $sidebar[$key] = $items;

        return $sidebar;
    }

    public static function isMultiSidebar(array $sidebar): bool
    {
        return $sidebar !== [] && ! array_is_list($sidebar);
    }

    /**
     * Convert a sidebar link to a document path (no extension).
     * `/guide/routing` → `guide/routing`, `/guide/` → `guide/index`.
     */
    public static function pathFromLink(string $link, string $base = ''): ?string
    {
        if (static::isExternalUrl($link) || static::isDynamicRoute($link)) {
            return null;
        }

        $rawPath = parse_url($link, PHP_URL_PATH);

        if (! is_string($rawPath) || $rawPath === '') {
            $rawPath = $link;
        }

        $path = trim($rawPath, '/');
        $base = trim($base, '/');

        if ($base !== '' && ($path === $base || Str::startsWith($path, $base.'/'))) {
            $path = $path === $base ? '' : Str::after($path, $base.'/');
        }

        foreach (['.html', '.md'] as $suffix) {
            if (Str::endsWith($path, $suffix)) {
                $path = Str::beforeLast($path, $suffix);
            }
        }

        if ($path === '') {
            return 'index';
        }

        if (Str::endsWith($rawPath, '/') && $rawPath !== '/') {
            return $path.'/index';
        }

        return $path;
    }

    public static function linkFromPath(string $path): string
    {
        if ($path === 'index') {
            return '/';
        }

        if (Str::endsWith($path, '/index')) {
            return '/'.Str::beforeLast($path, '/index').'/';
        }

        return '/'.$path;
    }

    public static function isExternalUrl(string $url): bool
    {
        return Str::startsWith($url, ['http://', 'https://', '//', 'mailto:']);
    }

    public static function isDynamicRoute(string $url): bool
    {
        return Str::contains($url, '[');
    }

    public static function itemLink(mixed $item): ?string
    {
        if (is_string($item)) {
            return $item;
        }

        if (is_array($item) && isset($item['link']) && is_string($item['link'])) {
            return $item['link'];
        }

        return null;
    }

    public static function normalizeLink(string $link): string
    {
        return trim($link, '/');
    }

    /**
     * Merge external / unmatched / dynamic links from a previous sidebar
     * into a freshly generated one so they survive regeneration.
     *
     * An optional predicate can veto preservation per link (e.g. to drop
     * links to documents that no longer exist).
     */
    public static function mergePreserved(array $generated, array $previous, ?callable $shouldPreserve = null): array
    {
        foreach ($generated as $i => $item) {
            if (! is_array($item) || empty($item['items']) || ! is_array($item['items'])) {
                continue;
            }

            $match = static::findMatch($previous, $item);
            $prevItems = is_array($match) && ! empty($match['items']) && is_array($match['items'])
                ? $match['items']
                : [];

            $item['items'] = static::mergePreserved($item['items'], $prevItems, $shouldPreserve);
            $generated[$i] = $item;
        }

        $used = array_map([static::class, 'normalizeLink'], static::collectLinks($generated));

        foreach ($previous as $item) {
            $link = static::itemLink($item);

            if ($link === null) {
                continue;
            }

            if (in_array(static::normalizeLink($link), $used, true)) {
                continue;
            }

            if ($shouldPreserve && ! $shouldPreserve($link)) {
                continue;
            }

            $generated[] = $item;
        }

        return $generated;
    }

    /**
     * Replace a link throughout the sidebar (e.g. after a path rename).
     */
    public static function replaceLink(array $items, string $oldLink, string $newLink): array
    {
        $old = static::normalizeLink($oldLink);

        foreach ($items as $i => $item) {
            if (is_string($item)) {
                if (static::normalizeLink($item) === $old) {
                    $items[$i] = $newLink;
                }

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            if (isset($item['link']) && is_string($item['link']) && static::normalizeLink($item['link']) === $old) {
                $item['link'] = $newLink;
            }

            if (! empty($item['items']) && is_array($item['items'])) {
                $item['items'] = static::replaceLink($item['items'], $oldLink, $newLink);
            }

            $items[$i] = $item;
        }

        return $items;
    }

    public static function collectLinks(array $items): array
    {
        $links = [];

        foreach ($items as $item) {
            if ($link = static::itemLink($item)) {
                $links[] = $link;
            }

            if (is_array($item) && ! empty($item['items']) && is_array($item['items'])) {
                $links = array_merge($links, static::collectLinks($item['items']));
            }
        }

        return $links;
    }

    public static function findMatch(array $items, array $needle): ?array
    {
        $link = static::itemLink($needle);
        $text = $needle['text'] ?? null;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemLink = static::itemLink($item);

            if ($link && $itemLink && static::normalizeLink($itemLink) === static::normalizeLink($link)) {
                return $item;
            }

            if (! $link && $text && ($item['text'] ?? null) === $text) {
                return $item;
            }
        }

        return null;
    }
}
