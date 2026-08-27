<?php

namespace Statamic\Sidecar\Tests;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Source;

class DocumentsTest extends TestCase
{
    private Source $source;

    private Documents $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = $this->configureSource('docs');
        $this->repo = app(Documents::class);
    }

    private function dir(): string
    {
        return $this->source->directory();
    }

    #[Test]
    public function it_lists_all_documents_keyed_by_path()
    {
        $this->makeDoc($this->dir(), '_index.md', ['title' => 'Home']);
        $this->makeDoc($this->dir(), 'installation.md', ['title' => 'Installation']);
        $this->makeDoc($this->dir(), 'guide/_index.md', ['title' => 'Guide']);
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing']);
        File::put($this->dir().'/ignored.txt', 'nope');

        $all = $this->repo->all($this->source);

        $this->assertEquals(
            ['_index', 'guide/_index', 'guide/routing', 'installation'],
            $all->keys()->all()
        );
    }

    #[Test]
    public function it_resolves_paths_returned_relative_to_the_project_root()
    {
        $directory = base_path('content/sidecar-docs');
        File::ensureDirectoryExists($directory);

        $this->source = $this->configureSource('docs', ['directory' => $directory]);
        $this->repo = app(Documents::class);

        $this->makeDoc($directory, 'page.md', ['title' => 'Page']);

        $all = $this->repo->all($this->source);

        $this->assertEquals(['page'], $all->keys()->all());
        $this->assertNotNull($this->repo->find($this->source, 'page'));
    }

    #[Test]
    public function it_skips_laradocs_ignored_patterns()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page']);
        $this->makeDoc($this->dir(), 'README.md', ['title' => 'Readme']);
        $this->makeDoc($this->dir(), '_drafts/wip.md', ['title' => 'Draft']);

        $all = $this->repo->all($this->source);

        $this->assertEquals(['page'], $all->keys()->all());
    }

    #[Test]
    public function it_finds_a_document_and_parses_front_matter()
    {
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing', 'order' => 2], "# Routing\n");

        $document = $this->repo->find($this->source, 'guide/routing');

        $this->assertNotNull($document);
        $this->assertEquals('Routing', $document->title());
        $this->assertEquals(2, $document->get('order'));
        $this->assertEquals("# Routing\n", $document->content());
        $this->assertEquals('routing', $document->slug());
        $this->assertEquals(['guide'], $document->ancestry());
        $this->assertEquals('guide/routing', $document->uriPath());
    }

    #[Test]
    public function it_handles_documents_without_front_matter()
    {
        File::put($this->dir().'/plain.md', "# Just Markdown\n");

        $document = $this->repo->find($this->source, 'plain');

        $this->assertEquals([], $document->data());
        $this->assertEquals("# Just Markdown\n", $document->content());
    }

    #[Test]
    public function it_returns_null_for_missing_or_traversal_paths()
    {
        $this->assertNull($this->repo->find($this->source, 'nope'));
        $this->assertNull($this->repo->find($this->source, '../secrets'));
        $this->assertNull($this->repo->find($this->source, ''));
    }

    #[Test]
    public function saving_preserves_unknown_front_matter_keys()
    {
        $this->makeDoc($this->dir(), 'page.md', [
            'title' => 'Page',
            'custom_key' => 'kept',
            'another' => 'also kept',
        ], "Body\n");

        $document = $this->repo->find($this->source, 'page');
        $document->set('title', 'Updated Page');
        $this->repo->save($document);

        $fresh = $this->repo->find($this->source, 'page');

        $this->assertEquals('Updated Page', $fresh->title());
        $this->assertEquals('kept', $fresh->get('custom_key'));
        $this->assertEquals('also kept', $fresh->get('another'));
        $this->assertEquals("Body\n", $fresh->content());
    }

    #[Test]
    public function it_moves_a_document_and_remembers_the_previous_path()
    {
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing']);

        $document = $this->repo->find($this->source, 'guide/routing');
        $this->repo->move($document, 'advanced/routing');

        $this->assertEquals('advanced/routing', $document->path());
        $this->assertEquals('guide/routing', $document->previousPath());
        $this->assertFileExists($this->dir().'/advanced/routing.md');
        $this->assertFileDoesNotExist($this->dir().'/guide/routing.md');

        // The now-empty guide directory is cleaned up.
        $this->assertDirectoryDoesNotExist($this->dir().'/guide');
    }

    #[Test]
    public function it_deletes_a_document_and_cleans_empty_directories()
    {
        $this->makeDoc($this->dir(), 'guide/deep/page.md', ['title' => 'Page']);

        $document = $this->repo->find($this->source, 'guide/deep/page');
        $this->repo->delete($document);

        $this->assertFileDoesNotExist($this->dir().'/guide/deep/page.md');
        $this->assertDirectoryDoesNotExist($this->dir().'/guide/deep');
        $this->assertDirectoryDoesNotExist($this->dir().'/guide');
        $this->assertDirectoryExists($this->dir());
    }

    #[Test]
    public function empty_front_matter_writes_a_bare_file()
    {
        $document = $this->repo->make($this->source, 'bare', [], "Just content\n");
        $this->repo->save($document);

        $this->assertEquals("Just content\n", File::get($this->dir().'/bare.md'));
    }
}
