<?php

namespace Statamic\Sidecar\Tests;

use Facades\Statamic\Console\Processes\Composer;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class InstallCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(config_path('sidecar.php'));

        parent::tearDown();
    }

    #[Test]
    public function it_publishes_config_and_adds_a_source_for_the_given_driver()
    {
        $this->assertFileDoesNotExist(config_path('sidecar.php'));

        $this->artisan('statamic:install:sidecar', ['driver' => 'laradocs'])
            ->expectsConfirmation('Would you like to add a default source config for this driver?', 'yes')
            ->assertSuccessful();

        $this->assertFileExists(config_path('sidecar.php'));

        $config = require config_path('sidecar.php');

        $this->assertEquals('laradocs', $config['sources']['docs']['driver']);
        $this->assertEquals(base_path('docs'), $config['sources']['docs']['directory']);
    }

    #[Test]
    public function it_does_not_duplicate_an_existing_source()
    {
        $this->artisan('statamic:install:sidecar', ['driver' => 'jigsaw'])
            ->expectsConfirmation('Would you like to add a default source config for this driver?', 'yes')
            ->assertSuccessful();

        $this->artisan('statamic:install:sidecar', ['driver' => 'jigsaw'])
            ->assertSuccessful();

        $config = require config_path('sidecar.php');

        $this->assertEquals('jigsaw', $config['sources']['docs']['driver']);
        $this->assertEquals(base_path('source/docs'), $config['sources']['docs']['directory']);
    }

    #[Test]
    public function it_configures_hyde_to_use_the_docs_directory()
    {
        $this->artisan('statamic:install:sidecar', ['driver' => 'hyde'])
            ->expectsConfirmation('Would you like to add a default source config for this driver?', 'yes')
            ->assertSuccessful();

        $config = require config_path('sidecar.php');

        $this->assertEquals('hyde', $config['sources']['docs']['driver']);
        $this->assertEquals(base_path('hyde/_docs'), $config['sources']['docs']['directory']);
    }

    #[Test]
    public function it_rejects_unknown_drivers()
    {
        $this->artisan('statamic:install:sidecar', ['driver' => 'hugo'])
            ->assertFailed();

        $this->assertFileDoesNotExist(config_path('sidecar.php'));
    }

    #[Test]
    public function it_detects_a_paired_package_and_confirms()
    {
        Composer::shouldReceive('isInstalled')->with('petebishwhip/laradocs')->andReturnTrue();
        Composer::shouldReceive('isInstalled')->with('tightenco/jigsaw')->andReturnFalse();
        Composer::shouldReceive('isInstalled')->with('hyde/framework')->andReturnFalse();

        $this->artisan('statamic:install:sidecar')
            ->expectsConfirmation('Detected petebishwhip/laradocs. Configure the [laradocs] Sidecar driver?', 'yes')
            ->expectsConfirmation('Would you like to add a default source config for this driver?', 'yes')
            ->assertSuccessful();

        $config = require config_path('sidecar.php');

        $this->assertEquals('laradocs', $config['sources']['docs']['driver']);
    }

    #[Test]
    public function it_uses_the_configured_laradocs_path()
    {
        config()->set('laradocs.docs.path', base_path('documentation'));

        $this->artisan('statamic:install:sidecar', ['driver' => 'laradocs'])
            ->expectsConfirmation('Would you like to add a default source config for this driver?', 'yes')
            ->assertSuccessful();

        $config = require config_path('sidecar.php');

        $this->assertEquals(base_path('documentation'), $config['sources']['docs']['directory']);
    }
}
