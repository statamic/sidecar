<?php

namespace Statamic\Sidecar\Drivers;

use InvalidArgumentException;
use Statamic\Facades\Blueprint;
use Statamic\Fields\Blueprint as BlueprintInstance;
use Statamic\Sidecar\Contracts\Driver as DriverContract;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Source;
use Statamic\Sidecar\Tree\DerivedTree;
use Statamic\Sidecar\Tree\TreeSaver;

/**
 * @experimental
 */
abstract class Driver implements DriverContract
{
    public function __construct(
        protected array $config,
        protected string $sourceHandle,
    ) {}

    abstract public function title(): string;

    abstract protected function defaultBlueprint(): BlueprintInstance;

    public function directory(): string
    {
        return $this->config['directory']
            ?? throw new InvalidArgumentException("Sidecar source [{$this->sourceHandle}] is missing a directory.");
    }

    public function extension(): string
    {
        return $this->config['extension'] ?? 'md';
    }

    public function indexFileName(): string
    {
        return '_index';
    }

    public function ignoredPatterns(): array
    {
        return [];
    }

    /**
     * Adjust the publish blueprint for a specific document or create context.
     */
    public function prepareBlueprint(BlueprintInstance $blueprint, ?Document $document = null, array $context = []): BlueprintInstance
    {
        if (! $document) {
            $blueprint->ensureField('parent', ['type' => 'hidden']);
            $blueprint->ensureField('as_section', ['type' => 'hidden']);
        }

        return $blueprint;
    }

    public function blueprint(): BlueprintInstance
    {
        if ($handle = $this->config['blueprint'] ?? null) {
            return Blueprint::find($handle)
                ?? throw new InvalidArgumentException("Sidecar blueprint [{$handle}] not found.");
        }

        return $this->defaultBlueprint();
    }

    public function expectsRoot(): bool
    {
        return $this->supportsNesting();
    }

    public function supportsNesting(): bool
    {
        return false;
    }

    public function supportsOrdering(): bool
    {
        return $this->supportsNesting();
    }

    /**
     * Sibling sort key derived from the document. Null sorts last.
     */
    public function orderValue(Document $document): ?int
    {
        $order = $document->get('order');

        return $order !== null ? (int) $order : null;
    }

    /**
     * Persist a sibling position into the driver's on-disk format.
     */
    public function persistOrder(Document $document, int $position): void
    {
        $document->set('order', $position);
    }

    public function tree(Source $source): array
    {
        return (new DerivedTree)->build($source);
    }

    public function saveTree(Source $source, array $branches): void
    {
        (new TreeSaver)->save($source, $branches);
    }

    public function afterTreeSaved(Source $source): void
    {
        //
    }

    public function afterSave(Document $document): void
    {
        //
    }

    public function afterDelete(Document $document): void
    {
        //
    }

    public function url(Document $document): ?string
    {
        return null;
    }

    public function previewTargets(Document $document): array
    {
        $url = $this->config['preview_url'] ?? $this->url($document);

        if (! $url) {
            return [];
        }

        return [
            [
                'label' => __('Site'),
                'refresh' => true,
                'url' => str_replace('{path}', $document->uriPath(), $url),
            ],
        ];
    }

    public function sourceHandle(): string
    {
        return $this->sourceHandle;
    }

    public function config(?string $key = null, $default = null)
    {
        if (is_null($key)) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    protected function makeBlueprint(array $contents): BlueprintInstance
    {
        return Blueprint::make($this->sourceHandle)
            ->setNamespace('sidecar')
            ->setContents($contents);
    }
}
