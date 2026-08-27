<?php

namespace Statamic\Sidecar\Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Drivers\Jigsaw\NavigationFile;
use Statamic\Sidecar\Source;

class JigsawDriverTest extends TestCase
{
    private Source $source;

    private string $navPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->navPath = $this->fixturesDir.'/navigation.php';

        $this->source = $this->configureSource('docs', [
            'driver' => 'jigsaw',
            'directory' => $this->fixturesDir.'/source/docs',
            'navigation' => $this->navPath,
            'url_prefix' => 'docs',
            'site_url' => 'http://jigsaw.test',
        ]);
    }

    private function dir(): string
    {
        return $this->source->directory();
    }

    private function writeNav(array $nav): void
    {
        NavigationFile::write($this->navPath, $nav);
    }

    private function nav(): array
    {
        return NavigationFile::load($this->navPath);
    }

    #[Test]
    public function the_tree_is_a_projection_of_navigation_php()
    {
        $this->makeDoc($this->dir(), 'getting-started.md', ['title' => 'Getting Started']);
        $this->makeDoc($this->dir(), 'navigation.md', ['title' => 'Navigation']);

        $this->writeNav([
            'Getting Started' => [
                'url' => 'docs/getting-started',
                'children' => [
                    'Navigation' => 'docs/navigation',
                ],
            ],
            'GitHub' => 'https://github.com/example',
        ]);

        $tree = $this->source->driver()->tree($this->source);

        $this->assertCount(1, $tree); // the external link isn't editable content
        $this->assertEquals('getting-started', $tree[0]['id']);
        $this->assertEquals('navigation', $tree[0]['children'][0]['id']);
    }

    #[Test]
    public function without_a_navigation_file_the_tree_is_a_flat_listing()
    {
        $this->makeDoc($this->dir(), 'bravo.md', ['title' => 'Bravo']);
        $this->makeDoc($this->dir(), 'alpha.md', ['title' => 'Alpha']);

        $tree = $this->source->driver()->tree($this->source);

        $this->assertEquals(['alpha', 'bravo'], collect($tree)->pluck('id')->all());
    }

    #[Test]
    public function saving_the_tree_rewrites_navigation_php_and_preserves_external_links()
    {
        $this->makeDoc($this->dir(), 'getting-started.md', ['title' => 'Getting Started']);
        $this->makeDoc($this->dir(), 'navigation.md', ['title' => 'Navigation']);

        $this->writeNav([
            'Getting Started' => 'docs/getting-started',
            'Navigation' => 'docs/navigation',
            'GitHub' => 'https://github.com/example',
        ]);

        $driver = $this->source->driver();

        $driver->saveTree($this->source, [
            ['id' => 'navigation', 'children' => [
                ['id' => 'getting-started', 'children' => []],
            ]],
        ]);

        $nav = $this->nav();

        $this->assertEquals([
            'url' => 'docs/navigation',
            'children' => ['Getting Started' => 'docs/getting-started'],
        ], $nav['Navigation']);

        $this->assertEquals('https://github.com/example', $nav['GitHub']);
        $this->assertFileDoesNotExist($this->dir().'/navigation/getting-started.md'); // files never move
    }

    #[Test]
    public function after_save_fills_default_front_matter_and_refreshes_nav_labels()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Old Title']);
        $this->writeNav(['Old Title' => 'docs/page']);

        $repo = app(Documents::class);
        $document = $repo->find($this->source, 'page');

        $document->set('title', 'New Title');
        $repo->save($document);

        $this->source->driver()->afterSave($document);

        $fresh = $repo->find($this->source, 'page');
        $this->assertEquals('_layouts.documentation', $fresh->get('extends'));
        $this->assertEquals('content', $fresh->get('section'));

        $nav = $this->nav();
        $this->assertArrayHasKey('New Title', $nav);
        $this->assertArrayNotHasKey('Old Title', $nav);
    }

    #[Test]
    public function renaming_a_document_patches_navigation_urls()
    {
        $this->makeDoc($this->dir(), 'old-slug.md', ['title' => 'Page']);
        $this->writeNav(['Page' => 'docs/old-slug']);

        $repo = app(Documents::class);
        $document = $repo->find($this->source, 'old-slug');

        $repo->move($document, 'new-slug');
        $repo->save($document);

        $this->source->driver()->afterSave($document);

        $this->assertEquals(['Page' => 'docs/new-slug'], $this->nav());
    }

    #[Test]
    public function deleting_a_document_drops_it_from_navigation()
    {
        $this->makeDoc($this->dir(), 'keep.md', ['title' => 'Keep']);
        $this->makeDoc($this->dir(), 'gone.md', ['title' => 'Gone']);
        $this->writeNav(['Keep' => 'docs/keep', 'Gone' => 'docs/gone']);

        $repo = app(Documents::class);
        $document = $repo->find($this->source, 'gone');
        $repo->delete($document);

        $this->source->driver()->afterDelete($document);

        $this->assertEquals(['Keep' => 'docs/keep'], $this->nav());
    }

    #[Test]
    public function documents_have_site_urls_and_a_live_preview_target()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page']);

        $document = app(Documents::class)->find($this->source, 'page');

        $this->assertEquals('http://jigsaw.test/docs/page', $document->url());

        $targets = $document->previewTargets();
        $this->assertCount(1, $targets);
        $this->assertStringContainsString('sidecar/jigsaw/live-preview', $targets[0]['url']);
    }

    #[Test]
    public function jigsaw_reorders_but_never_nests_files()
    {
        $driver = $this->source->driver();

        $this->assertFalse($driver->supportsNesting());
        $this->assertTrue($driver->supportsOrdering());
        $this->assertFalse($driver->expectsRoot());
    }

    #[Test]
    public function the_live_preview_endpoint_renders_wip_content()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page'], "Saved\n");
        $this->writeNav(['Page' => 'docs/page']);

        $this->actingAsSuper();

        $token = $this
            ->postJson(cp_route('sidecar.documents.preview.edit', ['docs', 'page']), [
                'preview' => ['title' => 'WIP Title', 'content' => "# WIP Heading\n"],
            ])
            ->assertOk()
            ->json('token');

        auth()->logout();

        $this
            ->get('/!/sidecar/jigsaw/live-preview?token='.$token.'&live-preview=1')
            ->assertOk()
            ->assertSee('WIP Title')
            ->assertSee('WIP Heading');
    }
}
