<?php

namespace Database\Seeders;

use App\Modules\Customers\Models\Customer;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Realistic "Legume și Fructe" catalogue modelled on a Carrefour / Bringo
 * fruit-and-vegetable aisle. Wipes the current product data and rebuilds both
 * sides of the market:
 *
 *  - Supermarket products + observed shelf prices across the major RO chains
 *    (always Carrefour and Bringo, plus a rotating set of competitors), with
 *    brand, origin, caliber, quality, packaging, VAT and bio flags filled in.
 *  - Supplier products for the tenants' suppliers, with wholesale tiered prices,
 *    per-product sourcing cost overrides, packaging, validity and bio flags.
 *
 * The seeder is destructive by design (the user asked to replace the current
 * products) but safe to re-run: it truncates the produce tables first.
 */
class LegumeFructeCatalogSeeder extends Seeder
{
    private const CURRENCY = 'RON';

    private const VAT_RATE = 11.0;

    /** Retail multiplier vs. the Carrefour shelf price, per chain. */
    private const CHAIN_FACTORS = [
        'Carrefour' => 1.00,
        'Bringo' => 1.06,
        'Auchan' => 0.97,
        'Kaufland' => 0.95,
        'Lidl' => 0.93,
        'Mega Image' => 1.07,
        'Profi' => 1.04,
        'Penny' => 0.96,
        'Freshful' => 1.05,
        'Sezamo' => 1.08,
    ];

    /** Competitor chains rotated in alongside the always-present Carrefour + Bringo. */
    private const ROTATING_CHAINS = ['Auchan', 'Kaufland', 'Lidl', 'Mega Image', 'Profi', 'Penny', 'Freshful', 'Sezamo'];

