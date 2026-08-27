<?php

namespace Statamic\Sidecar\Contracts;

use Statamic\Fields\Blueprint;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Source;

/**
 * Contract for Sidecar drivers.
 *
 * Drivers teach Statamic how to edit content belonging to an external
 * static site generator (or similar markdown-based system) in place.
 * Anything that affects the SSG's output lives in the driver's own
 * format — Sidecar persists nothing of its own.
 *
 * @experimental
 */
interface Driver
{
    /**
     * Human-readable default title for the source.
     */
    public function title(): string;

    /**
     * Absolute directory containing the documents.
     */
    public function directory(): string;

    /**
     * File extension (without the dot) of the documents.
     */
    public function extension(): string;

    /**
     * Filename (without extension) used for section/root index pages.
     */
    public function indexFileName(): string;

    /**
     * fnmatch patterns matched against each path segment when listing files.
     */
    public function ignoredPatterns(): array;

    /**
     * Default blueprint used when the user hasn't saved an override.
     */
    public function blueprint(): Blueprint;

    /**
     * Whether the tree has a root page (e.g. an `_index.md` at the top level).
     */
    public function expectsRoot(): bool;

    /**
     * Whether nesting is stored as real subfolders on disk, meaning a
     * tree save may relocate files.
     */
    public function supportsNesting(): bool;

    /**
     * Whether sibling order can be persisted in the driver's format.
     * When false, drag-to-reorder is disabled rather than silently discarded.
     */
    public function supportsOrdering(): bool;

    /**
     * Branches for the tree UI, derived from the driver's own representation
     * (folder layout, navigation file, etc). Never a stored tree document.
     */
    public function tree(Source $source): array;

    /**
     * Persist a rearranged tree back into the driver's format.
     */
    public function saveTree(Source $source, array $branches): void;

    /**
     * Called after a tree save has been persisted.
     */
    public function afterTreeSaved(Source $source): void;

    /**
     * Called after a document is saved through the Control Panel.
     */
    public function afterSave(Document $document): void;

    /**
     * Called after a document is deleted through the Control Panel.
     */
    public function afterDelete(Document $document): void;

    /**
     * Public URL of the document on the rendered site, if any (Visit URL).
     */
    public function url(Document $document): ?string;

    /**
     * Live Preview targets. Each target needs `label`, `url` and `refresh` keys.
     */
    public function previewTargets(Document $document): array;
}
