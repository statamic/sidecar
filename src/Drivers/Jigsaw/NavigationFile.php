<?php

namespace Statamic\Sidecar\Drivers\Jigsaw;

use Statamic\Facades\File;
use Statamic\Support\Str;

/**
 * Reads and writes a Jigsaw `navigation.php` return array. The navigation
 * file owns the docs hierarchy — Sidecar's tree is a projection of it.
 */
class NavigationFile
{
    public static function load(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $navigation = require $path;

        return is_array($navigation) ? $navigation : [];
    }

    public static function write(string $path, array $navigation): void
    {
        File::put($path, static::export($navigation));
    }

    /**
     * Export a navigation array as PHP source for navigation.php.
     */
    public static function export(array $navigation): string
    {
        $exported = static::exportValue($navigation, 0);

        return "<?php\n\nreturn {$exported};\n";
    }

    public static function url(string $slug, string $urlPrefix): string
    {
        $urlPrefix = trim($urlPrefix, '/');

        return $urlPrefix === '' ? $slug : $urlPrefix.'/'.$slug;
    }

    public static function slugFromUrl(string $url, string $urlPrefix): ?string
    {
        $url = trim($url, '/');
        $urlPrefix = trim($urlPrefix, '/');

        if (static::isExternalUrl($url)) {
            return null;
        }

        if ($urlPrefix !== '' && $url === $urlPrefix) {
            return null;
        }

        if ($urlPrefix !== '' && Str::startsWith($url, $urlPrefix.'/')) {
            $url = Str::after($url, $urlPrefix.'/');
        }

        // Flat docs pages: always resolve to the filename slug.
        return Str::contains($url, '/') ? Str::afterLast($url, '/') : $url;
    }

    public static function isExternalUrl(string $url): bool
    {
        return Str::startsWith($url, ['http://', 'https://', '//', 'mailto:']);
    }

    public static function itemUrl(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && isset($value['url']) && is_string($value['url'])) {
            return $value['url'];
        }

        return null;
    }

    /**
     * Merge external / unmatched manual links from a previous navigation
     * into a freshly generated one so they survive regeneration.
     *
     * An optional predicate can veto preservation per URL (e.g. to drop
     * links to documents that no longer exist).
     */
    public static function mergePreserved(array $generated, array $previous, ?callable $shouldPreserve = null): array
    {
        $usedUrls = static::collectUrls($generated);

        foreach ($previous as $label => $value) {
            $url = static::itemUrl($value);

            if ($url === null) {
                continue;
            }

            if (static::isExternalUrl($url) || ! in_array($url, $usedUrls, true)) {
                if ($shouldPreserve && ! $shouldPreserve($url)) {
                    continue;
                }

                if (! array_key_exists($label, $generated)) {
                    $generated[$label] = $value;
                }
            }
        }

        return $generated;
    }

    /**
     * Replace a URL throughout the navigation (e.g. after a slug rename).
     */
    public static function replaceUrl(array $navigation, string $oldUrl, string $newUrl): array
    {
        foreach ($navigation as $label => $value) {
            if (is_string($value)) {
                if (trim($value, '/') === trim($oldUrl, '/')) {
                    $navigation[$label] = $newUrl;
                }

                continue;
            }

            if (is_array($value)) {
                if (isset($value['url']) && is_string($value['url']) && trim($value['url'], '/') === trim($oldUrl, '/')) {
                    $value['url'] = $newUrl;
                }

                if (! empty($value['children']) && is_array($value['children'])) {
                    $value['children'] = static::replaceUrl($value['children'], $oldUrl, $newUrl);
                }

                $navigation[$label] = $value;
            }
        }

        return $navigation;
    }

    public static function collectUrls(array $navigation): array
    {
        $urls = [];

        foreach ($navigation as $value) {
            if ($url = static::itemUrl($value)) {
                $urls[] = $url;
            }

            if (is_array($value) && ! empty($value['children']) && is_array($value['children'])) {
                $urls = array_merge($urls, static::collectUrls($value['children']));
            }
        }

        return $urls;
    }

    protected static function exportValue(mixed $value, int $depth): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $indent = str_repeat('    ', $depth);
            $inner = str_repeat('    ', $depth + 1);
            $isList = array_is_list($value);
            $lines = ['['];

            foreach ($value as $key => $item) {
                $exported = static::exportValue($item, $depth + 1);

                if ($isList) {
                    $lines[] = "{$inner}{$exported},";
                } else {
                    $lines[] = "{$inner}".static::exportKey($key)." => {$exported},";
                }
            }

            $lines[] = "{$indent}]";

            return implode("\n", $lines);
        }

        if (is_string($value)) {
            return var_export($value, true);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return var_export($value, true);
    }

    protected static function exportKey(string|int $key): string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        return var_export($key, true);
    }
}
