<?php

namespace App\Modules\Customers\Models;

use App\Models\Tenant;
use App\Modules\Customers\Enums\CustomerLocationType;
use Database\Factories\CustomerLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLocation extends Model
{
    /** @use HasFactory<CustomerLocationFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'name',
        'type',
        'is_separate_legal_entity',
        'legal_name',
        'fiscal_code',
        'bank_name',
        'bank_account',
        'country',
        'county',
        'city',
        'address',
        'notes',
    ];

    protected $attributes = [
        'type' => 'supermarket',
    ];

    protected static function newFactory(): CustomerLocationFactory
    {
        return CustomerLocationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => CustomerLocationType::class,
            'is_separate_legal_entity' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function displayName(): string
    {
        return collect([
            $this->name,
            $this->city,
            $this->address,
        ])
            ->filter()
            ->join(' - ');
    }
}
