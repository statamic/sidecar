<?php

namespace Statamic\Sidecar\Drivers\LaraDocs;

use Illuminate\Support\Facades\Artisan;
use Laradocs\Routing\DocumentUrl;
use Statamic\Facades\Icon;
use Statamic\Facades\URL;
use Statamic\Fields\Blueprint;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Drivers\Driver;
use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Sidecar\Source;

/**
 * LaraDocs stores nesting as real subfolders (`guide/routing.md`, sections as
 * `guide/_index.md`, root as `_index.md`) and sibling order as `order` front
 * matter — exactly the derived tree's native format, so the base driver's
 * tree projection and saver are used as-is.
 */
class LaraDocsDriver extends Driver
{
    public function title(): string
    {
        return __('Documentation');
    }

    public function directory(): string
    {
        return $this->config['directory']
            ?? config('laradocs.docs.path')
            ?? base_path('docs');
    }

    public function indexFileName(): string
    {
        return config('laradocs.docs.index', '_index');
    }

    public function ignoredPatterns(): array
    {
        return config('laradocs.docs.ignored_patterns', ['.*', '_drafts', 'README.md']);
    }

    public function supportsNesting(): bool
    {
        return true;
    }

    public function afterSave(Document $document): void
    {
        $this->clearCache();
    }

    public function afterDelete(Document $document): void
    {
        $this->clearCache();
    }

    public function afterTreeSaved(Source $source): void
    {
        $this->clearCache();
    }

    public function url(Document $document): ?string
    {
        $slug = $document->value('url_slug') ?: $document->uriPath();

        if (class_exists(DocumentUrl::class)) {
            try {
                return DocumentUrl::toSlug((string) $slug);
            } catch (\Throwable $e) {
                // Routes may be unregistered in tests or before LaraDocs boots.
            }
        }

        $prefix = trim(config('laradocs.route.prefix', 'docs'), '/');

        return $slug === '' ? url($prefix) : url($prefix.'/'.$slug);
    }

    public function previewTargets(Document $document): array
    {
        $action = trim(config('statamic.routes.action', '!'), '/');

        return [
            [
                'label' => __('Docs'),
                'refresh' => true,
                'url' => $this->config['preview_url']
                    ?? URL::makeRelative(url($action.'/sidecar/laradocs/live-preview')),
            ],
        ];
    }

    public function prepareBlueprint(Blueprint $blueprint, ?Document $document = null, array $context = []): Blueprint
    {
        $asSection = (bool) ($context['as_section'] ?? false);

        if (! $document) {
            $blueprint->ensureField('parent', ['type' => 'hidden']);
            $blueprint->ensureField('as_section', ['type' => 'hidden']);
        }

        if ($group = $blueprint->field('group')) {
            $config = array_merge($group->config(), [
                'options' => $this->existingGroups(),
            ]);

            $hideGroup = $asSection || ($document && $document->isIndex() && ! $document->isRoot());

            if ($hideGroup) {
                $config['visibility'] = 'hidden';
            }

            $group->setConfig($config);
        }

        if (($icon = $blueprint->field('icon')) && ! Icon::sets()->has('heroicons')) {
            $config = $icon->config();
            unset($config['set']);
            $icon->setConfig(array_merge($config, ['type' => 'text']));
        }

        return $blueprint;
    }

    /**
     * @return array<string, string>
     */
    public function existingGroups(): array
    {
        $source = Sidecar::source($this->sourceHandle);

        if (! $source) {
            return [];
        }

        return app(Documents::class)
            ->all($source)
            ->map(fn (Document $document) => $document->get('group'))
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn ($group) => [$group => $group])
            ->all();
    }

    protected function clearCache(): void
    {
        try {
            Artisan::call('laradocs:clear');
        } catch (\Throwable $e) {
            report($e);
        }
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
                            'display' => 'LaraDocs',
                            'fields' => [
                                [
                                    'handle' => 'slug',
                                    'field' => [
                                        'type' => 'slug',
                                        'display' => __('sidecar::messages.filename_display'),
                                        'validate' => 'max:200',
                                        'from' => 'title',
                                        'append' => '.md',
                                    ],
                                ],
                                [
                                    'handle' => 'group',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('sidecar::messages.group_display'),
                                        'clearable' => true,
                                        'taggable' => true,
                                        'push_tags' => true,
                                        'limit' => 1,
                                    ],
                                ],
                                [
                                    'handle' => 'icon',
                                    'field' => [
                                        'type' => 'icon',
                                        'display' => __('Icon'),
                                        'set' => 'heroicons',
                                    ],
                                ],
                                [
                                    'handle' => 'badge',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Badge'),
                                        'taggable' => true,
                                        'clearable' => true,
                                        'push_tags' => true,
                                        'limit' => 1,
                                        'options' => [
                                            'New' => __('New'),
                                            'Beta' => __('Beta'),
                                            'Stable' => __('Stable'),
                                            'Deprecated' => __('Deprecated'),
                                            'Archived' => __('Archived'),
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'hidden',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('sidecar::messages.hidden_display'),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'meta' => [
                    'display' => __('Meta'),
                    'sections' => [
                        [
                            'display' => __('Tags'),
                            'fields' => [
                                [
                                    'handle' => 'tags',
                                    'field' => [
                                        'type' => 'select',
                                        'display' => __('Tags'),
                                        'taggable' => true,
                                        'multiple' => true,
                                        'push_tags' => true,
                                        'clearable' => true,
                                    ],
                                ],
                            ],
                        ],
                        [
                            'display' => __('URL'),
                            'fields' => [
                                [
                                    'handle' => 'url_overrides',
                                    'field' => [
                                        'type' => 'revealer',
                                        'display' => __('sidecar::messages.url_overrides_display'),
                                        'mode' => 'button',
                                        'input_label' => __('sidecar::messages.url_overrides_display'),
                                    ],
                                ],
                                [
                                    'handle' => 'url_slug',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('sidecar::messages.url_slug_display'),
                                        'if' => [
                                            'url_overrides' => 'equals true',
                                        ],
                                    ],
                                ],
                                [
                                    'handle' => 'redirect',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Redirect'),
                                        'if' => [
                                            'url_overrides' => 'equals true',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'display' => __('SEO'),
                            'fields' => [
                                [
                                    'handle' => 'author',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Author'),
                                    ],
                                ],
                                [
                                    'handle' => 'image',
                                    'field' => [
                                        'type' => 'text',
                                        'display' => __('Image'),
                                    ],
                                ],
                            ],
                        ],
                        [
                            'display' => __('Search'),
                            'fields' => [
                                [
                                    'handle' => 'search',
                                    'field' => [
                                        'type' => 'toggle',
                                        'display' => __('sidecar::messages.search_display'),
                                        'default' => true,
                                    ],
                                ],
                                [
                                    'handle' => 'search_rank',
                                    'field' => [
                                        'type' => 'float',
                                        'display' => __('sidecar::messages.search_rank_display'),
                                        'default' => 1,
                                        'unless' => [
                                            'search' => 'equals false',
                                        ],
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
