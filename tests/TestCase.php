<?php

namespace Statamic\Sidecar\Tests;

use Illuminate\Support\Facades\File;
use Statamic\Facades\User;
use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Sidecar\ServiceProvider;
use Statamic\Sidecar\Source;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();

        // The stache's dev-null directory also lives inside __fixtures__ and
        // must survive between tests, so our file fixtures get a subfolder.
        $this->fixturesDir = __DIR__.'/__fixtures__/content';
        File::deleteDirectory($this->fixturesDir);
        File::ensureDirectoryExists($this->fixturesDir);
        File::ensureDirectoryExists(__DIR__.'/__fixtures__/dev-null');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixturesDir);

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        // Console commands bind their output through Partyline, which the
        // base AddonTestCase doesn't register.
        return array_merge(parent::getPackageProviders($app), [
            \Wilderborn\Partyline\ServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.system.addons_path', __DIR__.'/../');
    }

    protected function configureSource(string $handle = 'docs', array $config = []): Source
    {
        $config = array_merge([
            'driver' => 'laradocs',
            'directory' => $this->fixturesDir.'/'.$handle,
        ], $config);

        config()->set("sidecar.sources.{$handle}", $config);

        if (isset($config['directory'])) {
            File::ensureDirectoryExists($config['directory']);
        }

        Sidecar::flush();

        return Sidecar::source($handle);
    }

    protected function makeDoc(string $directory, string $relativePath, array $data = [], string $content = ''): string
    {
        $path = rtrim($directory, '/').'/'.$relativePath;

        $frontMatter = collect($data)
            ->map(fn ($value, $key) => is_int($value) ? "{$key}: {$value}" : "{$key}: '{$value}'")
            ->implode("\n");

        $contents = empty($data)
            ? $content
            : "---\n{$frontMatter}\n---\n{$content}";

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        return $path;
    }

    protected function actingAsSuper()
    {
        $user = tap(User::make()->makeSuper()->email('test@example.com'))->save();

        return $this->actingAs($user);
    }
}
