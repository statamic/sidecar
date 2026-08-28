<?php

namespace Statamic\Sidecar\Drivers\Hyde;

use Statamic\Fields\Blueprint;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Drivers\Driver;
use Statamic\Support\Arr;

/**
 * HydePHP stores docs in `_docs/` as real folders (subdirectories become
 * sidebar groups). Sibling order is `navigation.priority` front matter
 * (or a numeric filename prefix). The derived tree/saver handle moves;
 * this driver just maps Hyde's order format and flattened public URLs.
 */
class HydeDriver extends Driver
{
    public function title(): string
    {
        return __('Documentation');
    }

    public function directory(): string
    {
        return $this->config['directory'] ?? base_path('_docs');
    }

    public function indexFileName(): string
    {
        return 'index';
    }

    public function ignoredPatterns(): array
    {
        return $this->config['ignored_patterns'] ?? ['_*', '.*'];
    }

    public function supportsNesting(): bool
    {
        return true;
    }

    public function expectsRoot(): bool
    {
        return true;
    }

    public function orderValue(Document $document): ?int
    {
        $priority = Arr::get($document->get('navigation') ?? [], 'priority');

        if ($priority !== null) {
            return (int) $priority;
        }

        if (preg_match('/^(\d+)[-_]/', basename($document->path()), $matches)) {
            return (int) $matches[1];
        }

        return parent::orderValue($document);
    }

    public function persistOrder(Document $document, int $position): void
    {
        $navigation = $document->get('navigation');
        $navigation = is_array($navigation) ? $navigation : [];
        $navigation['priority'] = $position;

        $document->set('navigation', $navigation);
        $document->remove('order');
    }

    public function url(Document $document): ?string
    {
        $site = rtrim($this->config['site_url'] ?? '', '/');
        $prefix = trim($this->urlPrefix(), '/');
        $path = $this->publicPath($document);
        $url = ($prefix !== '' ? '/'.$prefix : '').($path === '/' ? '/' : $path);

        if ($site === '') {
            return $url;
        }

        return $site.($url === '/' ? '/' : $url);
    }

    public function previewTargets(Document $document): array
    {
        $url = $this->config['preview_url'] ?? $this->url($document);

        if (! $url) {
            return [];
        }

        return [
            [
                'label' => __('HydePHP'),
                'refresh' => true,
                'url' => str_replace('{path}', $this->publicPath($document), $url),
            ],
        ];
    }

    public function urlPrefix(): string
    {
        return $this->config['url_prefix'] ?? 'docs';
    }

    public function flattened(): bool
    {
        return (bool) ($this->config['flattened'] ?? true);
    }

    public function publicPath(Document $document): string
    {
        if ($document->isRoot()) {
            return '/';
        }

        $key = $this->flattened()
            ? $document->slug()
            : $document->uriPath();

        $key = $this->stripNumericPrefixes($key);

        return $key === '' ? '/' : '/'.$key;
    }

    protected function stripNumericPrefixes(string $path): string
    {
        return collect(explode('/', $path))
            ->map(fn (string $segment) => preg_replace('/^\d+[-_]/', '', $segment) ?? $segment)
            ->implode('/');
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
                            'display' => 'HydePHP',
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
                                    'handle' => 'hidden',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('Hide from sidebar'),
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
