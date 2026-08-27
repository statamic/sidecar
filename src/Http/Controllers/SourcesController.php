<?php

namespace Statamic\Sidecar\Http\Controllers;

use Inertia\Inertia;
use Statamic\CP\Column;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Sidecar\Source;

class SourcesController extends CpController
{
    public function index()
    {
        $source = Sidecar::sources()->first();

        abort_unless($source, 404);

        return redirect($source->showUrl());
    }

    public function show(string $sourceHandle)
    {
        $source = Sidecar::source($sourceHandle);

        abort_unless($source, 404);

        $documents = app(Documents::class)->all($source);

        return Inertia::render('sidecar/Show', [
            'handle' => $source->handle(),
            'title' => $source->title(),
            'readOnly' => $source->readOnly(),
            'expectsRoot' => $source->expectsRoot() && $documents->has($source->indexFileName()),
            'structured' => $source->driver()->supportsNesting() || $source->driver()->supportsOrdering(),
            'supportsNesting' => $source->driver()->supportsNesting(),
            'supportsOrdering' => $source->driver()->supportsOrdering(),
            'treeIndexUrl' => $source->treeIndexUrl(),
            'treeSubmitUrl' => $source->treeSubmitUrl(),
            'createUrl' => $source->createUrl(),
            'editBlueprintUrl' => $source->editBlueprintUrl(),
            'directory' => $source->directory(),
            'columns' => [
                Column::make('title')->label(__('Title')),
                Column::make('path')->label(__('Path')),
                Column::make('group')->label(__('Group')),
                Column::make('hidden')->label(__('Hidden')),
            ],
            'rows' => $documents
                ->map(fn (Document $document) => [
                    'id' => $document->path(),
                    'title' => $document->title(),
                    'path' => $document->path().'.'.$source->extension(),
                    'group' => $document->get('group'),
                    'hidden' => (bool) $document->get('hidden'),
                    'edit_url' => $document->editUrl(),
                    'delete_url' => $document->deleteUrl(),
                ])
                ->values()
                ->all(),
            'sources' => Sidecar::sources()
                ->map(fn (Source $other) => [
                    'handle' => $other->handle(),
                    'title' => $other->title(),
                    'url' => $other->showUrl(),
                    'active' => $other->handle() === $source->handle(),
                ])
                ->all(),
        ]);
    }
}
