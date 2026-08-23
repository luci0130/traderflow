<?php

namespace Turiac\FilamentImageGallery;

use Filament\Contracts\Plugin;
use Filament\Panel;

class FilamentImageGalleryPlugin implements Plugin
{
    protected ?string $directory = null;

    protected ?string $disk = null;

    protected ?int $maxImages = null;

    protected ?int $maxParallelUploads = null;

    protected ?bool $optimizeImages = null;

    /** @var array<string, string> | null */
    protected ?array $roles = null;

    /** @var array<int, string> | null */
    protected ?array $defaultRoles = null;

    /** @var array<int, string> | null */
    protected ?array $tools = null;

    protected ?string $visibility = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'filament-image-gallery';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}

    public function directory(string $directory): static
    {
        $this->directory = trim($directory, '/');

        return $this;
    }

    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function maxImages(int $maxImages): static
    {
        $this->maxImages = max(1, $maxImages);

        return $this;
    }

    public function maxParallelUploads(int $maxParallelUploads): static
    {
        $this->maxParallelUploads = max(1, $maxParallelUploads);

        return $this;
    }

    public function optimizeImages(bool $optimizeImages = true): static
    {
        $this->optimizeImages = $optimizeImages;

        return $this;
    }

    /** @param  array<string, string>  $roles */
    public function roles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /** @param  array<int, string>  $defaultRoles */
    public function defaultRoles(array $defaultRoles): static
    {
        $this->defaultRoles = array_values($defaultRoles);

        return $this;
    }

    /** @param  array<int, string>  $tools */
    public function tools(array $tools): static
    {
        $this->tools = array_values($tools);

        return $this;
    }

    public function visibility(string $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getDirectory(): ?string
    {
        return $this->directory;
    }

    public function getDisk(): ?string
    {
        return $this->disk;
    }

    public function getMaxImages(): ?int
    {
        return $this->maxImages;
    }

    public function getMaxParallelUploads(): ?int
    {
        return $this->maxParallelUploads;
    }

    public function getOptimizeImages(): ?bool
    {
        return $this->optimizeImages;
    }

    /** @return array<string, string> | null */
    public function getRoles(): ?array
    {
        return $this->roles;
    }

    /** @return array<int, string> | null */
    public function getDefaultRoles(): ?array
    {
        return $this->defaultRoles;
    }

    /** @return array<int, string> | null */
    public function getTools(): ?array
    {
        return $this->tools;
    }

    public function getVisibility(): ?string
    {
        return $this->visibility;
    }
}
