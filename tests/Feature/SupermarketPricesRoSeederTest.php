<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Database\Seeders\SupermarketPricesRoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupermarketPricesRoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_romanian_supermarket_as_a_global_customer(): void
    {
        $this->seed(SupermarketPricesRoSeeder::class);

        $expected = [
            'Carrefour', 'Auchan', 'Lidl', 'Penny', 'Profi', 'Mega Image',
            'Kaufland', 'Selgros', 'Metro', 'Home Garden',
            'Sezamo', 'Freshful', 'Bringo',
        ];

        $this->assertSame(13, Customer::query()->global()->count());

        foreach ($expected as $name) {
            $supermarket = Customer::query()->global()->where('name', $name)->first();

            $this->assertNotNull($supermarket, "expected supermarket '{$name}' to be seeded");
            $this->assertNull($supermarket->tenant_id);
        }
    }

    public function test_supermarkets_carry_real_company_data(): void
    {
        $this->seed(SupermarketPricesRoSeeder::class);

        $carrefour = Customer::query()->global()->where('name', 'Carrefour')->firstOrFail();
        $this->assertSame('CARREFOUR ROMANIA SA', $carrefour->legal_name);
        $this->assertSame('RO11588780', $carrefour->vat_number);
        $this->assertSame('București', $carrefour->city);
        $this->assertStringContainsString('carrefour.ro', (string) $carrefour->notes);

        $profi = Customer::query()->global()->where('name', 'Profi')->firstOrFail();
        $this->assertSame('PROFI ROM FOOD SRL', $profi->legal_name);
        $this->assertSame('RO11607939', $profi->vat_number);
        $this->assertSame('Timișoara', $profi->city);

        $freshful = Customer::query()->global()->where('name', 'Freshful')->firstOrFail();
        $this->assertSame('EMAG RETAIL SRL', $freshful->legal_name);
        $this->assertSame('RO44231872', $freshful->vat_number);

        // Home Garden has no reliable public CUI, so vat_number stays null rather than fabricated.
        $homeGarden = Customer::query()->global()->where('name', 'Home Garden')->firstOrFail();
        $this->assertNull($homeGarden->vat_number);
    }

    public function test_it_seeds_products_with_packaging_caliber_and_origin(): void
    {
        $this->seed(SupermarketPricesRoSeeder::class);

        $cherry = SupermarketProduct::query()
            ->where('name', 'Roșii Cherry')
            ->where('origin', 'Turcia')
            ->first();

        $this->assertNotNull($cherry);
        $this->assertSame('Roșii', $cherry->category);
        $this->assertSame('250.0000', $cherry->package_size);
        $this->assertSame('g', $cherry->package_unit);
        $this->assertSame('10 - 14', $cherry->caliber);

        $bulkTomato = SupermarketProduct::query()
            ->where('name', 'Roșii Rotundă normală')
            ->where('origin', 'România')
            ->first();

        $this->assertNotNull($bulkTomato);
        $this->assertNull($bulkTomato->package_size);
        $this->assertSame('kg', $bulkTomato->package_unit);
    }

    public function test_it_records_prices_per_supermarket_with_the_observed_date(): void
    {
        $this->seed(SupermarketPricesRoSeeder::class);

        $cucumber = SupermarketProduct::query()
            ->where('name', 'Castraveți Cornichon')
            ->where('origin', 'România')
            ->firstOrFail();

        $this->assertSame(3, $cucumber->prices()->count());

        $carrefour = Customer::query()->global()->where('name', 'Carrefour')->firstOrFail();
        $price = SupermarketPrice::query()
            ->where('supermarket_product_id', $cucumber->id)
            ->where('supermarket_id', $carrefour->id)
            ->firstOrFail();

        $this->assertSame('4.9900', $price->price);
        $this->assertSame('RON', $price->currency);
        $this->assertSame(SupermarketPrice::SOURCE_MANUAL, $price->source);
        $this->assertSame('2026-05-26', $price->observed_at->toDateString());
    }

    public function test_running_the_seeder_twice_does_not_duplicate_records(): void
    {
        $this->seed(SupermarketPricesRoSeeder::class);

        $supermarkets = Customer::query()->global()->count();
        $products = SupermarketProduct::query()->count();
        $prices = SupermarketPrice::query()->count();

        $this->seed(SupermarketPricesRoSeeder::class);

        $this->assertSame($supermarkets, Customer::query()->global()->count());
        $this->assertSame($products, SupermarketProduct::query()->count());
        $this->assertSame($prices, SupermarketPrice::query()->count());
    }
}
