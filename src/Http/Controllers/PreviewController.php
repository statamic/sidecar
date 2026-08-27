<?php

namespace Statamic\Sidecar\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\PreviewController as CorePreviewController;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Sidecar\Source;
use Statamic\Support\Arr;
use Statamic\Support\Str;

class PreviewController extends CorePreviewController
{
    /**
     * Tokenize a document with the WIP form values applied as supplements.
     * The core controller's `edit` is entry-flavored (it authorizes against
     * a policy Documents don't have), so this is our equivalent.
     */
    public function document(Request $request, string $sourceHandle, string $path)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);

        $document = app(Documents::class)->find($source, $path);

        abort_unless($document, 404);

        $this->applyPreviewValues($source, $document, $request->input('preview', []));

        return $this->tokenizeAndReturn($request, $document);
    }

    /**
     * Tokenize an unsaved document from create-form values.
     */
    public function create(Request $request, string $sourceHandle)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);

        $preview = $request->input('preview', []);
        $parent = $this->folderFromParent($source, $preview['parent'] ?? null);
        $asSection = filter_var($preview['as_section'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $slug = Str::slug($preview['slug'] ?? '') ?: Str::slug($preview['title'] ?? '') ?: 'new-document';

        $path = $this->proposedPath($source, $parent, $slug, $asSection);

        $document = app(Documents::class)->make($source, $path);

        $this->applyPreviewValues($source, $document, $preview);

        return $this->tokenizeAndReturn($request, $document);
    }

    protected function applyPreviewValues(Source $source, $document, array $preview): void
    {
        $fields = $source->blueprint()
            ->fields()
            ->addValues($preview)
            ->process();

        foreach (Arr::except($fields->values()->all(), ['slug', 'parent', 'as_section']) as $key => $value) {
            if ($key === 'url_slug') {
                $document->setSupplement('url_slug', $value);
                $document->set('slug', $value);

                continue;
            }

            $document->setSupplement($key, $value);
        }
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
