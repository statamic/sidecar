<?php

namespace Statamic\Sidecar\Drivers\Jigsaw;

use Facades\Statamic\CP\LivePreview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Statamic\Facades\Markdown;
use Statamic\Sidecar\Document;

class LivePreviewController
{
    public function __invoke(Request $request): View
    {
        $token = $request->statamicToken();
        abort_unless($token, 404);

        $document = LivePreview::item($token);
        abort_unless($document instanceof Document, 404);

        $driver = $document->source()->driver();
        abort_unless($driver instanceof JigsawDriver, 404);

        $title = (string) ($document->value('title') ?: $document->slug());
        $content = (string) ($document->value('content') ?? '');

        return view('sidecar::jigsaw-live-preview', [
            'title' => $title,
            'html' => Markdown::parse($content),
            'navigation' => NavigationFile::load($driver->navigationPath()),
            'document' => $document,
            'urlPrefix' => $driver->urlPrefix(),
        ]);
    }
}
