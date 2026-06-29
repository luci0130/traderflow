<?php

namespace Database\Seeders;

use App\Modules\Customers\Models\Customer;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeds the Romanian retail landscape: every supermarket (in-store + online) as a
 * globally shared customer, plus a representative basket of fruit & vegetable
 * shelf prices observed on a single day. Re-running the seeder is safe — records
 * are matched on natural keys and updated in place.
 *
 * Modelled on the price-tracking spreadsheet: Produs/Tip => product, UM =>
 * package, Calibru => caliber, Origine => origin, and one column of prices per
 * supermarket chain.
 */
class SupermarketPricesRoSeeder extends Seeder
{
    private const OBSERVED_AT = '2026-05-26';

    private const VAT_RATE = 11.0;

    /**
     * Romanian supermarket chains with their real legal entities, fiscal codes
     * (CUI) and registered head offices, sourced from the public trade register.
     * vat_number is null only where no reliable public record was available.
     *
     * @var list<array{name: string, legal_name: string, vat_number: string|null, city: string, address: string, notes: string}>
     */
    private const SUPERMARKETS = [
        ['name' => 'Carrefour', 'legal_name' => 'CARREFOUR ROMANIA SA', 'vat_number' => 'RO11588780', 'city' => 'București', 'address' => 'Str. Gara Herăstrău 4C, Green Court, Sector 2', 'notes' => 'Website: carrefour.ro'],
        ['name' => 'Auchan', 'legal_name' => 'AUCHAN ROMÂNIA SA', 'vat_number' => 'RO17233051', 'city' => 'București', 'address' => 'Str. Brașov 25, Sector 6', 'notes' => 'Website: auchan.ro'],
        ['name' => 'Lidl', 'legal_name' => 'LIDL DISCOUNT SRL', 'vat_number' => 'RO22891860', 'city' => 'București', 'address' => 'Str. Cpt. Av. Alexandru Șerbănescu 58A, Sector 1', 'notes' => 'Parte din Schwarz Group · Website: lidl.ro'],
        ['name' => 'Penny', 'legal_name' => 'REWE (ROMANIA) SRL', 'vat_number' => 'RO13348610', 'city' => 'Ștefăneștii de Jos (Ilfov)', 'address' => 'Str. Bușteni 7', 'notes' => 'Parte din REWE Group · Website: penny.ro'],
        ['name' => 'Profi', 'legal_name' => 'PROFI ROM FOOD SRL', 'vat_number' => 'RO11607939', 'city' => 'Timișoara', 'address' => 'Aleea Amiciției 1', 'notes' => 'Parte din Ahold Delhaize · Website: profi.ro'],
        ['name' => 'Mega Image', 'legal_name' => 'MEGA IMAGE SRL', 'vat_number' => 'RO6719278', 'city' => 'București', 'address' => 'Bd. Timișoara 26, Sector 6', 'notes' => 'Parte din Ahold Delhaize · Website: mega-image.ro'],
        ['name' => 'Kaufland', 'legal_name' => 'KAUFLAND ROMANIA SCS', 'vat_number' => 'RO15991149', 'city' => 'București', 'address' => 'Str. Barbu Văcărescu 120-144, Sector 2', 'notes' => 'Parte din Schwarz Group · Website: kaufland.ro'],
        ['name' => 'Selgros', 'legal_name' => 'SELGROS CASH & CARRY SRL', 'vat_number' => 'RO11805367', 'city' => 'Brașov', 'address' => 'Calea București 231', 'notes' => 'Cash & Carry · Website: selgros.ro'],
        ['name' => 'Metro', 'legal_name' => 'METRO CASH & CARRY ROMANIA SRL', 'vat_number' => 'RO8119423', 'city' => 'București', 'address' => 'Bd. Theodor Pallady 51N, Sector 3', 'notes' => 'Cash & Carry · Website: metro.ro'],
        ['name' => 'Home Garden', 'legal_name' => 'Home Garden', 'vat_number' => null, 'city' => 'Cluj-Napoca', 'address' => 'Str. Traian Vuia 182', 'notes' => 'Brand conserve & legume-fructe (din 2004) · Website: homegarden.ro'],
        ['name' => 'Sezamo', 'legal_name' => 'SEZAMO SRL', 'vat_number' => 'RO50031526', 'city' => 'Ștefăneștii de Jos (Ilfov)', 'address' => 'Str. Linia de Centură 5', 'notes' => 'Supermarket online · Rohlik Group · Website: sezamo.ro'],
        ['name' => 'Freshful', 'legal_name' => 'EMAG RETAIL SRL', 'vat_number' => 'RO44231872', 'city' => 'București', 'address' => 'Str. Gara Herăstrău 6, Globalworth Square, Sector 2', 'notes' => 'Hypermarket online by eMAG · Website: freshful.ro'],
        ['name' => 'Bringo', 'legal_name' => 'BRINGO MAGAZIN SRL', 'vat_number' => 'RO36649034', 'city' => 'București', 'address' => 'Str. Mihai Eminescu 108-112, Sector 2', 'notes' => 'Livrare online (Carrefour) · Website: bringo.ro'],
    ];

