<?php

namespace Statamic\Sidecar\Tests;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Fields\Blueprint;
use Statamic\Sidecar\Contracts\Driver;
use Statamic\Sidecar\Drivers\Jigsaw\JigsawDriver;
use Statamic\Sidecar\Drivers\LaraDocs\LaraDocsDriver;
use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Sidecar\Source;

class ManagerTest extends TestCase
{
    #[Test]
    public function it_registers_the_bundled_drivers_and_pairs()
    {
        $this->assertTrue(Sidecar::hasDriver('laradocs'));
        $this->assertTrue(Sidecar::hasDriver('jigsaw'));
        $this->assertTrue(Sidecar::hasDriver('vitepress'));

        $this->assertEquals('laradocs', Sidecar::packages()->get('petebishwhip/laradocs'));
        $this->assertEquals('jigsaw', Sidecar::packages()->get('tightenco/jigsaw'));
    }

    #[Test]
    public function it_resolves_sources_from_config()
    {
        $this->configureSource('docs');

        $source = Sidecar::source('docs');

        $this->assertInstanceOf(Source::class, $source);
        $this->assertInstanceOf(LaraDocsDriver::class, $source->driver());
        $this->assertEquals('docs', $source->handle());
        $this->assertTrue(Sidecar::manages('docs'));
        $this->assertCount(1, Sidecar::sources());
    }

    #[Test]
    public function it_resolves_multiple_sources_with_different_drivers()
    {
        $this->configureSource('docs');
        $this->configureSource('jigsaw-docs', ['driver' => 'jigsaw']);

        $this->assertInstanceOf(LaraDocsDriver::class, Sidecar::driver('docs'));
        $this->assertInstanceOf(JigsawDriver::class, Sidecar::driver('jigsaw-docs'));
        $this->assertCount(2, Sidecar::sources());
    }

    #[Test]
    public function unknown_sources_are_not_managed()
    {
        $this->assertFalse(Sidecar::manages('nope'));
        $this->assertNull(Sidecar::source('nope'));
    }

    #[Test]
    public function malformed_scalar_config_degrades_gracefully()
    {
        Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'must be an array'));

        config()->set('sidecar.sources.docs', 'laradocs');
        Sidecar::flush();

        $this->assertNull(Sidecar::source('docs'));
        $this->assertFalse(Sidecar::manages('docs'));
        $this->assertCount(0, Sidecar::sources());
    }

    #[Test]
    public function missing_driver_key_degrades_gracefully()
    {
        Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'missing a driver'));

        config()->set('sidecar.sources.docs', ['directory' => $this->fixturesDir]);
        Sidecar::flush();

        $this->assertNull(Sidecar::source('docs'));
    }

    #[Test]
    public function unregistered_driver_degrades_gracefully()
    {
        Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'is not registered'));

        config()->set('sidecar.sources.docs', ['driver' => 'hugo', 'directory' => $this->fixturesDir]);
        Sidecar::flush();

        $this->assertNull(Sidecar::source('docs'));
        $this->assertFalse(Sidecar::manages('docs'));
    }

    #[Test]
    public function custom_drivers_can_be_registered()
    {
        Sidecar::extend('custom', function ($app, array $config, string $handle) {
            return new class($config, $handle) extends \Statamic\Sidecar\Drivers\Driver
            {
                public function title(): string
                {
                    return 'Custom';
                }

                protected function defaultBlueprint(): Blueprint
                {
                    return $this->makeBlueprint(['title' => 'Custom']);
                }
            };
        });

        $this->configureSource('custom-docs', ['driver' => 'custom']);

        $this->assertInstanceOf(Driver::class, Sidecar::driver('custom-docs'));
        $this->assertEquals('Custom', Sidecar::source('custom-docs')->title());
    }

    #[Test]
    public function driver_throws_for_undefined_source()
    {
        $this->expectException(InvalidArgumentException::class);

        Sidecar::driver('nope');
    }

    #[Test]
    public function source_config_overrides_title_and_read_only()
    {
        $this->configureSource('docs', ['title' => 'Handbook', 'read_only' => true]);

        $source = Sidecar::source('docs');

        $this->assertEquals('Handbook', $source->title());
        $this->assertTrue($source->readOnly());
    }
}
