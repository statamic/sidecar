<?php

namespace Statamic\Sidecar\Tests;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Icon;
use Statamic\Icons\IconManager;
use Statamic\Sidecar\Documents;
use Statamic\Sidecar\Drivers\LaraDocs\LaraDocsDriver;
use Statamic\Sidecar\Source;

class LaraDocsDriverTest extends TestCase
{
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = $this->configureSource('docs');
    }

    #[Test]
    public function it_reads_the_index_filename_from_laradocs_config()
    {
        config()->set('laradocs.docs.index', 'index');

        $this->assertEquals('index', $this->source->driver()->indexFileName());
    }

    #[Test]
    public function it_uses_laradocs_ignored_patterns()
    {
        $this->assertContains('README.md', $this->source->driver()->ignoredPatterns());
        $this->assertContains('_drafts', $this->source->driver()->ignoredPatterns());
    }

    #[Test]
    public function existing_groups_are_collected_for_the_select()
    {
        $this->makeDoc($this->source->directory(), 'a.md', ['title' => 'A', 'group' => 'Guide']);
        $this->makeDoc($this->source->directory(), 'b.md', ['title' => 'B', 'group' => 'Recipes']);
        $this->makeDoc($this->source->directory(), 'c.md', ['title' => 'C']);

        $groups = $this->source->driver()->existingGroups();

        $this->assertEquals(['Guide' => 'Guide', 'Recipes' => 'Recipes'], $groups);
    }

    #[Test]
    public function prepare_blueprint_hides_group_on_section_indexes()
    {
        $this->makeDoc($this->source->directory(), 'guide/_index.md', ['title' => 'Guide']);

        $document = app(Documents::class)->find($this->source, 'guide/_index');
        $blueprint = $this->source->driver()->prepareBlueprint($this->source->blueprint(), $document);

        $this->assertEquals('hidden', $blueprint->field('group')->get('visibility'));
    }

    #[Test]
    public function prepare_blueprint_keeps_group_on_leaves()
    {
        $this->makeDoc($this->source->directory(), 'page.md', ['title' => 'Page', 'group' => 'Guide']);

        $document = app(Documents::class)->find($this->source, 'page');
        $blueprint = $this->source->driver()->prepareBlueprint($this->source->blueprint(), $document);

        $this->assertNotEquals('hidden', $blueprint->field('group')->get('visibility'));
        $this->assertArrayHasKey('Guide', $blueprint->field('group')->get('options'));
    }

    #[Test]
    public function it_is_the_laradocs_driver()
    {
        $this->assertInstanceOf(LaraDocsDriver::class, $this->source->driver());
        $this->assertTrue($this->source->driver()->supportsNesting());
    }

    #[Test]
    public function the_sidebar_only_has_everyday_nav_fields()
    {
        $handles = $this->source->blueprint()
            ->tabs()
            ->get('sidebar')
            ->fields()
            ->all()
            ->keys()
            ->values()
            ->all();

        $this->assertSame(['slug', 'group', 'icon', 'badge', 'hidden'], $handles);
    }

    #[Test]
    public function the_meta_tab_uses_a_url_revealer_and_search_conditional()
    {
        $blueprint = $this->source->blueprint();

        $this->assertTrue($blueprint->tabs()->has('meta'));
        $this->assertEquals('revealer', $blueprint->field('url_overrides')->type());
        $this->assertEquals(['url_overrides' => 'equals true'], $blueprint->field('url_slug')->get('if'));
        $this->assertEquals(['url_overrides' => 'equals true'], $blueprint->field('redirect')->get('if'));
        $this->assertEquals(['search' => 'equals false'], $blueprint->field('search_rank')->get('unless'));
    }

    #[Test]
    public function prepare_blueprint_falls_back_to_a_text_icon_without_heroicons()
    {
        Icon::swap(new IconManager);

        $blueprint = $this->source->driver()->prepareBlueprint($this->source->blueprint());

        $this->assertEquals('text', $blueprint->field('icon')->type());
        $this->assertArrayNotHasKey('set', $blueprint->field('icon')->config());
    }

    #[Test]
    public function prepare_blueprint_keeps_the_heroicons_icon_field_when_registered()
    {
        $directory = $this->fixturesDir.'/heroicons';
        File::ensureDirectoryExists($directory);
        File::put($directory.'/rocket.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        Icon::swap(new IconManager);
        Icon::register('heroicons', $directory);

        $blueprint = $this->source->driver()->prepareBlueprint($this->source->blueprint());

        $this->assertEquals('icon', $blueprint->field('icon')->type());
        $this->assertEquals('heroicons', $blueprint->field('icon')->get('set'));
    }

    #[Test]
    public function url_overrides_revealer_opens_when_a_slug_or_redirect_exists()
    {
        $this->makeDoc($this->source->directory(), 'plain.md', ['title' => 'Plain']);
        $this->makeDoc($this->source->directory(), 'custom.md', ['title' => 'Custom', 'slug' => 'overridden']);
        $this->makeDoc($this->source->directory(), 'gone.md', ['title' => 'Gone', 'redirect' => '/elsewhere']);

        $this->assertFalse(app(Documents::class)->find($this->source, 'plain')->values()['url_overrides']);
        $this->assertTrue(app(Documents::class)->find($this->source, 'custom')->values()['url_overrides']);
        $this->assertTrue(app(Documents::class)->find($this->source, 'gone')->values()['url_overrides']);
    }
}