    /**
     * Transcribed from the price spreadsheet. Each row: the product family, its
     * type/variety, packaging, caliber, origin and the prices observed per chain.
     *
     * @var list<array{produs: string, tip: string, um: string, caliber: string|null, origine: string|null, prices: array<string, float>}>
     */
    private const ROWS = [
        ['produs' => 'Roșii', 'tip' => 'Cherry', 'um' => '250 g', 'caliber' => '10 - 14', 'origine' => 'Turcia', 'prices' => ['Profi' => 6.99]],
        ['produs' => 'Roșii', 'tip' => 'Cherry', 'um' => 'kg', 'caliber' => null, 'origine' => null, 'prices' => ['Home Garden' => 21.80]],
        ['produs' => 'Roșii', 'tip' => 'Cherry Ciliegino Cocktail', 'um' => '500 g', 'caliber' => '25 - 35', 'origine' => 'Italia', 'prices' => ['Profi' => 14.99]],
        ['produs' => 'Roșii', 'tip' => 'Roze', 'um' => 'kg', 'caliber' => null, 'origine' => 'Olanda', 'prices' => ['Profi' => 12.99]],
        ['produs' => 'Roșii', 'tip' => 'Cherry ciorchine', 'um' => '300 g', 'caliber' => '25 - 30', 'origine' => 'România', 'prices' => ['Profi' => 18.99]],
        ['produs' => 'Roșii', 'tip' => 'Rotundă normală', 'um' => 'kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Home Garden' => 16.60]],
        ['produs' => 'Roșii', 'tip' => 'Rotundă normală', 'um' => 'kg', 'caliber' => null, 'origine' => 'Turcia', 'prices' => ['Carrefour' => 11.49]],
        ['produs' => 'Roșii', 'tip' => 'Datterino', 'um' => '250 g', 'caliber' => '25 - 35', 'origine' => 'Italia', 'prices' => ['Profi' => 8.99]],
        ['produs' => 'Ardei', 'tip' => 'California Roșu (gras)', 'um' => 'kg', 'caliber' => null, 'origine' => 'Olanda', 'prices' => ['Carrefour' => 16.19, 'Home Garden' => 18.90]],
        ['produs' => 'Ardei', 'tip' => 'Kapia roșu', 'um' => 'kg', 'caliber' => null, 'origine' => 'Grecia', 'prices' => ['Profi' => 26.99]],
        ['produs' => 'Ardei', 'tip' => 'Kapia roșu', 'um' => 'kg', 'caliber' => null, 'origine' => 'Turcia', 'prices' => ['Carrefour' => 22.99, 'Home Garden' => 27.30]],
        ['produs' => 'Ardei', 'tip' => 'Gras roșu', 'um' => 'kg', 'caliber' => null, 'origine' => 'Turcia', 'prices' => ['Profi' => 19.99]],
        ['produs' => 'Ardei', 'tip' => 'Gras Bianca (galben)', 'um' => 'kg', 'caliber' => null, 'origine' => 'Turcia', 'prices' => ['Carrefour' => 11.79, 'Home Garden' => 13.70]],
        ['produs' => 'Ardei', 'tip' => 'Iute', 'um' => 'kg', 'caliber' => null, 'origine' => 'Turcia', 'prices' => ['Home Garden' => 29.80]],
        ['produs' => 'Ardei', 'tip' => 'Iute', 'um' => '100 g', 'caliber' => null, 'origine' => 'Maroc', 'prices' => ['Profi' => 4.99]],
        ['produs' => 'Castraveți', 'tip' => 'Cornichon', 'um' => 'kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 5.99, 'Carrefour' => 4.99, 'Home Garden' => 8.30]],
        ['produs' => 'Castraveți', 'tip' => 'Fabio', 'um' => 'buc', 'caliber' => null, 'origine' => 'Grecia', 'prices' => ['Profi' => 3.49]],
        ['produs' => 'Castraveți', 'tip' => 'Fabio', 'um' => 'kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Home Garden' => 13.10]],
        ['produs' => 'Dovlecel', 'tip' => '', 'um' => 'kg', 'caliber' => null, 'origine' => 'Turcia', 'prices' => ['Profi' => 7.99]],
        ['produs' => 'Țelină', 'tip' => 'rădăcină', 'um' => 'kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 5.99]],
        ['produs' => 'Țelină', 'tip' => 'rădăcină', 'um' => 'kg', 'caliber' => null, 'origine' => 'Olanda', 'prices' => ['Carrefour' => 4.19, 'Home Garden' => 6.30]],
        ['produs' => 'Pătrunjel', 'tip' => 'rădăcină', 'um' => 'kg', 'caliber' => null, 'origine' => 'Polonia', 'prices' => ['Home Garden' => 10.50]],
        ['produs' => 'Pătrunjel', 'tip' => 'rădăcină', 'um' => '300 g', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 5.99]],
        ['produs' => 'Păstârnac', 'tip' => 'rădăcină', 'um' => '300 g', 'caliber' => '35 - 50', 'origine' => 'România', 'prices' => ['Profi' => 5.99, 'Carrefour' => 6.49]],
        ['produs' => 'Gulii', 'tip' => '', 'um' => 'buc', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 3.99]],
        ['produs' => 'Gulii', 'tip' => '', 'um' => 'buc', 'caliber' => null, 'origine' => 'Italia', 'prices' => ['Home Garden' => 5.00]],
        ['produs' => 'Morcov', 'tip' => 'rădăcină', 'um' => 'kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Carrefour' => 4.89]],
        ['produs' => 'Morcov', 'tip' => 'rădăcină', 'um' => 'kg', 'caliber' => null, 'origine' => 'Grecia', 'prices' => ['Profi' => 4.49]],
        ['produs' => 'Morcov', 'tip' => 'rădăcină', 'um' => 'kg', 'caliber' => null, 'origine' => 'Egipt', 'prices' => ['Home Garden' => 5.30]],
        ['produs' => 'Usturoi', 'tip' => 'roșu', 'um' => 'kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 17.99]],
        ['produs' => 'Usturoi', 'tip' => 'alb/roșu', 'um' => '250 g', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 6.99]],
        ['produs' => 'Usturoi', 'tip' => 'alb', 'um' => 'kg', 'caliber' => null, 'origine' => 'Grecia', 'prices' => ['Home Garden' => 23.80]],
        ['produs' => 'Ceapă', 'tip' => 'verde', 'um' => 'legătură', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 1.99, 'Home Garden' => 2.80]],
        ['produs' => 'Ceapă', 'tip' => 'roșie', 'um' => 'kg', 'caliber' => null, 'origine' => 'Italia', 'prices' => ['Home Garden' => 4.60]],
        ['produs' => 'Ceapă', 'tip' => 'roșie', 'um' => 'kg', 'caliber' => null, 'origine' => 'Olanda', 'prices' => ['Profi' => 3.29]],
        ['produs' => 'Ceapă', 'tip' => 'albă', 'um' => 'kg', 'caliber' => null, 'origine' => 'Olanda', 'prices' => ['Profi' => 1.99, 'Carrefour' => 2.29]],
        ['produs' => 'Ceapă', 'tip' => 'albă', 'um' => 'kg', 'caliber' => null, 'origine' => 'Germania', 'prices' => ['Home Garden' => 2.99]],
        ['produs' => 'Cartofi', 'tip' => 'albi noi', 'um' => 'kg', 'caliber' => null, 'origine' => 'Egipt', 'prices' => ['Profi' => 3.69, 'Home Garden' => 2.39]],
        ['produs' => 'Cartofi', 'tip' => 'albi noi', 'um' => 'kg', 'caliber' => null, 'origine' => 'Grecia', 'prices' => ['Carrefour' => 3.29]],
        ['produs' => 'Cartofi', 'tip' => 'albi', 'um' => '2.5 kg', 'caliber' => null, 'origine' => 'Franța', 'prices' => ['Profi' => 8.99, 'Carrefour' => 12.99]],
        ['produs' => 'Cartofi', 'tip' => 'albi', 'um' => '2 kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 5.79]],
        ['produs' => 'Cartofi', 'tip' => 'dulci', 'um' => 'kg', 'caliber' => null, 'origine' => 'Egipt', 'prices' => ['Profi' => 11.99, 'Carrefour' => 13.99, 'Home Garden' => 14.30]],
        ['produs' => 'Vinete', 'tip' => '', 'um' => 'kg', 'caliber' => null, 'origine' => 'Turcia', 'prices' => ['Profi' => 13.99]],
        ['produs' => 'Vinete', 'tip' => '', 'um' => 'kg', 'caliber' => null, 'origine' => 'Olanda', 'prices' => ['Home Garden' => 19.50]],
        ['produs' => 'Varză', 'tip' => 'albă nouă', 'um' => 'kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 3.99, 'Carrefour' => 3.79, 'Home Garden' => 4.50]],
        ['produs' => 'Varză', 'tip' => 'roșie', 'um' => 'kg', 'caliber' => null, 'origine' => 'Turcia', 'prices' => ['Home Garden' => 5.90]],
        ['produs' => 'Salată', 'tip' => 'verde', 'um' => 'buc', 'caliber' => null, 'origine' => 'Italia', 'prices' => ['Home Garden' => 9.10]],
        ['produs' => 'Spanac', 'tip' => 'caserolă', 'um' => '100 g', 'caliber' => null, 'origine' => 'Italia', 'prices' => ['Carrefour' => 6.29]],
        ['produs' => 'Conopidă', 'tip' => '', 'um' => 'kg', 'caliber' => null, 'origine' => 'România', 'prices' => ['Profi' => 16.99]],
        ['produs' => 'Conopidă', 'tip' => '', 'um' => 'kg', 'caliber' => null, 'origine' => 'Italia', 'prices' => ['Home Garden' => 20.80]],
    ];

    public function run(): void
    {
        $supermarkets = $this->seedSupermarkets();
        $this->seedProductsAndPrices($supermarkets);
    }

    /**
     * @return array<string, Customer>
     */
    private function seedSupermarkets(): array
    {
        $supermarkets = [];

        foreach (self::SUPERMARKETS as $entry) {
            $name = $entry['name'];

            $supermarket = Customer::withoutGlobalScopes()
                ->where('slug', Str::slug($name))
                ->whereNull('tenant_id')
                ->first() ?? new Customer;

            // forceFill keeps the global (tenant_id = null) scope and refreshes the
            // real company data on every run.
            $supermarket->forceFill([
                'tenant_id' => null,
                'name' => $name,
                'slug' => Str::slug($name),
                'legal_name' => $entry['legal_name'],
                'vat_number' => $entry['vat_number'],
                'country' => 'RO',
                'city' => $entry['city'],
                'address' => $entry['address'],
                'notes' => $entry['notes'],
                'status' => 'active',
                'is_active' => true,
            ])->saveQuietly();

            $supermarkets[$name] = $supermarket;
        }

        return $supermarkets;
    }

    /**
     * @param  array<string, Customer>  $supermarkets
     */
    private function seedProductsAndPrices(array $supermarkets): void
    {
        $observedAt = Carbon::parse(self::OBSERVED_AT);

        foreach (self::ROWS as $row) {
            $name = trim($row['produs'].' '.$row['tip']);
            [$packageSize, $packageUnit] = $this->parsePackage($row['um']);

            // A deterministic text key keeps the seeder idempotent across re-runs
            // and database engines, avoiding fragile decimal/NULL equality on
            // package_size when matching an existing product.
            $referenceKey = 'RO-'.Str::slug($name.'-'.($row['origine'] ?? 'na').'-'.$row['um']);

            $product = SupermarketProduct::updateOrCreate(
                ['barcode' => $referenceKey],
                [
                    'name' => $name,
                    'category' => $row['produs'],
                    'origin' => $row['origine'],
                    'caliber' => $row['caliber'],
                    'package_size' => $packageSize,
                    'package_unit' => $packageUnit,
                    'vat_rate' => self::VAT_RATE,
                ],
            );

            foreach ($row['prices'] as $supermarketName => $price) {
                $supermarket = $supermarkets[$supermarketName] ?? null;

                if ($supermarket === null) {
                    continue;
                }

                // This seeder represents a single price snapshot, so one row per
                // (supermarket, product) keeps re-runs idempotent. observed_at lives
                // in the values to avoid fragile date-column equality on SQLite.
                SupermarketPrice::updateOrCreate(
                    [
                        'supermarket_id' => $supermarket->getKey(),
                        'supermarket_product_id' => $product->getKey(),
                    ],
                    [
                        'observed_at' => $observedAt->toDateString(),
                        'price' => $price,
                        'currency' => 'RON',
                        'source' => SupermarketPrice::SOURCE_MANUAL,
                    ],
                );
            }
        }
    }

    /**
     * Splits a unit-of-measure label into a numeric package size and its unit.
     * Bulk units (kg, buc, legătură) have no fixed size.
     *
     * @return array{0: float|null, 1: string}
     */
    private function parsePackage(string $um): array
    {
        $um = trim($um);

        if (preg_match('/^([\d.,]+)\s*(\w+)$/u', $um, $matches) === 1) {
            return [(float) str_replace(',', '.', $matches[1]), $matches[2]];
        }

        return [null, $um];
    }
}
