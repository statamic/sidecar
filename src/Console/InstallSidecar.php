<?php

namespace Statamic\Sidecar\Console;

use Facades\Statamic\Console\Processes\Composer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Statamic\Console\EnhancesCommands;
use Statamic\Console\RunsInPlease;
use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;

class InstallSidecar extends Command
{
    use EnhancesCommands, RunsInPlease;

    protected $signature = 'statamic:install:sidecar {driver? : The Sidecar driver to configure}';

    protected $description = 'Configure a Sidecar source for an installed static site generator';

    public function handle()
    {
        $driver = $this->argument('driver') ?? $this->resolveDriver();

        if (! $driver) {
            return;
        }

        if (! Sidecar::hasDriver($driver)) {
            error("The [{$driver}] Sidecar driver is not registered. Available drivers: ".implode(', ', Sidecar::registeredDrivers()));

            return 1;
        }

        $this->writeConfig($driver);

        info('Sidecar is ready. Your source will appear in the Control Panel.');
    }

    protected function resolveDriver(): ?string
    {
        $detected = $this->detectDrivers();

        if ($detected->isEmpty()) {
            $available = collect(Sidecar::registeredDrivers());

            if ($available->isEmpty()) {
                error('No Sidecar drivers are registered.');

                return null;
            }

            return select(
                'No compatible packages were detected. Which Sidecar driver would you like to configure?',
                $available->mapWithKeys(fn ($driver) => [$driver => $driver])->all()
            );
        }

        if ($detected->count() === 1) {
            $driver = $detected->keys()->first();

            if (confirm("Detected {$detected->first()}. Configure the [{$driver}] Sidecar driver?")) {
                return $driver;
            }

            return null;
        }

        return select(
            'Multiple compatible packages detected. Which Sidecar driver would you like to configure?',
            $detected->mapWithKeys(fn ($package, $driver) => [$driver => "{$driver} ({$package})"])->all()
        );
    }

    /**
     * Drivers whose paired SSG/composer package is installed in this app.
     */
    protected function detectDrivers()
    {
        return Sidecar::packages()
            ->filter(fn ($driver, $package) => Composer::isInstalled($package))
            ->mapWithKeys(fn ($driver, $package) => [$driver => $package]);
    }

    protected function writeConfig(string $driver): void
    {
        $path = config_path('sidecar.php');

        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::copy(__DIR__.'/../../config/sidecar.php', $path);
            $this->checkLine('Published config/sidecar.php');
        }

        $config = require $path;

        $handle = $this->defaultHandleFor($driver);

        if (isset($config['sources'][$handle])) {
            $this->checkLine('Sidecar source config already present');

            return;
        }

        if (! confirm('Would you like to add a default source config for this driver?')) {
            return;
        }

        $directory = $this->defaultDirectoryFor($driver);

        $stub = File::get($path);

        $entry = <<<PHP

        '{$handle}' => [
            'driver' => '{$driver}',
            'directory' => {$directory},
        ],
PHP;

        if (Str::contains($stub, "'sources' => [")) {
            $stub = Str::replaceFirst(
                "'sources' => [",
                "'sources' => [".$entry,
                $stub
            );

            File::put($path, $stub);
            $this->checkLine("Added [{$handle}] source to config/sidecar.php");
        } else {
            error('Could not automatically update config/sidecar.php. Please add the source manually.');
        }
    }

    protected function defaultHandleFor(string $driver): string
    {
        return match ($driver) {
            'laradocs', 'jigsaw' => 'docs',
            default => $driver,
        };
    }

    protected function defaultDirectoryFor(string $driver): string
    {
        return match ($driver) {
            'laradocs' => $this->laradocsDirectoryExpression(),
            'jigsaw' => "base_path('source/docs')",
            default => "base_path('{$driver}')",
        };
    }

    /**
     * Prefer the installed LaraDocs path so we don't guess `docs/` when
     * they've pointed `laradocs.docs.path` somewhere else.
     */
    protected function laradocsDirectoryExpression(): string
    {
        $path = config('laradocs.docs.path');

        if (! $path) {
            return "base_path('docs')";
        }

        $base = rtrim(base_path(), '/');

        if ($path === $base.'/docs' || $path === 'docs') {
            return "base_path('docs')";
        }

        if (Str::startsWith($path, $base.'/')) {
            $relative = Str::after($path, $base.'/');

            return "base_path('{$relative}')";
        }

        return var_export($path, true);
    }
}
