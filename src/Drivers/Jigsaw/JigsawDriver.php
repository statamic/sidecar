<?php

namespace Statamic\Sidecar\Drivers\Jigsaw;

use Illuminate\Support\Collection;
use Statamic\Facades\Path;
use Statamic\Facades\URL;
use Statamic\Fields\Blueprint;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Drivers\Driver;
use Statamic\Sidecar\Source;
use Statamic\Support\Str;

/**
 * Docs pages stay flat on disk — the hierarchy is owned by `navigation.php`,
 * so the tree is a projection of that file and saving the tree rewrites it.
 * No files are ever relocated.
 */
class JigsawDriver extends Driver
{
    public function title(): string
    {
        return __('Documentation');
    }

    public function directory(): string
    {
        return $this->config['directory'] ?? base_path('source/docs');
    }

    public function supportsNesting(): bool
    {
        return false;
    }

    public function supportsOrdering(): bool
    {
        // Order lives in navigation.php, which the tree save rewrites in
        // full — nothing needs to be written into front matter.
        return true;
    }

    public function expectsRoot(): bool
    {
        // The docs template has no index root — top-level nav groups are siblings.
        return false;
    }

    public function tree(Source $source): array
    {
        $documents = app(Documents::class)->all($source);
        $navigation = NavigationFile::load($this->navigationPath());

        if (empty($navigation)) {
            // No navigation file (yet) — a flat listing keeps the tree usable,
            // and saving it will create navigation.php.
            return $documents
                ->sortBy(fn (Document $document) => strtolower($document->title()))
                ->map(fn (Document $document) => $this->branch($document, []))
                ->values()
                ->all();
        }

        return $this->navToBranches($navigation, $documents->keyBy(fn (Document $d) => $d->slug()));
    }

    public function saveTree(Source $source, array $branches): void
    {
        $documents = app(Documents::class)->all($source);
        $slugs = $documents->map(fn (Document $document) => $document->slug())->values()->all();

        $navigation = NavigationFile::mergePreserved(
            $this->branchesToNav($branches, $documents),
            NavigationFile::load($this->navigationPath()),
            // Preserve manual links, but drop docs links whose document is gone.
            fn (string $url) => ! $this->isDanglingDocUrl($url, $slugs),
        );

        NavigationFile::write($this->navigationPath(), $navigation);
    }

    public function afterSave(Document $document): void
    {
        $this->ensureDefaultFrontMatter($document);

        // A rename must be patched into navigation.php before regenerating,
        // otherwise the stale URL can't be matched back to the document.
        if (($previous = $document->previousPath()) && $previous !== $document->path()) {
            $navPath = $this->navigationPath();

            NavigationFile::write($navPath, NavigationFile::replaceUrl(
                NavigationFile::load($navPath),
                NavigationFile::url(basename($previous), $this->urlPrefix()),
                NavigationFile::url($document->slug(), $this->urlPrefix()),
            ));
        }

        $this->regenerateNavigation($document->source());
    }

    public function afterDelete(Document $document): void
    {
        // Regenerating drops the now-unresolvable item from navigation.php.
        $this->regenerateNavigation($document->source());
    }

    public function url(Document $document): ?string
    {
        $base = rtrim($this->config['site_url'] ?? url('/'), '/');
        $prefix = trim($this->urlPrefix(), '/');

        return $base.'/'.$prefix.'/'.$document->slug();
    }

    public function previewTargets(Document $document): array
    {
        $action = trim(config('statamic.routes.action', '!'), '/');

        return [
            [
                'label' => __('Jigsaw'),
                'refresh' => true,
                // Custom rendering — WIP content is read from the Live Preview
                // token rather than the saved file on disk.
                'url' => $this->config['preview_url']
                    ?? URL::makeRelative(url($action.'/sidecar/jigsaw/live-preview')),
            ],
        ];
    }

    public function navigationPath(): string
    {
        $path = $this->config['navigation'] ?? base_path('navigation.php');

        return Path::isAbsolute(Path::tidy($path)) ? $path : base_path($path);
    }

    public function urlPrefix(): string
    {
        return $this->config['url_prefix'] ?? 'docs';
    }

