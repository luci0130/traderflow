<?php

namespace App\Modules\NumberSequences\Filament\Resources\NumberSequences\Pages;

use App\Modules\NumberSequences\Filament\Resources\NumberSequences\NumberSequenceResource;
use Filament\Resources\Pages\EditRecord;

class EditNumberSequence extends EditRecord
{
    protected static string $resource = NumberSequenceResource::class;
}
