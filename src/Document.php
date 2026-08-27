<?php

namespace Statamic\Sidecar;

use Statamic\Sidecar\Facades\Sidecar;
use Statamic\Support\Str;

/**
 * A single external markdown file, identified by its current on-disk path.
 *
 * Nothing Sidecar persists is keyed to this object — identity is the
 * relative path (without extension), stable within a single request.
 * Serializable so Live Preview can cache it against a token.
 *
 * @experimental
 */
class Document
{
    protected array $supplements = [];

    protected ?string $previousPath = null;

    public function __construct(
        protected string $sourceHandle,
        protected string $path,
        protected array $data = [],
        protected string $content = '',
    ) {}

    public function source(): Source
    {
        return Sidecar::source($this->sourceHandle);
    }

    public function sourceHandle(): string
    {
        return $this->sourceHandle;
    }

    /**
     * Relative path without extension. e.g. `guide/routing`, `guide/_index`.
     */
    public function path(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * The path this document had before being moved/renamed in this request.
     */
    public function previousPath(): ?string
    {
        return $this->previousPath;
    }

    public function setPreviousPath(?string $path): self
    {
        $this->previousPath = $path;

        return $this;
    }

    public function absolutePath(): string
    {
        return $this->source()->directory().'/'.$this->path.'.'.$this->source()->extension();
    }

    public function isIndex(): bool
    {
        return basename($this->path) === $this->source()->indexFileName();
    }

    public function isRoot(): bool
    {
        return $this->path === $this->source()->indexFileName();
    }

    /**
     * The URL/filename slug. Sections use their folder name, the root uses `index`.
     */
    public function slug(): string
    {
        if ($this->isRoot()) {
            return 'index';
        }

        if ($this->isIndex()) {
            return basename(dirname($this->path));
        }

        return basename($this->path);
    }

    /**
     * Ancestor folder segments. e.g. `guide/advanced/routing` → [guide, advanced].
     */
    public function ancestry(): array
    {
        if ($this->isRoot()) {
            return [];
        }

        $folder = $this->isIndex()
            ? dirname(dirname($this->path))
            : dirname($this->path);

        return $folder === '.' ? [] : explode('/', $folder);
    }

    /**
     * URI path segments for public URLs (empty string for the root).
     */
    public function uriPath(): string
    {
        if ($this->isRoot()) {
            return '';
        }

        return implode('/', [...$this->ancestry(), $this->slug()]);
    }

    public function title(): string
    {
        return $this->data['title']
            ?? Str::title(str_replace('-', ' ', $this->slug()));
    }

    public function data(): array
    {
        return $this->data;
    }

    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function remove(string $key): self
    {
        unset($this->data[$key]);

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Front matter plus the body, keyed for blueprint fields.
     */
    public function values(): array
    {
        return array_merge($this->data, [
            'slug' => $this->slug(),
            'url_slug' => $this->data['slug'] ?? null,
            'url_overrides' => filled($this->data['slug'] ?? null) || filled($this->data['redirect'] ?? null),
            'content' => $this->content,
        ]);
    }

    public function blueprint()
    {
        return $this->source()->blueprint();
    }

    public function editUrl(): string
    {
        return cp_route('sidecar.documents.edit', [$this->sourceHandle, $this->path]);
    }

    public function updateUrl(): string
    {
        return cp_route('sidecar.documents.update', [$this->sourceHandle, $this->path]);
    }

    public function deleteUrl(): string
    {
        return cp_route('sidecar.documents.destroy', [$this->sourceHandle, $this->path]);
    }

    public function livePreviewUrl(): ?string
    {
        return empty($this->previewTargets())
            ? null
            : cp_route('sidecar.documents.preview.edit', [$this->sourceHandle, $this->path]);
    }

    public function previewTargets(): array
    {
        return $this->source()->driver()->previewTargets($this);
    }

    public function url(): ?string
    {
        return $this->source()->driver()->url($this);
    }

    public function setSupplement(string $key, $value): self
    {
        $this->supplements[$key] = $value;

        return $this;
    }

    public function getSupplement(string $key)
    {
        return $this->supplements[$key] ?? null;
    }

    public function hasSupplement(string $key): bool
    {
        return array_key_exists($key, $this->supplements);
    }

    /**
     * A value with Live Preview supplements (WIP edits) taking precedence.
     */
    public function value(string $key)
    {
        if ($this->hasSupplement($key)) {
            return $this->getSupplement($key);
        }

        return $this->values()[$key] ?? null;
    }
}
