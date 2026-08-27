<?php

namespace Statamic\Sidecar\Tests;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Sidecar\Source;
use Statamic\Sidecar\Tree\DerivedTree;

class DerivedTreeTest extends TestCase
{
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = $this->configureSource('docs');
    }

    private function dir(): string
    {
        return $this->source->directory();
    }

    private function tree(): array
    {
        return (new DerivedTree)->build($this->source);
    }

    private function summarize(array $branches): array
    {
        return collect($branches)
            ->map(fn ($branch) => [
                'id' => $branch['id'],
                'children' => $this->summarize($branch['children']),
            ])
            ->all();
    }

    #[Test]
    public function it_derives_nesting_from_the_folder_layout()
    {
        $this->makeDoc($this->dir(), '_index.md', ['title' => 'Home']);
        $this->makeDoc($this->dir(), 'installation.md', ['title' => 'Installation', 'order' => 1]);
        $this->makeDoc($this->dir(), 'guide/_index.md', ['title' => 'Guide', 'order' => 2]);
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing', 'order' => 1]);
        $this->makeDoc($this->dir(), 'guide/views.md', ['title' => 'Views', 'order' => 2]);

        $this->assertEquals([
            ['id' => '_index', 'children' => []],
            ['id' => 'installation', 'children' => []],
            ['id' => 'guide/_index', 'children' => [
                ['id' => 'guide/routing', 'children' => []],
                ['id' => 'guide/views', 'children' => []],
            ]],
        ], $this->summarize($this->tree()));
    }

    #[Test]
    public function siblings_sort_by_order_then_title()
    {
        $this->makeDoc($this->dir(), 'zebra.md', ['title' => 'Zebra', 'order' => 1]);
        $this->makeDoc($this->dir(), 'apple.md', ['title' => 'Apple']);
        $this->makeDoc($this->dir(), 'mango.md', ['title' => 'Mango']);

        $this->assertEquals(
            ['zebra', 'apple', 'mango'],
            collect($this->tree())->pluck('id')->all()
        );
    }

    #[Test]
    public function branches_carry_titles_and_edit_urls()
    {
        $this->makeDoc($this->dir(), 'installation.md', ['title' => 'Installation']);

        $branch = $this->tree()[0];

        $this->assertEquals('Installation', $branch['title']);
        $this->assertEquals('installation', $branch['slug']);
        $this->assertStringContainsString('sidecar/docs/documents/installation/edit', $branch['edit_url']);
    }

    #[Test]
    public function folders_without_an_index_get_a_virtual_branch()
    {
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing']);

        $tree = $this->tree();

        $this->assertCount(1, $tree);
        $this->assertEquals(DerivedTree::VIRTUAL_PREFIX.'guide', $tree[0]['id']);
        $this->assertEquals('Guide', $tree[0]['title']);
        $this->assertNull($tree[0]['edit_url']);
        $this->assertEquals('guide/routing', $tree[0]['children'][0]['id']);
    }

    #[Test]
    public function the_tree_is_cached_until_files_change()
    {
        $this->makeDoc($this->dir(), 'one.md', ['title' => 'One']);

        $first = $this->tree();
        $this->assertCount(1, $first);

        // Bypassing the repository (an external edit) with a fresh mtime.
        $path = $this->makeDoc($this->dir(), 'two.md', ['title' => 'Two']);
        touch($path, time() + 5);

        $this->assertCount(2, $this->tree());
    }

    #[Test]
    public function an_empty_or_missing_directory_produces_an_empty_tree()
    {
        $this->assertEquals([], $this->tree());
    }
}
