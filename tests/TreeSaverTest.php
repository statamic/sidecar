<?php

namespace Statamic\Sidecar\Tests;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\YAML;
use Statamic\Sidecar\Source;
use Statamic\Sidecar\Tree\TreeSaver;

class TreeSaverTest extends TestCase
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

    private function save(array $branches): void
    {
        (new TreeSaver)->save($this->source, $branches);
    }

    private function frontMatter(string $relativePath): array
    {
        $parsed = YAML::parse(File::get($this->dir().'/'.$relativePath));
        unset($parsed['content']);

        return $parsed;
    }

    #[Test]
    public function reparenting_a_leaf_moves_its_file()
    {
        $this->makeDoc($this->dir(), '_index.md', ['title' => 'Home']);
        $this->makeDoc($this->dir(), 'routing.md', ['title' => 'Routing']);
        $this->makeDoc($this->dir(), 'guide/_index.md', ['title' => 'Guide']);

        $this->save([
            ['id' => '_index', 'children' => []],
            ['id' => 'guide/_index', 'children' => [
                ['id' => 'routing', 'children' => []],
            ]],
        ]);

        $this->assertFileDoesNotExist($this->dir().'/routing.md');
        $this->assertFileExists($this->dir().'/guide/routing.md');
        $this->assertEquals('Routing', $this->frontMatter('guide/routing.md')['title']);
    }

    #[Test]
    public function a_leaf_gaining_children_becomes_a_section_index()
    {
        $this->makeDoc($this->dir(), 'guide.md', ['title' => 'Guide']);
        $this->makeDoc($this->dir(), 'routing.md', ['title' => 'Routing']);

        $this->save([
            ['id' => 'guide', 'children' => [
                ['id' => 'routing', 'children' => []],
            ]],
        ]);

        $this->assertFileDoesNotExist($this->dir().'/guide.md');
        $this->assertFileExists($this->dir().'/guide/_index.md');
        $this->assertFileExists($this->dir().'/guide/routing.md');
    }

    #[Test]
    public function a_section_losing_its_children_becomes_a_leaf()
    {
        $this->makeDoc($this->dir(), 'guide/_index.md', ['title' => 'Guide']);
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing']);

        $this->save([
            ['id' => 'guide/_index', 'children' => []],
            ['id' => 'guide/routing', 'children' => []],
        ]);

        $this->assertFileExists($this->dir().'/guide.md');
        $this->assertFileExists($this->dir().'/routing.md');
        $this->assertDirectoryDoesNotExist($this->dir().'/guide');
    }

    #[Test]
    public function sibling_order_is_written_to_front_matter()
    {
        $this->makeDoc($this->dir(), '_index.md', ['title' => 'Home']);
        $this->makeDoc($this->dir(), 'aaa.md', ['title' => 'AAA', 'order' => 1]);
        $this->makeDoc($this->dir(), 'bbb.md', ['title' => 'BBB', 'order' => 2, 'custom' => 'kept']);

        $this->save([
            ['id' => '_index', 'children' => []],
            ['id' => 'bbb', 'children' => []],
            ['id' => 'aaa', 'children' => []],
        ]);

        $this->assertEquals(1, $this->frontMatter('bbb.md')['order']);
        $this->assertEquals(2, $this->frontMatter('aaa.md')['order']);

        // Untouched front matter survives the order write.
        $this->assertEquals('kept', $this->frontMatter('bbb.md')['custom']);
    }

    #[Test]
    public function the_root_cannot_have_children()
    {
        $this->makeDoc($this->dir(), '_index.md', ['title' => 'Home']);
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page']);

        $this->expectException(ValidationException::class);

        $this->save([
            ['id' => '_index', 'children' => [
                ['id' => 'page', 'children' => []],
            ]],
        ]);
    }

    #[Test]
    public function children_of_virtual_folders_use_the_folder_slug_as_ancestry()
    {
        // guide has no _index.md — its branch is virtual.
        $this->makeDoc($this->dir(), '_index.md', ['title' => 'Home']);
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing']);
        $this->makeDoc($this->dir(), 'views.md', ['title' => 'Views']);

        $this->save([
            ['id' => '_index', 'children' => []],
            ['id' => '_folder::guide', 'slug' => 'guide', 'children' => [
                ['id' => 'guide/routing', 'children' => []],
                ['id' => 'views', 'children' => []],
            ]],
        ]);

        $this->assertFileExists($this->dir().'/guide/routing.md');
        $this->assertFileExists($this->dir().'/guide/views.md');
        $this->assertFileDoesNotExist($this->dir().'/views.md');
    }

    #[Test]
    public function unknown_branch_ids_are_skipped()
    {
        $this->makeDoc($this->dir(), '_index.md', ['title' => 'Home']);
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page']);

        $this->save([
            ['id' => '_index', 'children' => []],
            ['id' => 'deleted-meanwhile', 'children' => []],
            ['id' => 'page', 'children' => []],
        ]);

        $this->assertFileExists($this->dir().'/page.md');
    }
}
