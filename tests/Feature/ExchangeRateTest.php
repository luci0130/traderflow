<?php

namespace Tests\Feature;

use App\Models\CurrencyExchangeRate;
use App\Support\ExchangeRate;
use App\Support\RefreshCurrencyRates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_bnr_feed_populates_every_platform_currency_pair_and_its_inverse(): void
    {
        Http::fake(['*' => Http::response($this->feed())]);

        $result = app(RefreshCurrencyRates::class)->refresh();

        $this->assertSame(['pairs' => 12, 'date' => '2026-11-05'], $result);
        $this->assertDatabaseCount('currency_exchange_rates', 12);
        $rates = app(ExchangeRate::class);
        $this->assertSame(5.0, $rates->rate('EUR', 'RON'));
        $this->assertSame(0.2, $rates->rate('RON', 'EUR'));
        $this->assertSame(1.25, $rates->rate('EUR', 'USD'));
        $this->assertSame(1.5, $rates->rate('GBP', 'USD'));
        $this->assertSame(12.0, $rates->convert(2, 'GBP', 'RON'));
        $this->assertSame(8.0, $rates->convert(2, 'USD', 'RON'));
        $this->assertSame(6.4, $rates->convert(8, 'USD', 'EUR'));
        Http::assertSentCount(1);
    }

    public function test_refresh_is_idempotent_and_updates_same_day_rates(): void
    {
        Http::fakeSequence()->push($this->feed())->push($this->feed('5.2'));

        $refresher = app(RefreshCurrencyRates::class);
        $refresher->refresh();
        $this->assertSame(5.0, app(ExchangeRate::class)->rate('EUR', 'RON'));
        $refresher->refresh();

        $this->assertDatabaseCount('currency_exchange_rates', 12);
        $this->assertSame(5.2, app(ExchangeRate::class)->rate('EUR', 'RON'));
    }

    public function test_history_is_retained_and_conversion_uses_the_latest_publication(): void
    {
        Http::fakeSequence()
            ->push($this->feed(date: '2026-11-04'))
            ->push($this->feed('5.2'));

        $refresher = app(RefreshCurrencyRates::class);
        $refresher->refresh();
        $refresher->refresh();

        $this->assertDatabaseCount('currency_exchange_rates', 24);
        $this->assertSame(5.2, app(ExchangeRate::class)->rate('EUR', 'RON'));
    }

    public function test_older_feed_never_replaces_a_newer_conversion_rate(): void
    {
        Http::fakeSequence()
            ->push($this->feed('5.2'))
            ->push($this->feed(date: '2026-11-04'));

        $refresher = app(RefreshCurrencyRates::class);
        $refresher->refresh();
        $refresher->refresh();

        $this->assertSame(5.2, app(ExchangeRate::class)->rate('EUR', 'RON'));
    }

    public function test_last_saved_rates_remain_usable_when_the_api_is_down(): void
    {
        CurrencyExchangeRate::factory()->create(['rate_date' => '2026-11-04']);
        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('currency:refresh')->assertFailed();
        $this->assertSame(5.0, app(ExchangeRate::class)->rate('EUR', 'RON'));
        $this->assertDatabaseCount('currency_exchange_rates', 1);
    }

    public function test_missing_rates_preserve_same_currency_and_return_null_for_unknown_pairs(): void
    {
        $rates = app(ExchangeRate::class);

        $this->assertSame(10.0, $rates->convert(10, 'RON', 'RON'));
        $this->assertSame(0.0, $rates->convert(0, 'EUR', 'RON'));
        $this->assertNull($rates->convert(10, 'USD', 'RON'));
        $this->assertNull($rates->convert(10, 'XYZ', 'RON'));
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function invalidFeeds(): array
    {
        return [
            'malformed XML' => ['<broken'],
            'HTML response' => ['<html><body>Unavailable</body></html>'],
            'invalid date' => ['<DataSet><Body><OrigCurrency>RON</OrigCurrency><Cube date="2026-02-30" /></Body></DataSet>'],
            'missing platform currencies' => ['<DataSet><Body><OrigCurrency>RON</OrigCurrency><Cube date="2026-11-05"><Rate currency="EUR">5</Rate></Cube></Body></DataSet>'],
            'zero rate' => ['<DataSet><Body><OrigCurrency>RON</OrigCurrency><Cube date="2026-11-05"><Rate currency="EUR">0</Rate></Cube></Body></DataSet>'],
            'negative rate' => ['<DataSet><Body><OrigCurrency>RON</OrigCurrency><Cube date="2026-11-05"><Rate currency="EUR">-1</Rate></Cube></Body></DataSet>'],
        ];
    }

    #[DataProvider('invalidFeeds')]
    public function test_invalid_feeds_never_partially_replace_saved_pairs(string $xml): void
    {
        CurrencyExchangeRate::factory()->create();
        Http::fake(['*' => Http::response($xml)]);

        $this->artisan('currency:refresh')->assertFailed();
        $this->assertDatabaseCount('currency_exchange_rates', 1);
        $this->assertSame(5.0, app(ExchangeRate::class)->rate('EUR', 'RON'));
    }

    public function test_feed_multiplier_is_applied_before_cross_rates_are_calculated(): void
    {
        config(['currency.currencies' => ['EUR', 'RON', 'USD', 'GBP', 'HUF']]);
        $xml = str_replace('</Cube>', '<Rate currency="HUF" multiplier="100">1.5</Rate></Cube>', $this->feed());
        Http::fake(['*' => Http::response($xml)]);

        app(RefreshCurrencyRates::class)->refresh();

        $this->assertDatabaseCount('currency_exchange_rates', 20);
        $this->assertSame(1.5, app(ExchangeRate::class)->convert(100, 'HUF', 'RON'));
    }

    public function test_refresh_command_reports_pair_count_and_publication_date(): void
    {
        Http::fake(['*' => Http::response($this->feed())]);

        $this->artisan('currency:refresh')->expectsOutput('Updated 12 currency pairs (2026-11-05).')->assertSuccessful();
    }

    private function feed(string $eur = '5', string $date = '2026-11-05'): string
    {
        return '<?xml version="1.0"?><DataSet xmlns="https://www.bnr.ro/xsd"><Body><OrigCurrency>RON</OrigCurrency><Cube date="'.$date.'"><Rate currency="EUR">'.$eur.'</Rate><Rate currency="USD">4</Rate><Rate currency="GBP">6</Rate></Cube></Body></DataSet>';
    }
}
