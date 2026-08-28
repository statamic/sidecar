<?php

namespace Statamic\Sidecar;

use Laradocs\Icons\HeroiconProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Icon;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Sidecar\Console\InstallSidecar;
use Statamic\Sidecar\Drivers\Hyde\HydeDriver;
use Statamic\Sidecar\Drivers\Jigsaw\JigsawDriver;
use Statamic\Sidecar\Drivers\LaraDocs\LaraDocsDriver;
use Statamic\Sidecar\Drivers\VitePress\VitePressDriver;
use Statamic\Sidecar\Facades\Sidecar;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => ['resources/js/sidecar.js'],
        'publicDirectory' => 'resources/dist',
    ];

    protected $commands = [
        InstallSidecar::class,
    ];

    public function register()
    {
        $this->app->singleton(Manager::class);
        $this->app->singleton(Documents::class);
    }

    public function bootAddon()
    {
        $this->registerDrivers();
        $this->registerHeroiconSet();
        $this->registerBlueprints();
        $this->registerNav();

        // Live Preview caches the WIP document against a token. Newer Laravel
        // versions restrict which classes the cache may unserialize.
        $this->registerSerializableClasses([Document::class]);
    }

    protected function registerDrivers(): void
    {
        Sidecar::extend('laradocs', function ($app, array $config, string $handle) {
            return new LaraDocsDriver($config, $handle);
        });

        Sidecar::extend('jigsaw', function ($app, array $config, string $handle) {
            return new JigsawDriver($config, $handle);
        });

        Sidecar::extend('vitepress', function ($app, array $config, string $handle) {
            return new VitePressDriver($config, $handle);
        });

        Sidecar::extend('hyde', function ($app, array $config, string $handle) {
            return new HydeDriver($config, $handle);
        });

        Sidecar::pair('petebishwhip/laradocs', 'laradocs');
        Sidecar::pair('tightenco/jigsaw', 'jigsaw');
        Sidecar::pair('hyde/framework', 'hyde');
    }

    protected function registerBlueprints(): void
    {
        // Users can override and edit each source's blueprint in the CP.
        // Saved overrides live in this namespace directory; until one is
        // saved, the driver's default blueprint acts as the fallback.
        Blueprint::addNamespace('sidecar', resource_path('blueprints/sidecar'));

        Sidecar::sources()->each(function (Source $source) {
            Blueprint::setFallback(
                'sidecar::'.$source->handle(),
                fn () => $source->driver()->blueprint()
            );
        });
    }

    protected function registerNav(): void
    {
        Nav::extend(function ($nav) {
            if (Sidecar::sources()->isEmpty()) {
                return;
            }

            $icon = $this->sidecarIcon();

            Nav::content(__('Sidecar'))
                ->route('sidecar.index')
                ->icon($icon)
                ->children(function () use ($icon) {
                    return Sidecar::sources()->map(function (Source $source) use ($icon) {
                        return Nav::item($source->title())
                            ->url($source->showUrl())
                            ->icon($icon);
                    });
                });
        });
    }

    protected function sidecarIcon(): string
    {
        return file_get_contents(__DIR__.'/../resources/svg/sidecar.svg');
    }

    protected function registerHeroiconSet(): void
    {
        if (! class_exists(HeroiconProvider::class)) {
            return;
        }

        $path = config('laradocs.icons.heroicons.path') ?: HeroiconProvider::detect();

        if (! $path) {
            return;
        }

        $variant = config('laradocs.icons.heroicons.variant', 'outline');
        $variant = in_array($variant, ['outline', 'solid', 'mini', 'micro'], true)
            ? $variant
            : 'outline';

        $size = match ($variant) {
            'mini' => '20',
            'micro' => '16',
            default => '24',
        };

        $directory = rtrim($path, '/').'/'.$size.'/'.$variant;

        if (is_dir($directory)) {
            Icon::register('heroicons', $directory);
        }
    }
}
