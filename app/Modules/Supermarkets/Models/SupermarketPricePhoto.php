<?php

namespace App\Modules\Supermarkets\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerLocation;
use Database\Factories\SupermarketPricePhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class SupermarketPricePhoto extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_DONE = 'done';

    protected $guarded = [];

    protected static function newFactory(): SupermarketPricePhotoFactory
    {
        return SupermarketPricePhotoFactory::new();
    }

    protected function casts(): array
    {
        return [
            'taken_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (SupermarketPricePhoto $photo): void {
            if (filled($photo->path)) {
                Storage::disk('public')->delete($photo->path);
            }
        });
    }

    /**
     * Delete the stored file while keeping the row, which stays as the audit
     * trail for the prices that were read from it.
     */
    public function purgeFile(): bool
    {
        if (blank($this->path)) {
            return false;
        }

        Storage::disk('public')->delete($this->path);

        $this->forceFill(['path' => null])->save();

        return true;
    }

    public function hasFile(): bool
    {
        return filled($this->path);
    }

    public function supermarket(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'supermarket_id')->withoutGlobalScope('active_tenant');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(CustomerLocation::class, 'customer_location_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SupermarketPrice::class);
    }
}