    /**
     * The produce catalogue. Each entry:
     *   name, group (Legume/Fructe), category, variety, origin (country),
     *   caliber, quality, package (shelf pack), packaging (method name),
     *   unit (supplier sale unit), bio, retail (Carrefour shelf RON for the
     *   package), wholesale (supplier RON per unit), brand.
     *
     * @var list<array{name:string,group:string,category:string,variety:?string,origin:string,caliber:?string,quality:string,package:string,packaging:string,unit:string,bio:bool,retail:float,wholesale:float,brand:string}>
     */
    private const ITEMS = [
        // ---- Legume ----
        ['name' => 'Roșii cherry', 'group' => 'Legume', 'category' => 'Roșii', 'variety' => 'Cherry', 'origin' => 'Turcia', 'caliber' => '25-35 mm', 'quality' => 'Categoria I', 'package' => '250 g', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => false, 'retail' => 9.99, 'wholesale' => 14.50, 'brand' => 'Carrefour'],
        ['name' => 'Roșii inimă de bou', 'group' => 'Legume', 'category' => 'Roșii', 'variety' => 'Inimă de bou', 'origin' => 'România', 'caliber' => '80-100 mm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 12.49, 'wholesale' => 7.20, 'brand' => 'Carrefour'],
        ['name' => 'Roșii bio', 'group' => 'Legume', 'category' => 'Roșii', 'variety' => null, 'origin' => 'România', 'caliber' => '60-80 mm', 'quality' => 'Extra', 'package' => '500 g', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => true, 'retail' => 14.99, 'wholesale' => 11.90, 'brand' => 'Carrefour Bio'],
        ['name' => 'Castraveți lungi', 'group' => 'Legume', 'category' => 'Castraveți', 'variety' => 'Lung', 'origin' => 'România', 'caliber' => '300-400 g', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 7.49, 'wholesale' => 4.30, 'brand' => 'Carrefour'],
        ['name' => 'Castraveți cornichon', 'group' => 'Legume', 'category' => 'Castraveți', 'variety' => 'Cornichon', 'origin' => 'Polonia', 'caliber' => '6-9 cm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 9.99, 'wholesale' => 6.10, 'brand' => 'Carrefour'],
        ['name' => 'Ardei kapia', 'group' => 'Legume', 'category' => 'Ardei', 'variety' => 'Kapia', 'origin' => 'România', 'caliber' => '120-160 g', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 13.99, 'wholesale' => 8.40, 'brand' => 'Carrefour'],
        ['name' => 'Ardei gras roșu', 'group' => 'Legume', 'category' => 'Ardei', 'variety' => 'Gras roșu', 'origin' => 'Spania', 'caliber' => '70-90 mm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 11.99, 'wholesale' => 7.10, 'brand' => 'Carrefour'],
        ['name' => 'Ardei gras galben', 'group' => 'Legume', 'category' => 'Ardei', 'variety' => 'Gras galben', 'origin' => 'Olanda', 'caliber' => '70-90 mm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 12.99, 'wholesale' => 7.80, 'brand' => 'Carrefour'],
        ['name' => 'Cartofi albi', 'group' => 'Legume', 'category' => 'Cartofi', 'variety' => 'Alb', 'origin' => 'România', 'caliber' => '40-70 mm', 'quality' => 'Categoria II', 'package' => 'sac 5 kg', 'packaging' => 'Sac', 'unit' => 'kg', 'bio' => false, 'retail' => 16.99, 'wholesale' => 2.10, 'brand' => 'Carrefour'],
        ['name' => 'Cartofi noi', 'group' => 'Legume', 'category' => 'Cartofi', 'variety' => 'Noi', 'origin' => 'Egipt', 'caliber' => '30-50 mm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Plasă', 'unit' => 'kg', 'bio' => false, 'retail' => 5.49, 'wholesale' => 3.20, 'brand' => 'Carrefour'],
        ['name' => 'Cartofi dulci', 'group' => 'Legume', 'category' => 'Cartofi', 'variety' => 'Dulce', 'origin' => 'Egipt', 'caliber' => '200-400 g', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 9.99, 'wholesale' => 6.30, 'brand' => 'Carrefour'],
        ['name' => 'Ceapă galbenă', 'group' => 'Legume', 'category' => 'Ceapă', 'variety' => 'Galbenă', 'origin' => 'România', 'caliber' => '50-70 mm', 'quality' => 'Categoria I', 'package' => 'plasă 2 kg', 'packaging' => 'Plasă', 'unit' => 'kg', 'bio' => false, 'retail' => 7.49, 'wholesale' => 1.90, 'brand' => 'Carrefour'],
        ['name' => 'Ceapă roșie', 'group' => 'Legume', 'category' => 'Ceapă', 'variety' => 'Roșie', 'origin' => 'Olanda', 'caliber' => '50-70 mm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Plasă', 'unit' => 'kg', 'bio' => false, 'retail' => 5.99, 'wholesale' => 3.40, 'brand' => 'Carrefour'],
        ['name' => 'Usturoi', 'group' => 'Legume', 'category' => 'Usturoi', 'variety' => null, 'origin' => 'China', 'caliber' => '55-65 mm', 'quality' => 'Categoria I', 'package' => 'plasă 250 g', 'packaging' => 'Plasă', 'unit' => 'kg', 'bio' => false, 'retail' => 8.99, 'wholesale' => 18.50, 'brand' => 'Carrefour'],
        ['name' => 'Morcovi', 'group' => 'Legume', 'category' => 'Morcovi', 'variety' => null, 'origin' => 'România', 'caliber' => '20-40 mm', 'quality' => 'Categoria I', 'package' => 'plasă 1 kg', 'packaging' => 'Plasă', 'unit' => 'kg', 'bio' => false, 'retail' => 4.99, 'wholesale' => 2.30, 'brand' => 'Carrefour'],
        ['name' => 'Țelină rădăcină', 'group' => 'Legume', 'category' => 'Țelină', 'variety' => 'Rădăcină', 'origin' => 'România', 'caliber' => '300-500 g', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Vrac', 'unit' => 'kg', 'bio' => false, 'retail' => 6.49, 'wholesale' => 3.70, 'brand' => 'Carrefour'],
        ['name' => 'Varză albă', 'group' => 'Legume', 'category' => 'Varză', 'variety' => 'Albă', 'origin' => 'România', 'caliber' => '1-2 kg', 'quality' => 'Categoria I', 'package' => 'buc', 'packaging' => 'Bucată', 'unit' => 'kg', 'bio' => false, 'retail' => 4.99, 'wholesale' => 1.60, 'brand' => 'Carrefour'],
        ['name' => 'Conopidă', 'group' => 'Legume', 'category' => 'Conopidă', 'variety' => null, 'origin' => 'Italia', 'caliber' => '600-1000 g', 'quality' => 'Categoria I', 'package' => 'buc', 'packaging' => 'Bucată', 'unit' => 'buc', 'bio' => false, 'retail' => 9.99, 'wholesale' => 5.80, 'brand' => 'Carrefour'],
        ['name' => 'Broccoli', 'group' => 'Legume', 'category' => 'Broccoli', 'variety' => null, 'origin' => 'Spania', 'caliber' => '400-600 g', 'quality' => 'Categoria I', 'package' => 'buc', 'packaging' => 'Bucată', 'unit' => 'buc', 'bio' => false, 'retail' => 8.99, 'wholesale' => 5.20, 'brand' => 'Carrefour'],
        ['name' => 'Vinete', 'group' => 'Legume', 'category' => 'Vinete', 'variety' => null, 'origin' => 'Spania', 'caliber' => '250-350 g', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 9.49, 'wholesale' => 5.60, 'brand' => 'Carrefour'],
        ['name' => 'Dovlecei', 'group' => 'Legume', 'category' => 'Dovlecei', 'variety' => null, 'origin' => 'România', 'caliber' => '200-300 g', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 6.99, 'wholesale' => 4.10, 'brand' => 'Carrefour'],
        ['name' => 'Spanac', 'group' => 'Legume', 'category' => 'Spanac', 'variety' => null, 'origin' => 'România', 'caliber' => null, 'quality' => 'Extra', 'package' => '200 g', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => true, 'retail' => 7.99, 'wholesale' => 12.40, 'brand' => 'Carrefour Bio'],
        ['name' => 'Salată iceberg', 'group' => 'Legume', 'category' => 'Salată', 'variety' => 'Iceberg', 'origin' => 'Italia', 'caliber' => '400-600 g', 'quality' => 'Categoria I', 'package' => 'buc', 'packaging' => 'Bucată', 'unit' => 'buc', 'bio' => false, 'retail' => 4.49, 'wholesale' => 2.50, 'brand' => 'Carrefour'],
        ['name' => 'Ciuperci champignon', 'group' => 'Legume', 'category' => 'Ciuperci', 'variety' => 'Champignon', 'origin' => 'România', 'caliber' => '30-50 mm', 'quality' => 'Categoria I', 'package' => '400 g', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => false, 'retail' => 6.99, 'wholesale' => 10.20, 'brand' => 'Carrefour'],

        // ---- Fructe ----
        ['name' => 'Mere Golden Delicious', 'group' => 'Fructe', 'category' => 'Mere', 'variety' => 'Golden Delicious', 'origin' => 'România', 'caliber' => '70-80 mm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 4.99, 'wholesale' => 2.80, 'brand' => 'Carrefour'],
        ['name' => 'Mere Royal Gala', 'group' => 'Fructe', 'category' => 'Mere', 'variety' => 'Royal Gala', 'origin' => 'România', 'caliber' => '65-75 mm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 5.49, 'wholesale' => 3.10, 'brand' => 'Carrefour'],
        ['name' => 'Mere bio', 'group' => 'Fructe', 'category' => 'Mere', 'variety' => 'Idared', 'origin' => 'România', 'caliber' => '70-80 mm', 'quality' => 'Extra', 'package' => 'plasă 1 kg', 'packaging' => 'Plasă', 'unit' => 'kg', 'bio' => true, 'retail' => 9.99, 'wholesale' => 6.50, 'brand' => 'Carrefour Bio'],
        ['name' => 'Pere Williams', 'group' => 'Fructe', 'category' => 'Pere', 'variety' => 'Williams', 'origin' => 'Italia', 'caliber' => '60-75 mm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 8.49, 'wholesale' => 5.10, 'brand' => 'Carrefour'],
        ['name' => 'Banane', 'group' => 'Fructe', 'category' => 'Banane', 'variety' => 'Cavendish', 'origin' => 'Ecuador', 'caliber' => '20-24 cm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => false, 'retail' => 7.99, 'wholesale' => 4.60, 'brand' => 'Chiquita'],
        ['name' => 'Banane bio', 'group' => 'Fructe', 'category' => 'Banane', 'variety' => 'Cavendish', 'origin' => 'Republica Dominicană', 'caliber' => '18-22 cm', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => true, 'retail' => 10.99, 'wholesale' => 6.80, 'brand' => 'Bonita'],
        ['name' => 'Portocale Navelina', 'group' => 'Fructe', 'category' => 'Citrice', 'variety' => 'Navelina', 'origin' => 'Spania', 'caliber' => '6-7', 'quality' => 'Categoria I', 'package' => 'plasă 2 kg', 'packaging' => 'Plasă', 'unit' => 'kg', 'bio' => false, 'retail' => 12.99, 'wholesale' => 3.90, 'brand' => 'Carrefour'],
        ['name' => 'Mandarine Clementine', 'group' => 'Fructe', 'category' => 'Citrice', 'variety' => 'Clementine', 'origin' => 'Spania', 'caliber' => '1-2', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 7.99, 'wholesale' => 4.70, 'brand' => 'Carrefour'],
        ['name' => 'Lămâi Primofiori', 'group' => 'Fructe', 'category' => 'Citrice', 'variety' => 'Primofiori', 'origin' => 'Spania', 'caliber' => '4-5', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 9.99, 'wholesale' => 6.20, 'brand' => 'Carrefour'],
        ['name' => 'Struguri albi fără sâmburi', 'group' => 'Fructe', 'category' => 'Struguri', 'variety' => 'Thompson', 'origin' => 'Grecia', 'caliber' => '16-18 mm', 'quality' => 'Categoria I', 'package' => '500 g', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => false, 'retail' => 9.99, 'wholesale' => 11.80, 'brand' => 'Carrefour'],
        ['name' => 'Căpșuni', 'group' => 'Fructe', 'category' => 'Fructe de pădure', 'variety' => null, 'origin' => 'Grecia', 'caliber' => '25-35 mm', 'quality' => 'Categoria I', 'package' => '500 g', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => false, 'retail' => 14.99, 'wholesale' => 18.50, 'brand' => 'Carrefour'],
        ['name' => 'Afine', 'group' => 'Fructe', 'category' => 'Fructe de pădure', 'variety' => null, 'origin' => 'Maroc', 'caliber' => '12-16 mm', 'quality' => 'Categoria I', 'package' => '125 g', 'packaging' => 'Cutie', 'unit' => 'kg', 'bio' => false, 'retail' => 9.99, 'wholesale' => 42.00, 'brand' => 'Carrefour'],
        ['name' => 'Kiwi Hayward', 'group' => 'Fructe', 'category' => 'Fructe exotice', 'variety' => 'Hayward', 'origin' => 'Grecia', 'caliber' => '25-27', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Ladă', 'unit' => 'kg', 'bio' => false, 'retail' => 11.99, 'wholesale' => 7.40, 'brand' => 'Carrefour'],
        ['name' => 'Pepene roșu', 'group' => 'Fructe', 'category' => 'Pepeni', 'variety' => 'Crimson', 'origin' => 'Grecia', 'caliber' => '4-6 kg', 'quality' => 'Categoria I', 'package' => 'kg', 'packaging' => 'Vrac', 'unit' => 'kg', 'bio' => false, 'retail' => 3.99, 'wholesale' => 1.80, 'brand' => 'Carrefour'],
        ['name' => 'Avocado Hass', 'group' => 'Fructe', 'category' => 'Fructe exotice', 'variety' => 'Hass', 'origin' => 'Peru', 'caliber' => '16-18', 'quality' => 'Categoria I', 'package' => 'buc', 'packaging' => 'Bucată', 'unit' => 'buc', 'bio' => false, 'retail' => 5.49, 'wholesale' => 3.10, 'brand' => 'Carrefour'],
    ];

    public function run(): void
    {
        $packaging = PackagingMethod::query()->pluck('id', 'name')->all();
        $supermarkets = Customer::query()->whereNull('tenant_id')->get()->keyBy('name');
        $suppliers = Supplier::query()->orderBy('id')->get();

        if ($supermarkets->isEmpty() || $suppliers->isEmpty()) {
            $this->command?->warn('LegumeFructeCatalogSeeder skipped: seed supermarkets and suppliers first (run DemoRoDataSeeder).');

            return;
        }

        $this->wipeExistingProducts();

        $observedAt = Carbon::today()->toDateString();
        $supplierCount = $suppliers->count();
        $supermarketProducts = 0;
        $supermarketPrices = 0;
        $supplierProducts = 0;

        foreach (array_values(self::ITEMS) as $index => $item) {
            $packagingId = $packaging[$item['packaging']] ?? null;

            $supermarketProduct = $this->seedSupermarketProduct($item, $packagingId);
            $supermarketProducts++;
            $supermarketPrices += $this->seedSupermarketPrices($item, $supermarketProduct, $supermarkets, $observedAt, $index);

            // Primary supplier offer, plus a competing one for every third item.
            $supplierProducts += $this->seedSupplierProduct($item, $suppliers[$index % $supplierCount], $packagingId, $index, 1.0);

            if ($index % 3 === 0) {
                $competitor = $suppliers[($index + 4) % $supplierCount];
                $supplierProducts += $this->seedSupplierProduct($item, $competitor, $packagingId, $index, 1.05);
            }
        }

        $this->command?->info(sprintf(
            'Legume & Fructe: %d supermarket products, %d shelf prices, %d supplier products.',
            $supermarketProducts,
            $supermarketPrices,
            $supplierProducts,
        ));
    }

    private function wipeExistingProducts(): void
    {
        // Children first so the wipe works regardless of FK cascade support.
        DB::table('supermarket_prices')->delete();
        DB::table('canonical_supermarket_product')->delete();
        SupermarketProduct::query()->delete();

        DB::table('canonical_supplier_product')->delete();
        DB::table('supplier_product_cost_overrides')->delete();
        DB::table('supplier_product_prices')->delete();
        SupplierProduct::query()->delete();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function seedSupermarketProduct(array $item, ?int $packagingId): SupermarketProduct
    {
        [$packageSize, $packageUnit] = $this->parsePackage($item['package']);

        return SupermarketProduct::create([
            'name' => $item['name'],
            'brand' => $item['brand'],
            'category' => $item['category'],
            'origin' => $item['origin'],
            'caliber' => $item['caliber'],
            'quality' => $item['quality'],
            'barcode' => $this->barcode($item['name'], $item['origin'], $item['package']),
            'package_size' => $packageSize,
            'package_unit' => $packageUnit,
            'packaging_method_id' => $packagingId,
            'vat_rate' => self::VAT_RATE,
            'is_bio' => $item['bio'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  \Illuminate\Support\Collection<string, Customer>  $supermarkets
     */
    private function seedSupermarketPrices(array $item, SupermarketProduct $product, $supermarkets, string $observedAt, int $index): int
    {
        $chains = $this->chainsForItem($index);
        $count = 0;

        foreach ($chains as $position => $chainName) {
            $supermarket = $supermarkets[$chainName] ?? null;

            if ($supermarket === null) {
                continue;
            }

            $factor = self::CHAIN_FACTORS[$chainName] ?? 1.0;
            $price = round($item['retail'] * $factor, 2);

            // A predictable promo on roughly a quarter of the listings.
            $isPromo = ($index + $position) % 4 === 0;
            $promoPrice = $isPromo ? round($price * 0.82, 2) : null;

            SupermarketPrice::create([
                'supermarket_id' => $supermarket->getKey(),
                'supermarket_product_id' => $product->getKey(),
                'price' => $price,
                'currency' => self::CURRENCY,
                'is_promo' => $isPromo,
                'promo_price' => $promoPrice,
                'observed_at' => $observedAt,
                'source' => SupermarketPrice::SOURCE_MANUAL,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function seedSupplierProduct(array $item, Supplier $supplier, ?int $packagingId, int $index, float $priceFactor): int
    {
        $unitPrice = round($item['wholesale'] * $priceFactor, 4);
        $unit = $item['unit'];

        $product = SupplierProduct::create([
            'producer_id' => $supplier->getKey(),
            'name' => $item['name'],
            'description' => sprintf('%s %s, origine %s.', $item['category'], $item['variety'] ? '· '.$item['variety'] : '', $item['origin']),
            'variety' => $item['variety'],
            'category' => $item['category'],
            'country_of_origin' => $this->countryCode($item['origin']),
            'caliber' => $item['caliber'],
            'default_packaging' => $item['package'],
            'packaging_method_id' => $packagingId,
            'is_bio' => $item['bio'],
            'min_quantity_value' => 50,
            'min_quantity_unit' => $unit,
            'unit_price' => $unitPrice,
            'currency' => self::CURRENCY,
            'quantity_available' => 300 + $index * 40,
            'valid_until' => Carbon::today()->addDays(14 + ($index % 5) * 7),
            'status' => 'active',
        ]);

        // Quantity break tiers (the first row mirrors the default unit price).
        $tiers = [
            ['min' => 50, 'price' => $unitPrice],
            ['min' => 200, 'price' => round($unitPrice * 0.95, 4)],
            ['min' => 500, 'price' => round($unitPrice * 0.90, 4)],
        ];

        foreach ($tiers as $sort => $tier) {
            $product->prices()->create([
                'min_quantity_value' => $tier['min'],
                'unit_price' => $tier['price'],
                'sort_order' => $sort,
            ]);
        }

        // Per-product sourcing cost overrides.
        $product->costOverride()->create([
            'packaging_cost' => round(0.05 + ($index % 5) * 0.03, 4),
            'transport_cost' => round(0.10 + ($index % 7) * 0.04, 4),
            'commission' => round(0.05 + ($index % 4) * 0.03, 4),
            'profit_margin' => round(0.20 + ($index % 6) * 0.07, 4),
        ]);

        return 1;
    }

    /**
     * Always Carrefour + Bringo, plus two rotating competitors for spread.
     *
     * @return list<string>
     */
    private function chainsForItem(int $index): array
    {
        $rotating = self::ROTATING_CHAINS;
        $count = count($rotating);

        return [
            'Carrefour',
            'Bringo',
            $rotating[$index % $count],
            $rotating[($index + 3) % $count],
        ];
    }

    /**
     * @return array{0: float|null, 1: string}
     */
    private function parsePackage(string $package): array
    {
        if (preg_match('/([\d.,]+)\s*(kg|g|ml|l)\b/u', $package, $matches) === 1) {
            return [(float) str_replace(',', '.', $matches[1]), $matches[2]];
        }

        return [null, $package];
    }

    private function barcode(string $name, string $origin, string $package): string
    {
        return 'RO-'.Str::slug($name.'-'.$origin.'-'.$package);
    }

    private function countryCode(string $country): string
    {
        return match ($country) {
            'România' => 'RO',
            'Spania' => 'ES',
            'Italia' => 'IT',
            'Olanda' => 'NL',
            'Grecia' => 'GR',
            'Turcia' => 'TR',
            'Polonia' => 'PL',
            'Egipt' => 'EG',
            'China' => 'CN',
            'Ecuador' => 'EC',
            'Republica Dominicană' => 'DO',
            'Maroc' => 'MA',
            'Peru' => 'PE',
            default => 'RO',
        };
    }
}
