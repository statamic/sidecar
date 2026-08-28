<?php

namespace Statamic\Sidecar\Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Drivers\VitePress\SidebarFile;
use Statamic\Sidecar\Drivers\VitePress\VitePressDriver;
use Statamic\Sidecar\Source;

class VitePressDriverTest extends TestCase
{
    private Source $source;

    private string $sidebarPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sidebarPath = $this->fixturesDir.'/docs/.vitepress/sidebar.json';

        $this->source = $this->configureSource('docs', [
            'driver' => 'vitepress',
            'directory' => $this->fixturesDir.'/docs',
            'sidebar' => $this->sidebarPath,
            'site_url' => 'http://vitepress.test',
            'preview_url' => 'http://localhost:5173{path}',
        ]);
    }

    private function dir(): string
    {
        return $this->source->directory();
    }

    private function writeSidebar(array $sidebar): void
    {
        SidebarFile::write($this->sidebarPath, $sidebar);
    }

    private function sidebar(): array
    {
        return SidebarFile::load($this->sidebarPath);
    }

    #[Test]
    public function the_tree_is_a_projection_of_sidebar_json()
    {
        $this->makeDoc($this->dir(), 'guide/getting-started.md', ['title' => 'Getting Started']);
        $this->makeDoc($this->dir(), 'guide/configuration.md', ['title' => 'Configuration']);

        $this->writeSidebar([
            [
                'text' => 'Guide',
                'items' => [
                    ['text' => 'Getting Started', 'link' => '/guide/getting-started'],
                    ['text' => 'Configuration', 'link' => '/guide/configuration'],
                ],
            ],
            ['text' => 'GitHub', 'link' => 'https://github.com/example'],
        ]);

        $tree = $this->source->driver()->tree($this->source);

        $this->assertCount(1, $tree);
        $this->assertEquals(VitePressDriver::VIRTUAL_PREFIX.'Guide', $tree[0]['id']);
        $this->assertEquals('guide/getting-started', $tree[0]['children'][0]['id']);
        $this->assertEquals('guide/configuration', $tree[0]['children'][1]['id']);
    }

    #[Test]
    public function without_a_sidebar_file_the_tree_is_a_flat_listing()
    {
        $this->makeDoc($this->dir(), 'bravo.md', ['title' => 'Bravo']);
        $this->makeDoc($this->dir(), 'alpha.md', ['title' => 'Alpha']);

        $tree = $this->source->driver()->tree($this->source);

        $this->assertEquals(['alpha', 'bravo'], collect($tree)->pluck('id')->all());
    }

    #[Test]
    public function unlisted_docs_are_appended_to_the_tree()
    {
        $this->makeDoc($this->dir(), 'listed.md', ['title' => 'Listed']);
        $this->makeDoc($this->dir(), 'zebra.md', ['title' => 'Zebra']);
        $this->makeDoc($this->dir(), 'alpha.md', ['title' => 'Alpha']);

        $this->writeSidebar([
            ['text' => 'Listed', 'link' => '/listed'],
        ]);

        $tree = $this->source->driver()->tree($this->source);

        $this->assertEquals(['listed', 'alpha', 'zebra'], collect($tree)->pluck('id')->all());
    }

    #[Test]
    public function saving_the_tree_rewrites_sidebar_json_and_preserves_external_links()
    {
        $this->makeDoc($this->dir(), 'getting-started.md', ['title' => 'Getting Started']);
        $this->makeDoc($this->dir(), 'configuration.md', ['title' => 'Configuration']);

        $this->writeSidebar([
            ['text' => 'Getting Started', 'link' => '/getting-started'],
            ['text' => 'Configuration', 'link' => '/configuration'],
            ['text' => 'GitHub', 'link' => 'https://github.com/example'],
        ]);

        $this->source->driver()->saveTree($this->source, [
            ['id' => 'configuration', 'children' => [
                ['id' => 'getting-started', 'children' => []],
            ]],
        ]);

        $sidebar = $this->sidebar();

        $this->assertEquals('Configuration', $sidebar[0]['text']);
        $this->assertEquals('/configuration', $sidebar[0]['link']);
        $this->assertEquals([
            ['text' => 'Getting Started', 'link' => '/getting-started'],
        ], $sidebar[0]['items']);

        $this->assertEquals(['text' => 'GitHub', 'link' => 'https://github.com/example'], $sidebar[1]);
        $this->assertFileDoesNotExist($this->dir().'/configuration/getting-started.md');
    }

    #[Test]
    public function saving_the_tree_drops_dangling_doc_links_and_keeps_collapsed()
    {
        $this->makeDoc($this->dir(), 'keep.md', ['title' => 'Keep']);

        $this->writeSidebar([
            ['text' => 'Keep', 'link' => '/keep', 'collapsed' => true],
            ['text' => 'Gone', 'link' => '/gone'],
            ['text' => 'Packages', 'link' => '/packages/[pkg]'],
        ]);

        $this->source->driver()->saveTree($this->source, [
            ['id' => 'keep', 'children' => []],
        ]);

        $sidebar = $this->sidebar();

        $this->assertEquals('Keep', $sidebar[0]['text']);
        $this->assertTrue($sidebar[0]['collapsed']);
        $this->assertEquals(['text' => 'Packages', 'link' => '/packages/[pkg]'], $sidebar[1]);
        $this->assertCount(2, $sidebar);
    }

    #[Test]
    public function first_tree_save_creates_sidebar_json()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page']);

        $this->assertFileDoesNotExist($this->sidebarPath);

        $this->source->driver()->saveTree($this->source, [
            ['id' => 'page', 'children' => []],
        ]);

        $this->assertEquals([
            ['text' => 'Page', 'link' => '/page'],
        ], $this->sidebar());
    }

    #[Test]
    public function multi_sidebar_is_scoped_to_sidebar_key()
    {
        $this->makeDoc($this->dir(), 'guide/getting-started.md', ['title' => 'Getting Started']);
        $this->makeDoc($this->dir(), 'api/reference.md', ['title' => 'Reference']);

        $this->writeSidebar([
            '/guide/' => [
                ['text' => 'Getting Started', 'link' => '/guide/getting-started'],
            ],
            '/api/' => [
                ['text' => 'Reference', 'link' => '/api/reference'],
            ],
        ]);

        $this->source = $this->configureSource('docs', [
            'driver' => 'vitepress',
            'directory' => $this->dir(),
            'sidebar' => $this->sidebarPath,
            'sidebar_key' => '/guide/',
            'site_url' => 'http://vitepress.test',
        ]);

        $tree = $this->source->driver()->tree($this->source);

        $this->assertEquals(['guide/getting-started', 'api/reference'], collect($tree)->pluck('id')->all());

        $this->source->driver()->saveTree($this->source, [
            ['id' => 'guide/getting-started', 'children' => []],
        ]);

        $sidebar = $this->sidebar();

        $this->assertEquals([
            ['text' => 'Getting Started', 'link' => '/guide/getting-started'],
        ], $sidebar['/guide/']);
        $this->assertEquals([
            ['text' => 'Reference', 'link' => '/api/reference'],
        ], $sidebar['/api/']);
    }

    #[Test]
    public function after_save_refreshes_sidebar_labels()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Old Title']);
        $this->writeSidebar([
            ['text' => 'Old Title', 'link' => '/page'],
        ]);

        $repo = app(Documents::class);
        $document = $repo->find($this->source, 'page');

        $document->set('title', 'New Title');
        $repo->save($document);

        $this->source->driver()->afterSave($document);

        $this->assertEquals([
            ['text' => 'New Title', 'link' => '/page'],
        ], $this->sidebar());
    }

    #[Test]
    public function renaming_a_document_patches_sidebar_links()
    {
        $this->makeDoc($this->dir(), 'old-slug.md', ['title' => 'Page']);
        $this->writeSidebar([
            ['text' => 'Page', 'link' => '/old-slug'],
        ]);

        $repo = app(Documents::class);
        $document = $repo->find($this->source, 'old-slug');

        $repo->move($document, 'new-slug');
        $repo->save($document);

        $this->source->driver()->afterSave($document);

        $this->assertEquals([
            ['text' => 'Page', 'link' => '/new-slug'],
        ], $this->sidebar());
    }

    #[Test]
    public function deleting_a_document_drops_it_from_the_sidebar()
    {
        $this->makeDoc($this->dir(), 'keep.md', ['title' => 'Keep']);
        $this->makeDoc($this->dir(), 'gone.md', ['title' => 'Gone']);
        $this->writeSidebar([
            ['text' => 'Keep', 'link' => '/keep'],
            ['text' => 'Gone', 'link' => '/gone'],
        ]);

        $repo = app(Documents::class);
        $document = $repo->find($this->source, 'gone');
        $repo->delete($document);

        $this->source->driver()->afterDelete($document);

        $this->assertEquals([
            ['text' => 'Keep', 'link' => '/keep'],
        ], $this->sidebar());
    }

    #[Test]
    public function documents_have_site_urls_and_a_dev_server_preview_target()
    {
        $this->makeDoc($this->dir(), 'guide/page.md', ['title' => 'Page']);
        $this->makeDoc($this->dir(), 'guide/index.md', ['title' => 'Guide']);
        $this->makeDoc($this->dir(), 'index.md', ['title' => 'Home']);

        $repo = app(Documents::class);

        $this->assertEquals('http://vitepress.test/guide/page', $repo->find($this->source, 'guide/page')->url());
        $this->assertEquals('http://vitepress.test/guide/', $repo->find($this->source, 'guide/index')->url());
        $this->assertEquals('http://vitepress.test/', $repo->find($this->source, 'index')->url());

        $targets = $repo->find($this->source, 'guide/page')->previewTargets();
        $this->assertCount(1, $targets);
        $this->assertEquals('http://localhost:5173/guide/page', $targets[0]['url']);
        $this->assertEquals('VitePress', $targets[0]['label']);
    }

    #[Test]
    public function urls_respect_base_and_html_suffixes()
    {
        $this->source = $this->configureSource('docs', [
            'driver' => 'vitepress',
            'directory' => $this->dir(),
            'sidebar' => $this->sidebarPath,
            'site_url' => 'http://vitepress.test',
            'base' => '/docs/',
            'clean_urls' => false,
        ]);

        $this->makeDoc($this->dir(), 'guide/page.md', ['title' => 'Page']);
        $this->makeDoc($this->dir(), 'guide/index.md', ['title' => 'Guide']);
        $this->makeDoc($this->dir(), 'index.md', ['title' => 'Home']);

        $repo = app(Documents::class);

        $this->assertEquals('http://vitepress.test/docs/guide/page.html', $repo->find($this->source, 'guide/page')->url());
        $this->assertEquals('http://vitepress.test/docs/guide/index.html', $repo->find($this->source, 'guide/index')->url());
        $this->assertEquals('http://vitepress.test/docs/index.html', $repo->find($this->source, 'index')->url());
    }

    #[Test]
    public function vitepress_reorders_but_never_nests_files()
    {
        $driver = $this->source->driver();

        $this->assertFalse($driver->supportsNesting());
        $this->assertTrue($driver->supportsOrdering());
        $this->assertFalse($driver->expectsRoot());
        $this->assertEquals('index', $driver->indexFileName());
    }

    #[Test]
    public function sidebar_file_resolves_html_suffixes_and_index_links()
    {
        $this->assertEquals('guide/routing', SidebarFile::pathFromLink('/guide/routing.html'));
        $this->assertEquals('guide/index', SidebarFile::pathFromLink('/guide/'));
        $this->assertEquals('index', SidebarFile::pathFromLink('/'));
        $this->assertEquals('guide/routing', SidebarFile::pathFromLink('/docs/guide/routing', 'docs'));
        $this->assertEquals('/guide/', SidebarFile::linkFromPath('guide/index'));
        $this->assertNull(SidebarFile::pathFromLink('https://github.com/example'));
        $this->assertNull(SidebarFile::pathFromLink('/packages/[pkg]'));
    }
}
