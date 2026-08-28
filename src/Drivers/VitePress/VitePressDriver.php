<?php

namespace Statamic\Sidecar\Drivers\VitePress;

use Illuminate\Support\Collection;
use Statamic\Facades\Path;
use Statamic\Fields\Blueprint;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Drivers\Driver;
use Statamic\Sidecar\Source;
use Statamic\Support\Str;

/**
 * Markdown stays where VitePress put it — the hierarchy is owned by
 * `sidebar.json`, so the tree is a projection of that file and saving
 * the tree rewrites it. No files are ever relocated.
 */
class VitePressDriver extends Driver
{
    const VIRTUAL_PREFIX = '_sidebar::';

    public function title(): string
    {
        return __('Documentation');
    }

    public function directory(): string
    {
        return $this->config['directory'] ?? base_path('docs');
    }

    public function indexFileName(): string
    {
        return 'index';
    }

    public function ignoredPatterns(): array
    {
        return $this->config['ignored_patterns'] ?? ['.vitepress', 'node_modules', 'public', '.*'];
    }

    public function supportsNesting(): bool
    {
        return false;
    }

    public function supportsOrdering(): bool
    {
        return true;
    }

    public function expectsRoot(): bool
    {
        return false;
    }

    public function tree(Source $source): array
    {
        $documents = app(Documents::class)->all($source);
        $items = SidebarFile::items($this->sidebar(), $this->sidebarKey());

        if (empty($items)) {
            return $documents
                ->sortBy(fn (Document $document) => strtolower($document->title()))
                ->map(fn (Document $document) => $this->branch($document, []))
                ->values()
                ->all();
        }

        $branches = $this->itemsToBranches($items, $documents);
        $listed = $this->collectListedPaths($branches);

        $unlisted = $documents
            ->reject(fn (Document $document) => in_array($document->path(), $listed, true))
            ->sortBy(fn (Document $document) => strtolower($document->title()))
            ->map(fn (Document $document) => $this->branch($document, []))
            ->values()
            ->all();

        return array_merge($branches, $unlisted);
    }

    public function saveTree(Source $source, array $branches): void
    {
        $documents = app(Documents::class)->all($source);
        $paths = $documents->keys()->all();
        $sidebar = $this->sidebar();
        $key = $this->sidebarKey();
        $previous = SidebarFile::items($sidebar, $key);

        $items = SidebarFile::mergePreserved(
            $this->branchesToItems($branches, $documents, $previous),
            $previous,
            fn (string $link) => ! $this->isDanglingDocLink($link, $paths),
        );

        $this->writeSidebar(SidebarFile::setItems($sidebar, $items, $key));
    }

    public function afterSave(Document $document): void
    {
        if (($previous = $document->previousPath()) && $previous !== $document->path()) {
            $this->patchSidebarLinks(
                SidebarFile::linkFromPath($previous),
                SidebarFile::linkFromPath($document->path()),
            );
        }

        $this->regenerateSidebar($document->source());
    }

    public function afterDelete(Document $document): void
    {
        $this->regenerateSidebar($document->source());
    }

    public function url(Document $document): ?string
    {
        $site = rtrim($this->config['site_url'] ?? '', '/');
        $base = trim($this->base(), '/');
        $path = $this->publicPath($document);
        $url = ($base !== '' ? '/'.$base : '').$path;

        if ($site === '') {
            return $url === '' ? '/' : $url;
        }

        return $site.($url === '' ? '/' : $url);
    }

    public function previewTargets(Document $document): array
    {
        $url = $this->config['preview_url'] ?? $this->url($document);

        if (! $url) {
            return [];
        }

        return [
            [
                'label' => __('VitePress'),
                'refresh' => true,
                'url' => str_replace('{path}', $this->publicPath($document), $url),
            ],
        ];
    }

    public function sidebarPath(): string
    {
        $path = $this->config['sidebar'] ?? $this->directory().'/.vitepress/sidebar.json';
        $path = Path::tidy($path);

        return Path::isAbsolute($path) ? $path : base_path($path);
    }

    public function sidebarKey(): ?string
    {
        if (array_key_exists('sidebar_key', $this->config)) {
            return $this->config['sidebar_key'];
        }

        $sidebar = $this->sidebar();

        return SidebarFile::isMultiSidebar($sidebar) ? array_key_first($sidebar) : null;
    }

    public function base(): string
    {
        return $this->config['base'] ?? '/';
    }

    public function cleanUrls(): bool
    {
        return (bool) ($this->config['clean_urls'] ?? true);
    }

    public function publicPath(Document $document): string
    {
        $uri = $document->uriPath();

        if ($uri === '') {
            return $this->cleanUrls() ? '/' : '/index.html';
        }

        if ($document->isIndex()) {
            return $this->cleanUrls() ? '/'.$uri.'/' : '/'.$uri.'/index.html';
        }

        return $this->cleanUrls() ? '/'.$uri : '/'.$uri.'.html';
    }

    protected function sidebar(): array
    {
        return SidebarFile::load($this->sidebarPath());
    }

    protected function writeSidebar(array $sidebar): void
    {
        SidebarFile::write($this->sidebarPath(), $sidebar);
    }

    protected function regenerateSidebar(Source $source): void
    {
        if (empty(SidebarFile::items($this->sidebar(), $this->sidebarKey()))) {
            return;
        }

        $this->saveTree($source, $this->tree($source));
    }

