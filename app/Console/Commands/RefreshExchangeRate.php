<?php

namespace App\Console\Commands;

use App\Support\RefreshCurrencyRates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('currency:refresh')]
#[Description('Refresh all platform currency pairs from the free BNR feed')]
class RefreshExchangeRate extends Command
{
    public function handle(RefreshCurrencyRates $refreshCurrencyRates): int
    {
        try {
            $data = $refreshCurrencyRates->refresh();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Currency rates could not be refreshed. Previous rates are retained.');

            return self::FAILURE;
        }

        $this->info("Updated {$data['pairs']} currency pairs ({$data['date']}).");

        return self::SUCCESS;
    }
}
