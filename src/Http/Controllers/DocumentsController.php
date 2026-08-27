<?php

namespace Statamic\Sidecar\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Sidecar\Source;
use Statamic\Support\Str;

class DocumentsController extends CpController
{
    public function create(Request $request, string $sourceHandle)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);
        abort_if($source->readOnly(), 403);

        $parent = $this->folderFromParent($source, $request->query('parent'));
        $asSection = $request->boolean('section');

        $blueprint = $this->blueprint($source, null, [
            'parent' => $parent,
            'as_section' => $asSection,
        ]);

        $fields = $blueprint->fields()->addValues([
            'parent' => $parent,
            'as_section' => $asSection,
        ])->preProcess();

        $document = app(Documents::class)->make($source, $this->proposedPath($source, $parent, 'new-document', $asSection));

        return Inertia::render('sidecar/Edit', [
            'title' => __('Create Document'),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
            'submitUrl' => cp_route('sidecar.documents.store', $source->handle()),
            'submitMethod' => 'post',
            'readOnly' => false,
            'isCreating' => true,
            'livePreviewUrl' => $source->createPreviewUrl(),
            'previewTargets' => $source->driver()->previewTargets($document),
            'permalink' => null,
            'listingUrl' => $source->showUrl(),
        ]);
    }

    public function store(Request $request, string $sourceHandle)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);
        abort_if($source->readOnly(), 403);

        $parent = $this->folderFromParent($source, $request->input('parent'));
        $asSection = $request->boolean('as_section');

        $blueprint = $this->blueprint($source, null, [
            'parent' => $parent,
            'as_section' => $asSection,
        ]);

        $fields = $blueprint->fields()->addValues($request->all());

        $fields->validate();

        $values = $fields->process()->values()->all();

        $slug = Str::slug($values['slug'] ?? '') ?: Str::slug($values['title'] ?? '');

        if (! $slug) {
            throw ValidationException::withMessages([
                'slug' => __('A slug or title is required.'),
            ]);
        }

        $repository = app(Documents::class);

        $this->convertLeafToSectionIfNeeded($source, $parent);

        $path = $this->proposedPath($source, $parent, $slug, $asSection);

        if ($repository->find($source, $path)) {
            throw ValidationException::withMessages([
                'slug' => __('A document with this slug already exists.'),
            ]);
        }

        $document = $repository->make(
            $source,
            $path,
            $this->dataFromValues([], $values),
            (string) ($values['content'] ?? ''),
        );

        $repository->save($document);

        $source->driver()->afterSave($document);

        return ['data' => ['redirect' => $document->editUrl()]];
    }

    public function edit(string $sourceHandle, string $path)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);

        $document = app(Documents::class)->find($source, $path);

        abort_unless($document, 404);

        $blueprint = $this->blueprint($source, $document);

        $fields = $blueprint->fields()->addValues($document->values())->preProcess();

        return Inertia::render('sidecar/Edit', [
            'title' => $document->title(),
            'blueprint' => $blueprint->toPublishArray(),
            'values' => $fields->values(),
            'meta' => $fields->meta(),
            'submitUrl' => $document->updateUrl(),
            'submitMethod' => 'patch',
            'readOnly' => $source->readOnly(),
            'isCreating' => false,
            'livePreviewUrl' => $source->readOnly() ? null : $document->livePreviewUrl(),
            'previewTargets' => $document->previewTargets(),
            'permalink' => $document->url(),
            'listingUrl' => $source->showUrl(),
        ]);
    }

    public function update(Request $request, string $sourceHandle, string $path)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);
        abort_if($source->readOnly(), 403);

        $repository = app(Documents::class);

        $document = $repository->find($source, $path);

        abort_unless($document, 404);

        $blueprint = $this->blueprint($source, $document);

        $fields = $blueprint->fields()->addValues($request->all());

        $fields->validate();

        $values = $fields->process()->values()->all();

        $this->renameIfNeeded($source, $document, $values['slug'] ?? null);

        $document->setData($this->dataFromValues($document->data(), $values));
        $document->setContent((string) ($values['content'] ?? ''));

        $repository->save($document);

        $source->driver()->afterSave($document);

        $fresh = $blueprint->fields()->addValues($document->values())->preProcess();

        return [
            'data' => [
                'title' => $document->title(),
                'values' => $fresh->values(),
                'permalink' => $document->url(),
                'editUrl' => $document->editUrl(),
            ],
        ];
    }

    public function destroy(string $sourceHandle, string $path)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);
        abort_if($source->readOnly(), 403);

        $repository = app(Documents::class);

        $document = $repository->find($source, $path);

        abort_unless($document, 404);

        $repository->delete($document);

        $source->driver()->afterDelete($document);

        return ['deleted' => true];
    }

    protected function blueprint(Source $source, ?Document $document = null, array $context = [])
    {
        return $source->driver()->prepareBlueprint($source->blueprint(), $document, $context);
    }

    /**
     * Merge blueprint-managed values over the existing front matter,
     * preserving any keys the blueprint doesn't know about.
     */
    protected function dataFromValues(array $existing, array $values): array
    {
        $data = $existing;

        foreach ($values as $key => $value) {
            if (in_array($key, ['slug', 'content', 'parent', 'as_section', 'url_overrides'], true)) {
                continue;
            }

            if ($key === 'url_slug') {
                if ($value === null || $value === '') {
                    unset($data['slug']);
                } else {
                    $data['slug'] = $value;
                }

                continue;
            }

            if ($this->shouldOmitValue($key, $value)) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    protected function shouldOmitValue(string $key, mixed $value): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        if (is_array($value) && $value === []) {
            return true;
        }

        if ($key === 'hidden' && $value === false) {
            return true;
        }

        if ($key === 'search' && $value === true) {
            return true;
        }

        if ($key === 'search_rank' && (float) $value === 1.0) {
            return true;
        }

        return false;
    }

    /**
     * Slug edits happen in the publish form, reparenting in the tree — never
     * the same request. A leaf renames its file; a section renames its folder
     * (bringing its children along).
     */
    protected function renameIfNeeded(Source $source, Document $document, ?string $slug): void
    {
        $slug = Str::slug((string) $slug);

        if (! $slug || $slug === $document->slug() || $document->isRoot()) {
            return;
        }

        $repository = app(Documents::class);
        $index = $source->indexFileName();

        if ($document->isIndex()) {
            $oldFolder = Str::beforeLast($document->path(), '/'.$index);
            $parent = Str::contains($oldFolder, '/') ? Str::beforeLast($oldFolder, '/').'/' : '';
            $newFolder = $parent.$slug;

            $oldAbsolute = $source->directory().'/'.$oldFolder;
            $newAbsolute = $source->directory().'/'.$newFolder;

            if (is_dir($newAbsolute) || $repository->find($source, $newFolder.'/'.$index)) {
                throw ValidationException::withMessages([
                    'slug' => __('A document with this slug already exists.'),
                ]);
            }

            app('files')->moveDirectory($oldAbsolute, $newAbsolute);

            $document->setPreviousPath($document->path())->setPath($newFolder.'/'.$index);

            return;
        }

        $folder = Str::contains($document->path(), '/') ? Str::beforeLast($document->path(), '/').'/' : '';
        $newPath = $folder.$slug;

        if ($repository->find($source, $newPath)) {
            throw ValidationException::withMessages([
                'slug' => __('A document with this slug already exists.'),
            ]);
        }

        $repository->move($document, $newPath);
    }

    /**
     * Creating a child of a leaf converts that leaf into a section index
     * so the new file can live in a real folder.
     */
    protected function convertLeafToSectionIfNeeded(Source $source, ?string $parent): void
    {
        if (! $parent || ! $source->driver()->supportsNesting()) {
            return;
        }

        $repository = app(Documents::class);
        $leaf = $repository->find($source, $parent);

        if (! $leaf || $leaf->isIndex()) {
            return;
        }

        $index = $source->indexFileName();
        $repository->move($leaf, $parent.'/'.$index);
    }

    protected function proposedPath(Source $source, ?string $parent, string $slug, bool $asSection): string
    {
        $folder = $parent ? trim($parent, '/') : '';
        $base = $folder === '' ? $slug : $folder.'/'.$slug;

        return $asSection ? $base.'/'.$source->indexFileName() : $base;
    }

    protected function folderFromParent(Source $source, ?string $parent): ?string
    {
        $parent = trim((string) $parent, '/');

        if (Str::startsWith($parent, '_folder::')) {
            $parent = Str::after($parent, '_folder::');
        }

        if ($parent === '' || Str::contains($parent, '..')) {
            return null;
        }

        $index = $source->indexFileName();

        if ($parent === $index) {
            return null;
        }

        if (Str::endsWith($parent, '/'.$index)) {
            return Str::beforeLast($parent, '/'.$index);
        }

        return $parent;
    }
}
