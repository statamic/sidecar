<?php

namespace Statamic\Sidecar;

use Closure;
use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Statamic\Sidecar\Contracts\Driver;

/**
 * @experimental
 */
class Manager
{
    protected array $customCreators = [];

    protected array $packages = [];

    protected array $resolved = [];

    /**
     * Register a driver factory.
     */
    public function extend(string $driver, Closure $callback): self
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Register a driver as compatible with an SSG/composer package.
     *
     * Used by `php please install:sidecar` to detect installed packages
     * and offer the matching driver.
     */
    public function pair(string $compatiblePackage, string $driver): self
    {
        $this->packages[$compatiblePackage] = $driver;

        return $this;
    }

    public function hasDriver(string $driver): bool
    {
        return isset($this->customCreators[$driver]);
    }

    public function registeredDrivers(): array
    {
        return array_keys($this->customCreators);
    }

    public function packages(): IlluminateCollection
    {
        return collect($this->packages);
    }

    /**
     * All bootable sources. Malformed or unresolvable config entries are
     * logged and skipped so a bad entry never takes the app down.
     */
    public function sources(): IlluminateCollection
    {
        return collect(config('sidecar.sources', []))
            ->map(fn ($config, $handle) => $this->source($handle))
            ->filter()
            ->values();
    }

    public function handles(): IlluminateCollection
    {
        return collect(config('sidecar.sources', []))->keys();
    }

    public function source(string $handle): ?Source
    {
        if (array_key_exists($handle, $this->resolved)) {
            return $this->resolved[$handle];
        }

        return $this->resolved[$handle] = $this->resolveSource($handle);
    }

    public function manages(string $handle): bool
    {
        return $this->source($handle) !== null;
    }

    /**
     * Forget resolved sources so config changes are picked up.
     */
    public function flush(): self
    {
        $this->resolved = [];

        return $this;
    }

    public function driver(string $handle): Driver
    {
        if (! $source = $this->source($handle)) {
            throw new InvalidArgumentException("Sidecar source [{$handle}] is not defined.");
        }

        return $source->driver();
    }

    protected function resolveSource(string $handle): ?Source
    {
        $config = config("sidecar.sources.{$handle}");

        if (is_null($config)) {
            return null;
        }

        // getConfig-style tolerance: a scalar entry ('docs' => 'laradocs')
        // should degrade gracefully, not fatal the app.
        if (! is_array($config)) {
            Log::warning("Sidecar: Source [{$handle}] config must be an array.");

            return null;
        }

        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            Log::warning("Sidecar: Source [{$handle}] is missing a driver.");

            return null;
        }

        // Config alone isn't enough — a removed/unregistered driver should
        // degrade rather than throw from CP routes and nav.
        if (! $this->hasDriver($driver)) {
            Log::warning("Sidecar: Driver [{$driver}] for source [{$handle}] is not registered.");

            return null;
        }

        return new Source(
            $handle,
            $config,
            $this->customCreators[$driver](app(), $config, $handle)
        );
    }
}
