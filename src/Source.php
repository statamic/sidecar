<?php

namespace Statamic\Sidecar;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Path;
use Statamic\Fields\Blueprint as BlueprintInstance;
use Statamic\Sidecar\Contracts\Driver;
use Statamic\Support\Arr;

/**
 * A configured directory of external documents, paired with a driver.
 *
 * @experimental
 */
class Source
{
    public function __construct(
        protected string $handle,
        protected array $config,
        protected Driver $driver,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    public function driver(): Driver
    {
        return $this->driver;
    }

    public function config(?string $key = null, $default = null)
    {
        if (is_null($key)) {
            return $this->config;
        }

        return Arr::get($this->config, $key, $default);
    }

    public function title(): string
    {
        return $this->config['title'] ?? $this->driver->title();
    }

    public function directory(): string
    {
        return rtrim(Path::tidy($this->driver->directory()), '/');
    }

    public function extension(): string
    {
        return $this->driver->extension();
    }

    public function indexFileName(): string
    {
        return $this->driver->indexFileName();
    }

    public function readOnly(): bool
    {
        return (bool) ($this->config['read_only'] ?? false);
    }

    public function expectsRoot(): bool
    {
        return $this->driver->expectsRoot();
    }

    /**
     * The user's blueprint override if one has been saved, otherwise the
     * driver's default (registered as a fallback for `sidecar::{handle}`).
     */
    public function blueprint(): BlueprintInstance
    {
        return Blueprint::find('sidecar::'.$this->handle) ?? $this->driver->blueprint();
    }

    public function showUrl(): string
    {
        return cp_route('sidecar.source.show', $this->handle);
    }

    public function createUrl(): string
    {
        return cp_route('sidecar.documents.create', $this->handle);
    }

    public function createPreviewUrl(): string
    {
        return cp_route('sidecar.documents.preview.create', $this->handle);
    }

    public function treeIndexUrl(): string
    {
        return cp_route('sidecar.source.tree.index', $this->handle);
    }

    public function treeSubmitUrl(): string
    {
        return cp_route('sidecar.source.tree.update', $this->handle);
    }

    public function editBlueprintUrl(): string
    {
        return cp_route('blueprints.additional.edit', ['sidecar', $this->handle]);
    }
}
