<?php

namespace App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages;

use App\Modules\ProductCategories\Filament\Resources\ProductCategories\ProductCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductCategory extends CreateRecord
{
    protected static string $resource = ProductCategoryResource::class;
}
