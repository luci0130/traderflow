<?php

namespace App\Modules\NumberSequences\Services;

use App\Modules\NumberSequences\Models\NumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Hands out the next document number for a tenant + type, incrementing the
 * sequence atomically. Sequences are auto-created from config defaults the first
 * time they are used.
 */
class NumberSequenceGenerator
{
    /**
     * Reserve and return the next formatted number for the tenant + type.
     */
    public function next(int $tenantId, string $key): string
    {
        return DB::transaction(function () use ($tenantId, $key): string {
            $sequence = NumberSequence::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first()
                ?? $this->createDefault($tenantId, $key);

            $value = (int) $sequence->next_number;
            $formatted = $sequence->format($value);

            $sequence->next_number = $value + max(1, (int) $sequence->step);
            $sequence->save();

            return $formatted;
        });
    }

    /**
     * Make sure every configured sequence type exists for the tenant (used to
     * populate the settings list).
     */
    public function ensureDefaultsFor(int $tenantId): void
    {
        foreach (array_keys(config('number_sequences.types', [])) as $key) {
            NumberSequence::query()
                ->withoutGlobalScopes()
                ->firstOrCreate(
                    ['tenant_id' => $tenantId, 'key' => $key],
                    $this->defaults($key),
                );
        }
    }

    private function createDefault(int $tenantId, string $key): NumberSequence
    {
        return NumberSequence::query()
            ->withoutGlobalScopes()
            ->create(['tenant_id' => $tenantId, 'key' => $key] + $this->defaults($key));
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(string $key): array
    {
        $config = config("number_sequences.types.{$key}", []);

        return [
            'prefix' => $config['prefix'] ?? '',
            'suffix' => null,
            'padding' => $config['padding'] ?? 5,
            'next_number' => 1,
            'step' => 1,
        ];
    }
}
