<?php

namespace App\Modules\Producers\Filament\Resources\ProducerOrders\Pages;

use App\Modules\Producers\Filament\Resources\ProducerOrders\ProducerOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListProducerOrders extends ListRecords
{
    protected static string $resource = ProducerOrderResource::class;
}