    /**
     * A URL under our docs prefix that no longer resolves to a document.
     */
    protected function isDanglingDocUrl(string $url, array $slugs): bool
    {
        if (NavigationFile::isExternalUrl($url)) {
            return false;
        }

        $prefix = trim($this->urlPrefix(), '/');
        $trimmed = trim($url, '/');

        // Manual links elsewhere on the site aren't ours to prune.
        if ($prefix !== '' && $trimmed !== $prefix && ! Str::startsWith($trimmed, $prefix.'/')) {
            return false;
        }

        $slug = NavigationFile::slugFromUrl($url, $this->urlPrefix());

        return $slug !== null && ! in_array($slug, $slugs, true);
    }

    protected function regenerateNavigation(Source $source): void
    {
        if (! empty(NavigationFile::load($this->navigationPath()))) {
            $this->saveTree($source, $this->tree($source));
        }
    }

    protected function ensureDefaultFrontMatter(Document $document): void
    {
        $dirty = false;

        if (! $document->get('extends')) {
            $document->set('extends', '_layouts.documentation');
            $dirty = true;
        }

        if (! $document->get('section')) {
            $document->set('section', 'content');
            $dirty = true;
        }

        if ($dirty) {
            app(Documents::class)->save($document);
        }
    }

    /**
     * @param  Collection<string, Document>  $documentsBySlug
     */
    protected function navToBranches(array $navigation, Collection $documentsBySlug): array
    {
        $branches = [];

        foreach ($navigation as $label => $value) {
            $url = NavigationFile::itemUrl($value);

            if ($url === null || NavigationFile::isExternalUrl($url)) {
                continue;
            }

            $slug = NavigationFile::slugFromUrl($url, $this->urlPrefix());

            if (! $slug || ! $document = $documentsBySlug->get($slug)) {
                continue;
            }

            $children = (is_array($value) && ! empty($value['children']) && is_array($value['children']))
                ? $this->navToBranches($value['children'], $documentsBySlug)
                : [];

            $branches[] = $this->branch($document, $children);
        }

        return $branches;
    }

    /**
     * @param  Collection<string, Document>  $documentsByPath
     */
    protected function branchesToNav(array $branches, Collection $documentsByPath): array
    {
        $nav = [];

        foreach ($branches as $branch) {
            $id = $branch['id'] ?? null;

            if (! $id || ! $document = $documentsByPath->get($id)) {
                continue;
            }

            $label = $document->title();
            $url = NavigationFile::url($document->slug(), $this->urlPrefix());
            $children = $branch['children'] ?? [];

            if ($children) {
                $nav[$label] = [
                    'url' => $url,
                    'children' => $this->branchesToNav($children, $documentsByPath),
                ];
            } else {
                $nav[$label] = $url;
            }
        }

        return $nav;
    }

    protected function branch(Document $document, array $children): array
    {
        return [
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
            'children' => $children,
        ];
    }

    protected function defaultBlueprint(): Blueprint
    {
        return $this->makeBlueprint([
            'title' => __('Doc'),
            'tabs' => [
                'main' => [
                    'display' => __('Main'),
                    'sections' => [
                        [
                            'fields' => [
                                [
                                    'handle' => 'title',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Title'),
                                        'validate' => 'required',
                                    ],
                                ],
                                [
                                    'handle' => 'description',
                                    'field' => [
                                        'type' => 'textarea',
                                        'display' => __('Description'),
                                        'instructions' => __('sidecar::messages.description_instructions'),
                                        'rows' => 2,
                                    ],
                                ],
                                [
                                    'handle' => 'content',
                                    'field' => [
                                        'type' => 'markdown',
                                        'display' => __('Content'),
                                        'container' => null,
                                        'folder' => null,
                                        'restrict' => false,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'sidebar' => [
                    'display' => __('Sidebar'),
                    'sections' => [
                        [
                            'display' => 'Jigsaw',
                            'fields' => [
                                [
                                    'handle' => 'slug',
                                    'field' => [
                                        'type' => 'slug',
                                        'display' => __('Slug'),
                                        'validate' => 'max:200',
                                        'from' => 'title',
                                    ],
                                ],
                                [
                                    'handle' => 'extends',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Extends'),
                                        'instructions' => __('sidecar::messages.extends_instructions'),
                                        'default' => '_layouts.documentation',
                                    ],
                                ],
                                [
                                    'handle' => 'section',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Section'),
                                        'instructions' => __('sidecar::messages.section_instructions'),
                                        'default' => 'content',
                                    ],
                                ],
                                [
                                    'handle' => 'permalink',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Permalink'),
                                        'instructions' => __('sidecar::messages.permalink_instructions'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
