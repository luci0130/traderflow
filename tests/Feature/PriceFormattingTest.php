<?php

namespace Tests\Feature;

use App\Models\CurrencyExchangeRate;
use App\Models\User;
use App\Support\PriceFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PriceFormattingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CurrencyExchangeRate::factory()->create();
        CurrencyExchangeRate::factory()->inverse()->create();
        Http::preventStrayRequests();
    }

    /** @return array<string, array{float|int|string|null, string, string, string}> */
    public static function prices(): array
    {
        return [
            'same RON' => [4.1, 'RON', 'RON', '4.10 RON'],
            'same EUR' => [0.92, 'EUR', 'EUR', '0.92 EUR'],
            'EUR to RON' => ['0.9200', 'EUR', 'RON', '4.60 RON (0.92 €)'],
            'RON to EUR' => [4.6, 'RON', 'EUR', '0.92 EUR (4.60 RON)'],
            'round at display' => [1.2345, 'EUR', 'RON', '6.17 RON (1.23 €)'],
            'zero' => [0, 'EUR', 'RON', '0.00 RON (0.00 €)'],
            'negative' => [-2, 'EUR', 'RON', '-10.00 RON (-2.00 €)'],
            'missing price' => [null, 'EUR', 'RON', '—'],
            'unsupported source' => [2, 'USD', 'RON', '2.00 USD (Exchange rate unavailable)'],
        ];
    }

    #[DataProvider('prices')]
    public function test_prices_use_the_requested_currency(float|int|string|null $amount, string $source, string $target, string $expected): void
    {
        $this->assertSame($expected, app(PriceFormatter::class)->format($amount, $source, $target));
    }

    public function test_formatter_uses_the_authenticated_users_persisted_preference(): void
    {
        $user = User::factory()->create(['preferred_currency' => 'EUR']);
        $this->actingAs($user);

        $this->assertSame('2.00 EUR (10.00 RON)', app(PriceFormatter::class)->format(10, 'RON'));
    }

    public function test_original_price_can_be_omitted_for_computed_amounts(): void
    {
        $this->assertSame('10.00 RON', app(PriceFormatter::class)->format(2, 'EUR', 'RON', showOriginal: false));
    }

    public function test_blade_component_reuses_the_formatter_and_merges_attributes(): void
    {
        $html = Blade::render('<x-price amount="0.92" currency="EUR" class="font-semibold" />');

        $this->assertStringContainsString('4.60 RON (0.92 €)', $html);
        $this->assertStringContainsString('tabular-nums font-semibold', $html);
    }

    public function test_missing_rate_preserves_the_original_currency(): void
    {
        CurrencyExchangeRate::query()->delete();
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertSame('0.92 EUR (Exchange rate unavailable)', app(PriceFormatter::class)->format(0.92, 'EUR', 'RON'));
    }
}
