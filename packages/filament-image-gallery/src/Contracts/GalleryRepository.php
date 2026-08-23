<?php

namespace Turiac\FilamentImageGallery\Contracts;

use Illuminate\Database\Eloquent\Model;

interface GalleryRepository
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function stateFor(Model $owner, string $collection): array;

    /**
     * @param  array<string, array<string, mixed>>  $state
     * @param  array<string, string>  $configuredRoles
     */
    public function persist(
        Model $owner,
        string $collection,
        array $state,
        array $configuredRoles,
        string $disk,
        string $visibility,
        string $directory = '',
        string $statePath = 'images',
        array $trustedPaths = [],
    ): void;
}
