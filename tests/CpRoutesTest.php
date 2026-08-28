<?php

namespace Statamic\Sidecar\Tests;

use Facades\Statamic\CP\LivePreview;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Token;
use Statamic\Facades\YAML;
use Statamic\Sidecar\Document;
use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Sidecar\Source;

class CpRoutesTest extends TestCase
{
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = $this->configureSource('docs');
        $this->actingAsSuper();
    }

    private function dir(): string
    {
        return $this->source->directory();
    }

    private function frontMatter(string $relativePath): array
    {
        $parsed = YAML::parse(File::get($this->dir().'/'.$relativePath));
        unset($parsed['content']);

        return $parsed;
    }

    #[Test]
    public function the_index_redirects_to_the_first_source()
    {
        $this
            ->get(cp_route('sidecar.index'))
            ->assertRedirect(cp_route('sidecar.source.show', 'docs'));
    }

    #[Test]
    public function the_index_404s_with_no_sources()
    {
        config()->set('sidecar.sources', []);
        Sidecar::flush();

        $this->get(cp_route('sidecar.index'))->assertNotFound();
    }

    #[Test]
    public function the_show_page_renders()
    {
        $this->makeDoc($this->dir(), 'installation.md', ['title' => 'Installation']);

        $this->get(cp_route('sidecar.source.show', 'docs'))->assertOk();
    }

    #[Test]
    public function unknown_sources_404()
    {
        $this->get(cp_route('sidecar.source.show', 'nope'))->assertNotFound();
    }

    #[Test]
    public function the_tree_endpoint_returns_pages()
    {
        $this->makeDoc($this->dir(), 'guide/_index.md', ['title' => 'Guide']);
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing']);

        $response = $this->getJson(cp_route('sidecar.source.tree.index', 'docs'))->assertOk();

        $pages = $response->json('pages');
        $this->assertEquals('guide/_index', $pages[0]['id']);
        $this->assertEquals('guide/routing', $pages[0]['children'][0]['id']);
    }

    #[Test]
    public function saving_the_tree_moves_files()
    {
        $this->makeDoc($this->dir(), 'routing.md', ['title' => 'Routing']);
        $this->makeDoc($this->dir(), 'guide/_index.md', ['title' => 'Guide']);

        $this
            ->patchJson(cp_route('sidecar.source.tree.update', 'docs'), [
                'pages' => [
                    ['id' => 'guide/_index', 'children' => [
                        ['id' => 'routing', 'children' => []],
                    ]],
                ],
            ])
            ->assertOk()
            ->assertJson(['saved' => true]);

        $this->assertFileExists($this->dir().'/guide/routing.md');
    }

    #[Test]
    public function the_edit_page_provides_publish_props()
    {
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing', 'custom' => 'kept'], "# Hello\n");

        $response = $this->get(cp_route('sidecar.documents.edit', ['docs', 'guide/routing']))->assertOk();

        $page = $response->viewData('page');

        $this->assertEquals('sidecar/Edit', $page['component']);
        $this->assertEquals('Routing', $page['props']['title']);
        $this->assertEquals('Routing', $page['props']['values']['title']);
        $this->assertEquals('routing', $page['props']['values']['slug']);
        $this->assertEquals("# Hello\n", $page['props']['values']['content']);
        $this->assertFalse($page['props']['readOnly']);
    }

    #[Test]
    public function updating_a_document_writes_the_file_and_preserves_unknown_keys()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page', 'custom' => 'kept', 'order' => 3], "Old body\n");

        $this
            ->patchJson(cp_route('sidecar.documents.update', ['docs', 'page']), [
                'title' => 'Updated',
                'slug' => 'page',
                'content' => "New body\n",
            ])
            ->assertOk();

        $frontMatter = $this->frontMatter('page.md');

        $this->assertEquals('Updated', $frontMatter['title']);
        $this->assertEquals('kept', $frontMatter['custom']);
        $this->assertEquals(3, $frontMatter['order']);
        $this->assertStringContainsString('New body', File::get($this->dir().'/page.md'));
    }

    #[Test]
    public function changing_the_slug_renames_a_leaf_file()
    {
        $this->makeDoc($this->dir(), 'guide/old-name.md', ['title' => 'Old']);

        $response = $this
            ->patchJson(cp_route('sidecar.documents.update', ['docs', 'guide/old-name']), [
                'title' => 'Old',
                'slug' => 'new-name',
            ])
            ->assertOk();

        $this->assertFileExists($this->dir().'/guide/new-name.md');
        $this->assertFileDoesNotExist($this->dir().'/guide/old-name.md');
        $this->assertStringContainsString('guide/new-name', $response->json('data.editUrl'));
    }

    #[Test]
    public function changing_a_section_slug_renames_the_folder()
    {
        $this->makeDoc($this->dir(), 'guide/_index.md', ['title' => 'Guide']);
        $this->makeDoc($this->dir(), 'guide/routing.md', ['title' => 'Routing']);

        $this
            ->patchJson(cp_route('sidecar.documents.update', ['docs', 'guide/_index']), [
                'title' => 'Handbook',
                'slug' => 'handbook',
            ])
            ->assertOk();

        $this->assertFileExists($this->dir().'/handbook/_index.md');
        $this->assertFileExists($this->dir().'/handbook/routing.md');
        $this->assertDirectoryDoesNotExist($this->dir().'/guide');
    }

    #[Test]
    public function a_conflicting_slug_is_rejected()
    {
        $this->makeDoc($this->dir(), 'one.md', ['title' => 'One']);
        $this->makeDoc($this->dir(), 'two.md', ['title' => 'Two']);

        $this
            ->patchJson(cp_route('sidecar.documents.update', ['docs', 'one']), [
                'title' => 'One',
                'slug' => 'two',
            ])
            ->assertStatus(422);

        $this->assertFileExists($this->dir().'/one.md');
    }

    #[Test]
    public function storing_a_document_creates_the_file_under_a_parent()
    {
        $this->makeDoc($this->dir(), 'guide/_index.md', ['title' => 'Guide']);

        $this
            ->postJson(cp_route('sidecar.documents.store', 'docs'), [
                'title' => 'Routing',
                'slug' => 'routing',
                'parent' => 'guide',
                'content' => "Hello\n",
            ])
            ->assertOk();

        $this->assertFileExists($this->dir().'/guide/routing.md');
    }

    #[Test]
    public function storing_a_section_writes_an_index_file()
    {
        $this
            ->postJson(cp_route('sidecar.documents.store', 'docs'), [
                'title' => 'Recipes',
                'slug' => 'recipes',
                'as_section' => true,
                'content' => "Hello\n",
            ])
            ->assertOk();

        $this->assertFileExists($this->dir().'/recipes/_index.md');
    }

    #[Test]
    public function storing_a_child_of_a_leaf_converts_it_to_a_section()
    {
        $this->makeDoc($this->dir(), 'guide.md', ['title' => 'Guide']);

        $this
            ->postJson(cp_route('sidecar.documents.store', 'docs'), [
                'title' => 'Routing',
                'slug' => 'routing',
                'parent' => 'guide',
            ])
            ->assertOk();

        $this->assertFileExists($this->dir().'/guide/_index.md');
        $this->assertFileExists($this->dir().'/guide/routing.md');
        $this->assertFileDoesNotExist($this->dir().'/guide.md');
    }

    #[Test]
    public function creating_a_document_enables_live_preview()
    {
        $response = $this->get(cp_route('sidecar.documents.create', 'docs'))->assertOk();

        $page = $response->viewData('page');

        $this->assertNotEmpty($page['props']['livePreviewUrl']);
        $this->assertNotEmpty($page['props']['previewTargets']);
    }

    #[Test]
    public function creating_a_child_keeps_parent_on_the_publish_form()
    {
        $response = $this
            ->get(cp_route('sidecar.documents.create', 'docs').'?parent=guide')
            ->assertOk();

        $page = $response->viewData('page');
        $handles = collect($page['props']['blueprint']['tabs'])
            ->flatMap(fn ($tab) => $tab['sections'] ?? [])
            ->flatMap(fn ($section) => $section['fields'] ?? [])
            ->pluck('handle');

        $this->assertEquals('guide', $page['props']['values']['parent']);
        $this->assertContains('parent', $handles);
        $this->assertContains('as_section', $handles);
    }

    #[Test]
    public function the_show_page_exposes_the_index_filename()
    {
        $page = $this->get(cp_route('sidecar.source.show', 'docs'))->assertOk()->viewData('page');

        $this->assertEquals('_index', $page['props']['indexFileName']);
    }

    #[Test]
    public function live_preview_can_tokenize_an_unsaved_document()
    {
        $response = $this
            ->postJson(cp_route('sidecar.documents.preview.create', 'docs'), [
                'preview' => [
                    'title' => 'WIP Title',
                    'slug' => 'wip',
                    'content' => 'WIP body',
                ],
            ])
            ->assertOk();

        $document = LivePreview::item(Token::find($response->json('token')));

        $this->assertInstanceOf(Document::class, $document);
        $this->assertEquals('WIP Title', $document->value('title'));
        $this->assertEquals('wip', $document->path());
    }

    #[Test]
    public function url_slug_is_written_as_front_matter_slug()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page']);

        $this
            ->patchJson(cp_route('sidecar.documents.update', ['docs', 'page']), [
                'title' => 'Page',
                'slug' => 'page',
                'url_slug' => 'custom-url',
            ])
            ->assertOk();

        $this->assertEquals('custom-url', $this->frontMatter('page.md')['slug']);
        $this->assertFileExists($this->dir().'/page.md');
    }

    #[Test]
    public function the_tree_exposes_laradocs_state()
    {
        $this->makeDoc($this->dir(), 'secret.md', [
            'title' => 'Secret',
            'hidden' => true,
            'group' => 'Guide',
            'redirect' => '/elsewhere',
        ]);

        $page = $this->getJson(cp_route('sidecar.source.tree.index', 'docs'))
            ->assertOk()
            ->json('pages.0');

        $this->assertTrue($page['hidden']);
        $this->assertEquals('Guide', $page['group']);
        $this->assertEquals('/elsewhere', $page['redirect']);
    }

    #[Test]
    public function storing_a_document_creates_the_file_at_the_root()
    {
        $response = $this
            ->postJson(cp_route('sidecar.documents.store', 'docs'), [
                'title' => 'Brand New',
                'slug' => 'brand-new',
                'content' => "Hello\n",
            ])
            ->assertOk();

        $this->assertFileExists($this->dir().'/brand-new.md');
        $this->assertStringContainsString('brand-new', $response->json('data.redirect'));
    }

    #[Test]
    public function deleting_a_document_removes_the_file()
    {
        $this->makeDoc($this->dir(), 'guide/page.md', ['title' => 'Page']);

        $this
            ->deleteJson(cp_route('sidecar.documents.destroy', ['docs', 'guide/page']))
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertFileDoesNotExist($this->dir().'/guide/page.md');
        $this->assertDirectoryDoesNotExist($this->dir().'/guide');
    }

    #[Test]
    public function read_only_sources_block_all_mutations()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page']);

        $this->configureSource('docs', ['read_only' => true]);

        $this->get(cp_route('sidecar.documents.create', 'docs'))->assertForbidden();
        $this->postJson(cp_route('sidecar.documents.store', 'docs'), ['title' => 'X'])->assertForbidden();
        $this->patchJson(cp_route('sidecar.documents.update', ['docs', 'page']), ['title' => 'X'])->assertForbidden();
        $this->deleteJson(cp_route('sidecar.documents.destroy', ['docs', 'page']))->assertForbidden();
        $this->patchJson(cp_route('sidecar.source.tree.update', 'docs'), ['pages' => []])->assertForbidden();

        // Reading still works.
        $this->get(cp_route('sidecar.source.show', 'docs'))->assertOk();
        $this->get(cp_route('sidecar.documents.edit', ['docs', 'page']))->assertOk();
    }

    #[Test]
    public function live_preview_tokenizes_a_document_with_wip_values()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page'], "Saved body\n");

        $response = $this
            ->postJson(cp_route('sidecar.documents.preview.edit', ['docs', 'page']), [
                'preview' => [
                    'title' => 'WIP Title',
                    'content' => "WIP body\n",
                ],
            ])
            ->assertOk();

        $this->assertNotNull($token = $response->json('token'));
        $this->assertStringContainsString('token='.$token, $response->json('url'));

        $document = LivePreview::item(Token::find($token));

        $this->assertInstanceOf(Document::class, $document);
        $this->assertEquals('WIP Title', $document->value('title'));
        // Laravel's TrimStrings middleware trims the trailing newline in transit.
        $this->assertEquals('WIP body', $document->value('content'));
        $this->assertEquals('Page', $document->get('title')); // saved data untouched
    }

    #[Test]
    public function guests_cannot_access_sidecar_routes()
    {
        $this->makeDoc($this->dir(), 'page.md', ['title' => 'Page']);

        auth()->logout();

        $this->get(cp_route('sidecar.source.show', 'docs'))->assertRedirect();
    }
}
