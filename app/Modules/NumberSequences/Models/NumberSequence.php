<?php

namespace App\Modules\NumberSequences\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-tenant, per-document-type number sequence. Numbers are formatted as
 * prefix + zero-padded counter + suffix, e.g. "OC-01412".
 */
class NumberSequence extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'padding' => 'integer',
            'next_number' => 'integer',
            'step' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Format a counter value into the full document number.
     */
    public function format(int $value): string
    {
        $padding = max(0, (int) $this->padding);

        return ($this->prefix ?? '')
            .str_pad((string) $value, $padding, '0', STR_PAD_LEFT)
            .($this->suffix ?? '');
    }

    /**
     * Preview of the number that will be assigned next.
     */
    public function preview(): string
    {
        return $this->format((int) $this->next_number);
    }

    public function typeLabel(): string
    {
        return __(config("number_sequences.types.{$this->key}.label", $this->key));
    }
}