    protected function patchSidebarLinks(string $oldLink, string $newLink): void
    {
        $sidebar = $this->sidebar();
        $key = $this->sidebarKey();
        $items = SidebarFile::replaceLink(SidebarFile::items($sidebar, $key), $oldLink, $newLink);

        $this->writeSidebar(SidebarFile::setItems($sidebar, $items, $key));
    }

    /**
     * A sidebar link under our site that no longer resolves to a document.
     */
    protected function isDanglingDocLink(string $link, array $paths): bool
    {
        if (SidebarFile::isExternalUrl($link) || SidebarFile::isDynamicRoute($link)) {
            return false;
        }

        $path = SidebarFile::pathFromLink($link, $this->base());

        if ($path === null) {
            return false;
        }

        return ! in_array($path, $paths, true) && ! in_array($path.'/index', $paths, true);
    }

    /**
     * @param  Collection<string, Document>  $documentsByPath
     */
    protected function itemsToBranches(array $items, Collection $documentsByPath): array
    {
        $branches = [];

        foreach ($items as $item) {
            $link = SidebarFile::itemLink($item);
            $children = (is_array($item) && ! empty($item['items']) && is_array($item['items']))
                ? $this->itemsToBranches($item['items'], $documentsByPath)
                : [];

            if ($document = $this->documentForLink($link, $documentsByPath)) {
                $branches[] = $this->branch($document, $children);

                continue;
            }

            if ($children) {
                $text = is_array($item) ? (string) ($item['text'] ?? 'Untitled') : 'Untitled';
                $branches[] = $this->virtualBranch($text, $children);
            }
        }

        return $branches;
    }

    /**
     * @param  Collection<string, Document>  $documentsByPath
     */
    protected function documentForLink(?string $link, Collection $documentsByPath): ?Document
    {
        if (! $link) {
            return null;
        }

        $path = SidebarFile::pathFromLink($link, $this->base());

        if (! $path) {
            return null;
        }

        return $documentsByPath->get($path)
            ?? $documentsByPath->get($path.'/index');
    }

    /**
     * @param  Collection<string, Document>  $documentsByPath
     */
    protected function branchesToItems(array $branches, Collection $documentsByPath, array $previous): array
    {
        $items = [];

        foreach ($branches as $branch) {
            $id = $branch['id'] ?? null;
            $children = $branch['children'] ?? [];

            if ($id && str_starts_with($id, self::VIRTUAL_PREFIX)) {
                $text = $branch['title'] ?? Str::after($id, self::VIRTUAL_PREFIX);
                $match = SidebarFile::findMatch($previous, ['text' => $text]);
                $item = ['text' => $text];

                if ($children) {
                    $item['items'] = $this->branchesToItems(
                        $children,
                        $documentsByPath,
                        is_array($match) && ! empty($match['items']) && is_array($match['items']) ? $match['items'] : [],
                    );
                }

                $this->copyCollapsed($item, $match);
                $items[] = $item;

                continue;
            }

            if (! $id || ! $document = $documentsByPath->get($id)) {
                continue;
            }

            $link = SidebarFile::linkFromPath($document->path());
            $match = SidebarFile::findMatch($previous, ['text' => $document->title(), 'link' => $link]);
            $item = [
                'text' => $document->title(),
                'link' => $link,
            ];

            if ($children) {
                $item['items'] = $this->branchesToItems(
                    $children,
                    $documentsByPath,
                    is_array($match) && ! empty($match['items']) && is_array($match['items']) ? $match['items'] : [],
                );
            }

            $this->copyCollapsed($item, $match);
            $items[] = $item;
        }

        return $items;
    }

    protected function copyCollapsed(array &$item, ?array $match): void
    {
        if (is_array($match) && array_key_exists('collapsed', $match)) {
            $item['collapsed'] = $match['collapsed'];
        }
    }

    protected function collectListedPaths(array $branches): array
    {
        $paths = [];

        foreach ($branches as $branch) {
            $id = $branch['id'] ?? null;

            if ($id && ! str_starts_with($id, self::VIRTUAL_PREFIX)) {
                $paths[] = $id;
            }

            if (! empty($branch['children']) && is_array($branch['children'])) {
                $paths = array_merge($paths, $this->collectListedPaths($branch['children']));
            }
        }

        return $paths;
    }

    protected function virtualBranch(string $text, array $children): array
    {
        return [
            'id' => self::VIRTUAL_PREFIX.$text,
            'title' => $text,
            'slug' => Str::slug($text),
            'url' => null,
            'edit_url' => null,
            'delete_url' => null,
            'hidden' => false,
            'redirect' => null,
            'group' => null,
            'badge' => null,
            'children' => $children,
        ];
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
                            'display' => 'VitePress',
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
                                    'handle' => 'layout',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Layout'),
                                        'options' => [
                                            'doc' => 'Doc',
                                            'home' => 'Home',
                                            'page' => 'Page',
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'outline',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Outline'),
                                    ],
                                ],
                                [
                                    'handle' => 'aside',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Aside'),
                                    ],
                                ],
                                [
                                    'handle' => 'prev',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Previous link'),
                                    ],
                                ],
                                [
                                    'handle' => 'next',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Next link'),
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
