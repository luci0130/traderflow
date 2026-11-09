<?php

namespace App\Modules\Supermarkets\Models;

use App\Modules\Supermarkets\Services\SupermarketCompanyResolver;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SupermarketProductCompany extends Pivot
{
    protected $table = 'supermarket_product_companies';

    public $incrementing = true;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $company): void {
            $company->forceFill(app(SupermarketCompanyResolver::class)->resolve(
                $company->supplier_lead_id,
                $company->supplier_id,
            ));
        });
    }
}
