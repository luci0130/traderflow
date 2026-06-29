<?php

namespace App\Modules\Products\Filament\Resources\PackagingMethods\Pages;

use App\Modules\Products\Filament\Resources\PackagingMethods\PackagingMethodResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePackagingMethod extends CreateRecord
{
    protected static string $resource = PackagingMethodResource::class;
}
