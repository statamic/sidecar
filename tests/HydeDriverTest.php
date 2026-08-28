<?php

namespace Statamic\Sidecar\Tests;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\YAML;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Source;
use Statamic\Sidecar\Tree\DerivedTree;

class HydeDriverTest extends TestCase
{
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = $this->configureSource('docs', [
            'driver' => 'hyde',
            'directory' => $this->fixturesDir.'/docs',
            'site_url' => 'http://hyde.test',
            'preview_url' => 'http://localhost:8080/docs{path}',
        ]);
    }

    private function dir(): string
    {
        return $this->source->directory();
    }

    private function makeHydeDoc(string $relativePath, array $data = [], string $content = ''): void
    {
        $path = rtrim($this->dir(), '/').'/'.$relativePath;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, YAML::dumpFrontMatter($data, $content));
    }

    #[Test]
    public function it_nests_from_folders_and_sorts_by_navigation_priority()
    {
        $this->makeHydeDoc('index.md', ['title' => 'Home']);
        $this->makeHydeDoc('zebra.md', ['title' => 'Zebra', 'navigation' => ['priority' => 2]]);
        $this->makeHydeDoc('apple.md', ['title' => 'Apple', 'navigation' => ['priority' => 1]]);
        $this->makeHydeDoc('guide/routing.md', ['title' => 'Routing', 'navigation' => ['priority' => 1]]);

        $tree = $this->source->driver()->tree($this->source);

        $this->assertEquals('index', $tree[0]['id']);
        $this->assertEquals(['apple', 'zebra', DerivedTree::VIRTUAL_PREFIX.'guide'], collect($tree)->pluck('id')->slice(1)->values()->all());
        $this->assertEquals('guide/routing', $tree[3]['children'][0]['id']);
    }

    #[Test]
    public function numeric_filename_prefixes_set_priority()
    {
        $this->makeHydeDoc('index.md', ['title' => 'Home']);
        $this->makeHydeDoc('02-zebra.md', ['title' => 'Zebra']);
        $this->makeHydeDoc('01-apple.md', ['title' => 'Apple']);

        $ids = collect($this->source->driver()->tree($this->source))->pluck('id')->all();

        $this->assertEquals(['index', '01-apple', '02-zebra'], $ids);
    }

    #[Test]
    public function saving_the_tree_moves_files_and_writes_navigation_priority()
    {
        $this->makeHydeDoc('index.md', ['title' => 'Home']);
        $this->makeHydeDoc('apple.md', ['title' => 'Apple']);
        $this->makeHydeDoc('zebra.md', ['title' => 'Zebra']);

        $this->source->driver()->saveTree($this->source, [
            ['id' => 'index', 'children' => []],
            ['id' => 'zebra', 'children' => [
                ['id' => 'apple', 'children' => []],
            ]],
        ]);

        $this->assertFileExists($this->dir().'/zebra/index.md');
        $this->assertFileExists($this->dir().'/zebra/apple.md');
        $this->assertFileDoesNotExist($this->dir().'/apple.md');

        $zebra = YAML::parse(File::get($this->dir().'/zebra/index.md'));
        $apple = YAML::parse(File::get($this->dir().'/zebra/apple.md'));

        $this->assertEquals(1, $zebra['navigation']['priority']);
        $this->assertEquals(1, $apple['navigation']['priority']);
        $this->assertArrayNotHasKey('order', $zebra);
    }

    #[Test]
    public function documents_have_flattened_docs_urls_and_a_dev_server_preview()
    {
        $this->makeHydeDoc('index.md', ['title' => 'Home']);
        $this->makeHydeDoc('guide/01-routing.md', ['title' => 'Routing']);

        $repo = app(Documents::class);

        $this->assertEquals('http://hyde.test/docs/', $repo->find($this->source, 'index')->url());
        $this->assertEquals('http://hyde.test/docs/routing', $repo->find($this->source, 'guide/01-routing')->url());

        $targets = $repo->find($this->source, 'guide/01-routing')->previewTargets();
        $this->assertEquals('http://localhost:8080/docs/routing', $targets[0]['url']);
        $this->assertEquals('HydePHP', $targets[0]['label']);
    }

    #[Test]
    public function nested_urls_keep_folders_when_flattening_is_off()
    {
        $this->source = $this->configureSource('docs', [
            'driver' => 'hyde',
            'directory' => $this->dir(),
            'site_url' => 'http://hyde.test',
            'flattened' => false,
        ]);

        $this->makeHydeDoc('guide/routing.md', ['title' => 'Routing']);

        $this->assertEquals(
            'http://hyde.test/docs/guide/routing',
            app(Documents::class)->find($this->source, 'guide/routing')->url()
        );
    }

    #[Test]
    public function hyde_nests_and_orders_from_the_filesystem()
    {
        $driver = $this->source->driver();

        $this->assertTrue($driver->supportsNesting());
        $this->assertTrue($driver->supportsOrdering());
        $this->assertTrue($driver->expectsRoot());
        $this->assertEquals('index', $driver->indexFileName());
    }

    #[Test]
    public function create_blueprint_includes_hidden_parent_fields()
    {
        $blueprint = $this->source->driver()->prepareBlueprint($this->source->blueprint());

        $this->assertEquals('hidden', $blueprint->field('parent')->type());
        $this->assertEquals('hidden', $blueprint->field('as_section')->type());
    }

    #[Test]
    public function storing_a_child_writes_under_the_parent_and_uses_index_md()
    {
        $this->actingAsSuper();
        $this->makeHydeDoc('getting-started.md', ['title' => 'Getting Started']);

        $this
            ->postJson(cp_route('sidecar.documents.store', 'docs'), [
                'title' => 'Routing',
                'slug' => 'routing',
                'parent' => 'getting-started',
            ])
            ->assertOk();

        $this->assertFileExists($this->dir().'/getting-started/index.md');
        $this->assertFileExists($this->dir().'/getting-started/routing.md');
        $this->assertFileDoesNotExist($this->dir().'/routing.md');
        $this->assertFileDoesNotExist($this->dir().'/getting-started.md');
    }
}
