<?php

namespace Statamic\Sidecar\Tree;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\File;
use Statamic\Facades\Path;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Source;
use Statamic\Support\Str;

/**
 * Projects a source's directory into tree branches: nesting from the folder
 * layout, sibling order from `order` front matter. Never stored — safe to
 * cache because it's regenerable from the files alone.
 *
 * @experimental
 */
class DerivedTree
{
    /**
     * Prefix for branches synthesized for folders that contain pages but
     * have no index document of their own.
     */
    const VIRTUAL_PREFIX = '_folder::';

    public static function cacheKey(Source $source): string
    {
        return 'sidecar.tree.'.$source->handle();
    }

    public static function forget(Source $source): void
    {
        Cache::forget(static::cacheKey($source));
    }

    public function build(Source $source): array
    {
        $signature = $this->signature($source);
        $cacheKey = static::cacheKey($source);

        $cached = Cache::get($cacheKey);

        if ($cached && ($cached['signature'] ?? null) === $signature) {
            return $cached['branches'];
        }

        $branches = $this->buildFresh($source);

        Cache::put($cacheKey, ['signature' => $signature, 'branches' => $branches], now()->addHour());

        return $branches;
    }

    protected function buildFresh(Source $source): array
    {
        $documents = app(Documents::class)->all($source);
        $index = $source->indexFileName();

        $branches = $this->children($source, $documents, '');

        if ($source->expectsRoot() && ($root = $documents->get($index))) {
            // expectsRoot trees put the root first with no children —
            // top-level pages are its siblings.
            array_unshift($branches, $this->branch($root, null));
        }

        return $branches;
    }

    /**
     * @param  Collection<string, Document>  $documents
     */
    protected function children(Source $source, Collection $documents, string $folder): array
    {
        $index = $source->indexFileName();
        $prefix = $folder === '' ? '' : $folder.'/';

        $direct = $documents->filter(function (Document $document, $path) use ($prefix, $index, $folder) {
            if ($folder === '' && $path === $index) {
                return false; // the root, handled separately
            }

            if ($prefix !== '' && ! Str::startsWith($path, $prefix)) {
                return false;
            }

            $rest = $prefix === '' ? $path : Str::after($path, $prefix);
            $parts = explode('/', $rest);

            if (count($parts) === 1) {
                return $parts[0] !== $index;
            }

            // A section's own index doc represents the section branch.
            return count($parts) === 2 && $parts[1] === $index;
        });

        $branches = $this->sort($source, $direct)
            ->map(function (Document $document) use ($source, $documents, $index) {
                $children = $document->isIndex()
                    ? $this->children($source, $documents, Str::beforeLast($document->path(), '/'.$index))
                    : [];

                return $this->branch($document, $children);
            })
            ->values()
            ->all();

        return array_merge($branches, $this->virtualSections($source, $documents, $folder));
    }

    /**
     * Folders that contain pages but no index document still need a branch
     * so their children are reachable in the tree.
     */
    protected function virtualSections(Source $source, Collection $documents, string $folder): array
    {
        $index = $source->indexFileName();
        $prefix = $folder === '' ? '' : $folder.'/';

        return $documents
            ->keys()
            ->filter(fn ($path) => $prefix === '' || Str::startsWith($path, $prefix))
            ->map(function ($path) use ($prefix) {
                $rest = $prefix === '' ? $path : Str::after($path, $prefix);

                return Str::contains($rest, '/') ? Str::before($rest, '/') : null;
            })
            ->filter()
            ->unique()
            ->reject(fn ($name) => $documents->has($prefix.$name.'/'.$index))
            ->map(fn ($name) => [
                'id' => self::VIRTUAL_PREFIX.$prefix.$name,
                'title' => Str::title(str_replace('-', ' ', $name)),
                'slug' => $name,
                'url' => null,
                'edit_url' => null,
                'delete_url' => null,
                'hidden' => false,
                'redirect' => null,
                'group' => null,
                'badge' => null,
                'children' => $this->children($source, $documents, $prefix.$name),
            ])
            ->values()
            ->all();
    }

    protected function branch(Document $document, ?array $children): array
    {
        $branch = [
            'id' => $document->path(),
            'title' => $document->title(),
            'slug' => $document->slug(),
            'url' => $document->editUrl(),
            'edit_url' => $document->editUrl(),
            'delete_url' => $document->deleteUrl(),
            'hidden' => (bool) $document->get('hidden'),
            'redirect' => $document->get('redirect'),
            'group' => $document->get('group'),
            'badge' => $document->get('badge'),
            'children' => $children ?? [],
        ];

        return $branch;
    }

    /**
     * @param  Collection<string, Document>  $documents
     */
    protected function sort(Source $source, Collection $documents): Collection
    {
        $driver = $source->driver();

        return $documents->sortBy(fn (Document $document) => [
            $driver->orderValue($document) ?? PHP_INT_MAX,
            strtolower($document->title()),
        ]);
    }

    protected function signature(Source $source): string
    {
        $directory = $source->directory();

        if (! File::isDirectory($directory)) {
            return 'empty';
        }

        $extension = '.'.$source->extension();

        $files = collect(File::getFilesRecursively($directory))
            ->map(fn ($path) => Path::tidy($path))
            ->filter(fn ($path) => Str::endsWith($path, $extension))
            ->sort()
            ->map(fn ($path) => $path.':'.File::lastModified($path))
            ->implode('|');

        return md5($files);
    }
}
