<?php

namespace Statamic\Sidecar;

use Illuminate\Support\Collection;
use Statamic\Facades\File;
use Statamic\Facades\Path;
use Statamic\Facades\YAML;
use Statamic\Sidecar\Tree\DerivedTree;
use Statamic\Support\Str;

/**
 * Reads and writes documents directly on the filesystem. There is no store,
 * no cache of record — their files are the only source of truth.
 *
 * @experimental
 */
class Documents
{
    /**
     * All documents in a source, keyed by relative path (without extension).
     *
     * @return Collection<string, Document>
     */
    public function all(Source $source): Collection
    {
        $directory = $source->directory();

        if (! File::isDirectory($directory)) {
            return collect();
        }

        $extension = $source->extension();

        return collect(File::getFilesRecursively($directory))
            ->map(fn ($path) => Path::tidy($path))
            ->filter(fn ($path) => Str::endsWith($path, '.'.$extension) && ! Str::startsWith(basename($path), '.'))
            ->map(fn ($path) => $this->makeFromPath($source, $path))
            ->reject(fn (Document $document) => $this->isIgnored($source, $document->path().'.'.$extension))
            ->keyBy(fn (Document $document) => $document->path())
            ->sortKeys();
    }

    public function find(Source $source, string $path): ?Document
    {
        if (! $path = $this->cleanPath($path)) {
            return null;
        }

        $absolute = $source->directory().'/'.$path.'.'.$source->extension();

        if (! File::exists($absolute)) {
            return null;
        }

        return $this->makeFromPath($source, $absolute);
    }

    public function make(Source $source, string $path, array $data = [], string $content = ''): Document
    {
        return new Document($source->handle(), $path, $data, $content);
    }

    public function save(Document $document): void
    {
        File::put($document->absolutePath(), $this->fileContents($document));

        DerivedTree::forget($document->source());
    }

    /**
     * Move/rename a document's file. The previous path is remembered on the
     * document so drivers can react (e.g. rewriting navigation URLs).
     */
    public function move(Document $document, string $newPath): Document
    {
        if ($newPath === $document->path()) {
            return $document;
        }

        $oldAbsolute = $document->absolutePath();

        $document->setPreviousPath($document->path())->setPath($newPath);

        $newAbsolute = $document->absolutePath();

        File::put($newAbsolute, File::get($oldAbsolute) ?? $this->fileContents($document));
        File::delete($oldAbsolute);

        $this->deleteEmptyDirectories([dirname($oldAbsolute)], $document->source()->directory());

        DerivedTree::forget($document->source());

        return $document;
    }

    public function delete(Document $document): void
    {
        $absolute = $document->absolutePath();

        File::delete($absolute);

        $this->deleteEmptyDirectories([dirname($absolute)], $document->source()->directory());

        DerivedTree::forget($document->source());
    }

    /**
     * Delete directories left empty after moves, walking up to (but never
     * touching) the source root.
     */
    public function deleteEmptyDirectories(array $directories, string $root): void
    {
        $root = Path::tidy(rtrim($root, '/'));

        collect($directories)
            ->filter()
            ->flatMap(function ($directory) use ($root) {
                $directory = Path::tidy(rtrim($directory, '/'));
                $dirs = [];

                while (
                    $directory
                    && $directory !== $root
                    && Str::startsWith($directory, $root.'/')
                ) {
                    $dirs[] = $directory;
                    $directory = Path::tidy(dirname($directory));
                }

                return $dirs;
            })
            ->unique()
            ->sortByDesc(fn ($dir) => substr_count($dir, '/'))
            ->each(function ($directory) {
                if (! File::exists($directory) || ! File::isDirectory($directory)) {
                    return;
                }

                if (! File::isEmpty($directory)) {
                    return;
                }

                File::delete($directory);
            });
    }

    public function fileContents(Document $document): string
    {
        if (empty($document->data())) {
            return $document->content();
        }

        return YAML::dumpFrontMatter($document->data(), $document->content());
    }

    protected function makeFromPath(Source $source, string $path): Document
    {
        $absolute = $this->resolveAbsolutePath($source, $path);
        $root = Str::finish(Path::tidy($source->directory()), '/');
        $relative = Str::after(Path::tidy($absolute), $root);
        $path = Str::beforeLast($relative, '.'.$source->extension());

        $contents = File::get($absolute) ?? '';

        if (! Str::startsWith(ltrim($contents), '---')) {
            return $this->make($source, $path, [], $contents);
        }

        $parsed = YAML::parse($contents);
        $content = $parsed['content'] ?? '';
        unset($parsed['content']);

        return $this->make($source, $path, $parsed, is_string($content) ? $content : '');
    }

    /**
     * Statamic's File::getFilesRecursively() returns paths relative to the
     * project root when the directory lives inside base_path().
     */
    protected function resolveAbsolutePath(Source $source, string $path): string
    {
        $path = Path::tidy($path);

        if (Path::isAbsolute($path)) {
            return $path;
        }

        $fromProjectRoot = Path::tidy(base_path($path));

        if (File::exists($fromProjectRoot)) {
            return $fromProjectRoot;
        }

        return Path::tidy($source->directory().'/'.$path);
    }

    /**
     * Match LaraDocs-style ignored_patterns: each path segment is fnmatched.
     */
    protected function isIgnored(Source $source, string $relativePath): bool
    {
        $patterns = $source->driver()->ignoredPatterns();

        if (empty($patterns)) {
            return false;
        }

        foreach (explode('/', trim($relativePath, '/')) as $segment) {
            foreach ($patterns as $pattern) {
                if (fnmatch($pattern, $segment)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function cleanPath(string $path): ?string
    {
        $path = trim(Path::tidy($path), '/');

        if ($path === '' || Str::contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
