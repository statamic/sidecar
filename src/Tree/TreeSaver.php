<?php

namespace Statamic\Sidecar\Tree;

use Illuminate\Validation\ValidationException;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Source;
use Statamic\Support\Str;

/**
 * Persists a rearranged tree straight to the filesystem: diffs each branch's
 * expected path (from tree ancestry) against its current one, moves files,
 * converts sections/leaves (`slug/_index.md` ↔ `slug.md`), and writes sibling
 * `order` front matter. No tree document is ever written.
 *
 * @experimental
 */
class TreeSaver
{
    public function save(Source $source, array $branches): void
    {
        $documents = app(Documents::class)->all($source);
        $repository = app(Documents::class);
        $driver = $source->driver();
        $index = $source->indexFileName();

        if ($source->expectsRoot() && ($branches[0]['id'] ?? null) === $index) {
            $root = array_shift($branches);

            if (! empty($root['children'])) {
                throw ValidationException::withMessages([
                    'pages' => __('The root page cannot have children.'),
                ]);
            }
        }

        $this->syncBranches($source, $documents, $repository, $branches, [], $driver, $index);
    }

    protected function syncBranches(
        Source $source,
        $documents,
        Documents $repository,
        array $branches,
        array $ancestry,
        $driver,
        string $index,
    ): void {
        foreach (array_values($branches) as $i => $branch) {
            $position = $i + 1;
            $id = $branch['id'] ?? null;

            if (! $id) {
                continue;
            }

            $children = $branch['children'] ?? [];

            // Virtual branches represent folders without an index document.
            // They can't move themselves, but their slug still forms their
            // children's ancestry.
            if (Str::startsWith($id, DerivedTree::VIRTUAL_PREFIX)) {
                if ($slug = $branch['slug'] ?? basename(Str::after($id, DerivedTree::VIRTUAL_PREFIX))) {
                    $this->syncBranches($source, $documents, $repository, $children, [...$ancestry, $slug], $driver, $index);
                }

                continue;
            }

            /** @var Document|null $document */
            $document = $documents->get($id);

            if (! $document) {
                continue;
            }

            $slug = $document->slug();

            if ($driver->supportsNesting()) {
                $expected = ($ancestry ? implode('/', $ancestry).'/' : '').$slug;

                if (! empty($children)) {
                    $expected .= '/'.$index;
                }

                if ($expected !== $document->path()) {
                    $repository->move($document, $expected);
                }
            }

            if ($driver->supportsOrdering() && (int) $document->get('order') !== $position) {
                $document->set('order', $position);
                $repository->save($document);
            }

            if (! empty($children)) {
                $this->syncBranches($source, $documents, $repository, $children, [...$ancestry, $slug], $driver, $index);
            }
        }
    }
}
