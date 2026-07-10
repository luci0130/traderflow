<?php

namespace App\Modules\TenantSettings\Services;

use App\Models\Tenant;
use App\Modules\TenantSettings\Models\TenantSetting;
use Illuminate\Support\Collection;

/**
 * Reads and writes a tenant's bank accounts, stored as a JSON tenant setting so
 * offers can list them without a dedicated schema. Each account is
 * {bank, iban, currency}.
 */
class TenantBankAccounts
{
    public const KEY = 'offer_bank_accounts';

    /**
     * @return array<int, array{bank: string, iban: string, currency: string}>
     */
    public static function get(Tenant $tenant): array
    {
        $raw = TenantSetting::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', self::KEY)
            ->value('value');

        if (blank($raw)) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? self::normalize($decoded) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    public static function set(Tenant $tenant, array $accounts): void
    {
        TenantSetting::query()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'key' => self::KEY],
            ['value' => json_encode(self::normalize($accounts))],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<int, array{bank: string, iban: string, currency: string}>
     */
    private static function normalize(array $accounts): array
    {
        return (new Collection($accounts))
            ->map(fn ($entry): array => [
                'bank' => trim((string) ($entry['bank'] ?? '')),
                'iban' => trim((string) ($entry['iban'] ?? '')),
                'currency' => trim((string) ($entry['currency'] ?? '')),
            ])
            ->filter(fn (array $entry): bool => $entry['bank'] !== '' || $entry['iban'] !== '')
            ->values()
            ->all();
    }
}
