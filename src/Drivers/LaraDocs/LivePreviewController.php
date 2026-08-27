<?php

namespace Statamic\Sidecar\Drivers\LaraDocs;

use Facades\Statamic\CP\LivePreview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laradocs\Contracts\DocumentParser;
use Laradocs\Documents\Document;
use Laradocs\Documents\DocumentCollection;
use Laradocs\Documents\DocumentTree;
use Laradocs\Laradocs;
use Laradocs\Metadata\Metadata;
use Laradocs\Seo\SeoFactory;
use Laradocs\Support\Config;
use Laradocs\Support\Navigation;
use Laradocs\Toc\TableOfContents;
use RalphJSmit\Laravel\SEO\Support\SEOData;
use Statamic\Sidecar\Document as SidecarDocument;

class LivePreviewController
{
    public function __invoke(Request $request): View
    {
        abort_unless(class_exists(Laradocs::class), 404);

        $token = $request->statamicToken();
        abort_unless($token, 404);

        $document = LivePreview::item($token);
        abort_unless($document instanceof SidecarDocument, 404);
        abort_unless($document->source()->driver() instanceof LaraDocsDriver, 404);

        return $this->view($this->laradocsDocument($document));
    }

    private function laradocsDocument(SidecarDocument $document): Document
    {
        $slug = $document->value('url_slug') ?: $document->uriPath();
        $markdown = (string) ($document->value('content') ?? '');
        $relativePath = $document->path().'.'.$document->source()->extension();

        $metadata = Metadata::fromArray([
            'title' => $document->value('title'),
            'description' => $document->value('description'),
            'slug' => $document->value('url_slug'),
            'order' => $document->value('order') ?? PHP_INT_MAX,
            'hidden' => (bool) ($document->value('hidden') ?? false),
            'group' => $document->value('group'),
            'badge' => $document->value('badge'),
            'icon' => $document->value('icon'),
            'tags' => $document->value('tags') ?? [],
            'author' => $document->value('author'),
            'layout' => $document->value('layout'),
            'image' => $document->value('image'),
            'redirect' => $document->value('redirect'),
            'search' => $document->value('search') ?? true,
            'search_rank' => $document->value('search_rank') ?? 1.0,
        ]);

        $laradocsDocument = new Document(
            path: 'sidecar-live-preview://'.$document->path(),
            relativePath: $relativePath,
            slug: $slug,
            metadata: $metadata,
            markdown: $markdown,
            modifiedAt: time(),
        );

        return $laradocsDocument->withHtml(
            app(DocumentParser::class)->parse($markdown)
        );
    }

    private function view(Document $document): View
    {
        $laradocs = app(Laradocs::class);

        $tree = $this->treeWithOverlay($laradocs, $document);
        $navigation = $tree->navigation();
        [$previous, $next] = Navigation::siblings($navigation, $document->slug);

        $toc = TableOfContents::fromHtml(
            $document->html ?? '',
            Config::int('laradocs.parser.toc.min_level', 2),
            Config::int('laradocs.parser.toc.max_level', 3),
        );

        $breadcrumbs = Navigation::breadcrumbs($navigation, $document->slug);

        $seoEnabled = $this->seoEnabled();
        $seo = $seoEnabled ? app(SeoFactory::class)->forDocument($document, $breadcrumbs) : null;

        return view('laradocs::show', [
            'document' => $document,
            'tree' => $tree,
            'activeSlug' => $document->slug,
            'navigation' => $navigation,
            'breadcrumbs' => $breadcrumbs,
            'previous' => $previous,
            'next' => $next,
            'toc' => $toc,
            'variables' => $laradocs->variableValues(),
            'seo' => $seo,
            'xCard' => $seoEnabled ? app(SeoFactory::class)->xCard() : null,
        ]);
    }

    /**
     * Rebuild the nav tree with the WIP document swapped in so title/group/
     * hidden/new pages show up in the sidebar before they're saved.
     */
    private function treeWithOverlay(Laradocs $laradocs, Document $wip): DocumentTree
    {
        $documents = $laradocs->all()->map(
            fn (Document $doc): Document => $doc->slug === $wip->slug ? $wip : $doc
        );

        if (! $documents->contains(fn (Document $doc): bool => $doc->slug === $wip->slug)) {
            $documents = $documents->push($wip);
        }

        return DocumentTree::fromDocuments(
            new DocumentCollection($documents->all()),
            Config::string('laradocs.docs.index', '_index'),
        );
    }

    private function seoEnabled(): bool
    {
        return Config::bool('laradocs.seo.enabled', true)
            && class_exists(SEOData::class);
    }
}
