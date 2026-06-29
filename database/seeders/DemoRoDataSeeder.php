<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\SupplierOrders\Models\SupplierOrder;
use App\Modules\SupplierOrders\Models\SupplierOrderItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DemoRoDataSeeder extends Seeder
{
    use \Database\Seeders\Concerns\SeedsTenantRoles;

    /**
     * How far back offers and orders are spread (days), for trend reports.
     */
    private const HISTORY_DAYS = 90;

    private const TENANTS = [
        'A' => [
            'name' => 'Freshmarket București',
            'legal_name' => 'Freshmarket Distribuție SRL',
            'currency' => 'RON',
            'country' => 'România',
            'city' => 'București',
            'email' => 'contact@freshmarket.test',
        ],
        'B' => [
            'name' => 'Verde Plus Cluj',
            'legal_name' => 'Verde Plus Trading SRL',
            'currency' => 'RON',
            'country' => 'România',
            'city' => 'Cluj-Napoca',
            'email' => 'office@verdeplus.test',
        ],
    ];

    /**
     * @var list<array{name: string, email: string, tenant: string, role: string}>
     */
    private const USERS = [
        ['name' => 'Mihai Popescu', 'email' => 'mihai@traderflow.test', 'tenant' => 'A', 'role' => 'super_admin'],
        ['name' => 'Cristina Vasilescu', 'email' => 'cristina@traderflow.test', 'tenant' => 'A', 'role' => 'sales_agent'],
        ['name' => 'Andrei Munteanu', 'email' => 'andrei@traderflow.test', 'tenant' => 'A', 'role' => 'purchasing_agent'],
    ];

    /**
     * Hierarchical product taxonomy seeded for every tenant. Nesting follows the
     * naming prefixes (e.g. "Roșii cherry roșii" sits under "Roșii cherry").
     * An empty array marks a leaf category. Two roots: Legume and Fructe.
     *
     * @var array<string, mixed>
     */
    private const CATEGORY_TREE = [
        'Legume' => [
            'Roșii' => [
                'Roșii normale' => [],
                'Roșii cherry' => [
                    'Roșii cherry roșii' => [],
                    'Roșii cherry galbene' => [],
                    'Roșii cherry mix' => [],
                ],
                'Roșii prunișoare' => [],
                'Roșii inimă de bou' => [],
                'Roșii kumato' => [],
                'Roșii cocktail' => [],
                'Roșii bio' => [],
                'Roșii pentru sos / procesare' => [],
            ],
            'Castraveți' => [
                'Castraveți lungi' => [],
                'Castraveți cornichon' => [],
                'Castraveți murați' => [],
                'Castraveți bio' => [],
                'Castraveți de solar' => [],
                'Castraveți de câmp' => [],
            ],
            'Ardei' => [
                'Ardei gras' => [
                    'Ardei gras roșu' => [],
                    'Ardei gras galben' => [],
                    'Ardei gras verde' => [],
                    'Ardei gras portocaliu' => [],
                ],
                'Ardei kapia' => [],
                'Ardei gogoșar' => [],
                'Ardei iute' => [
                    'Ardei iute verde' => [],
                    'Ardei iute roșu' => [],
                ],
                'Jalapeño' => [],
                'Chili' => [],
                'Ardei pentru copt' => [],
            ],
            'Cartofi' => [
                'Cartofi albi' => [],
                'Cartofi roșii' => [],
                'Cartofi galbeni' => [],
                'Cartofi noi' => [],
                'Cartofi dulci' => [],
                'Cartofi pentru prăjit' => [],
                'Cartofi pentru copt' => [],
                'Cartofi pentru piure' => [],
            ],
            'Ceapă' => [
                'Ceapă galbenă' => [],
                'Ceapă roșie' => [],
                'Ceapă albă' => [],
                'Ceapă verde' => [],
                'Șalotă' => [],
                'Ceapă pentru gătit' => [],
                'Ceapă pentru salată' => [],
            ],
            'Usturoi' => [
                'Usturoi alb' => [],
                'Usturoi roșu' => [],
                'Usturoi verde' => [],
                'Usturoi românesc' => [],
                'Usturoi import' => [],
            ],
            'Morcovi' => [
                'Morcovi normali' => [],
                'Morcovi baby' => [],
                'Morcovi mov' => [],
                'Morcovi galbeni' => [],
                'Morcovi bio' => [],
                'Morcovi pentru supă' => [],
            ],
            'Rădăcinoase' => [
                'Păstârnac' => [],
                'Pătrunjel rădăcină' => [],
                'Țelină rădăcină' => [],
                'Sfeclă roșie' => [],
                'Ridichi' => [
                    'Ridichi roșii' => [],
                    'Ridichi negre' => [],
                    'Daikon' => [],
                ],
            ],
            'Varză' => [
                'Varză albă' => [],
                'Varză roșie' => [],
                'Varză murată' => [],
                'Varză chinezească' => [],
                'Varză kale' => [],
                'Varză de Bruxelles' => [],
            ],
            'Salate și frunze verzi' => [
                'Salată verde' => [],
                'Salată iceberg' => [],
                'Salată romană' => [],
                'Rucola' => [],
                'Baby spanac' => [],
                'Spanac' => [],
                'Mangold' => [],
                'Valeriană' => [],
                'Mix salată' => [],
            ],
            'Dovlecei și dovleac' => [
                'Dovlecei verzi' => [],
                'Dovlecei galbeni' => [],
                'Zucchini' => [],
                'Dovleac plăcintar' => [],
                'Dovleac pentru copt' => [],
                'Dovleac Hokkaido' => [],
            ],
            'Vinete' => [
                'Vinete normale' => [],
                'Vinete albe' => [],
                'Vinete baby' => [],
                'Vinete pentru copt' => [],
            ],
            'Fasole și mazăre' => [
                'Fasole verde' => [],
                'Fasole galbenă' => [],
                'Fasole boabe' => [],
                'Fasole albă' => [],
                'Fasole roșie' => [],
                'Fasole pestriță' => [],
                'Mazăre verde' => [],
                'Mazăre păstăi' => [],
            ],
            'Ciuperci' => [
                'Champignon albe' => [],
                'Champignon brune' => [],
                'Pleurotus' => [],
                'Shiitake' => [],
                'Hribi' => [],
                'Gălbiori' => [],
                'Mix ciuperci' => [],
            ],
            'Porumb' => [
                'Porumb dulce' => [],
                'Porumb pentru fiert' => [],
                'Porumb pentru copt' => [],
                'Porumb boabe' => [],
            ],
            'Verdețuri și plante aromatice' => [
                'Pătrunjel' => [],
                'Mărar' => [],
                'Leuștean' => [],
                'Coriandru' => [],
                'Busuioc' => [],
                'Mentă' => [],
                'Cimbru' => [],
                'Rozmarin' => [],
                'Oregano' => [],
                'Tarhon' => [],
                'Salvie' => [],
            ],
            'Legume exotice / speciale' => [
                'Avocado' => [],
                'Ghimbir' => [],
                'Turmeric' => [],
                'Fenicul' => [],
                'Sparanghel' => [],
                'Anghinare' => [],
                'Pak choi' => [],
                'Okra' => [],
            ],
        ],
        'Fructe' => [
            'Citrice' => [
                'Portocale' => [
                    'Portocale normale' => [],
                    'Portocale roșii' => [],
                    'Portocale pentru suc' => [],
                    'Portocale bio' => [],
                ],
                'Mandarine' => [],
                'Clementine' => [],
                'Lămâi' => [
                    'Lămâi normale' => [],
                    'Lămâi bio' => [],
                    'Lămâi netratate' => [],
                ],
                'Lime' => [],
                'Grapefruit' => [
                    'Grapefruit alb' => [],
                    'Grapefruit roșu' => [],
                ],
                'Pomelo' => [],
            ],
            'Mere' => [
                'Mere roșii' => [],
                'Mere verzi' => [],
                'Mere galbene' => [],
                'Mere golden' => [],
                'Mere ionatan' => [],
                'Mere granny smith' => [],
                'Mere fuji' => [],
                'Mere gala' => [],
                'Mere bio' => [],
                'Mere pentru plăcintă' => [],
            ],
            'Pere' => [
                'Pere normale' => [],
                'Pere conference' => [],
                'Pere abate' => [],
                'Pere williams' => [],
                'Pere roșii' => [],
                'Pere bio' => [],
            ],
            'Banane' => [
                'Banane normale' => [],
                'Banane bio' => [],
                'Banane baby' => [],
                'Banane plantain' => [],
            ],
            'Struguri' => [
                'Struguri albi' => [],
                'Struguri negri' => [],
                'Struguri roșii' => [],
                'Struguri fără sâmburi' => [],
                'Struguri pentru masă' => [],
                'Struguri pentru vin' => [],
            ],
            'Fructe de pădure' => [
                'Căpșuni' => [],
                'Zmeură' => [],
                'Afine' => [],
                'Mure' => [],
                'Coacăze' => [
                    'Coacăze roșii' => [],
                    'Coacăze negre' => [],
                ],
                'Merișoare' => [],
                'Mix fructe de pădure' => [],
            ],
            'Fructe sâmburoase' => [
                'Piersici' => [],
                'Nectarine' => [],
                'Caise' => [],
                'Prune' => [],
                'Cireșe' => [],
                'Vișine' => [],
                'Corcodușe' => [],
            ],
            'Pepeni' => [
                'Pepene verde' => [
                    'Pepene verde cu sâmburi' => [],
                    'Pepene verde fără sâmburi' => [],
                ],
                'Pepene galben' => [
                    'Pepene galben cantalup' => [],
                    'Pepene galben honeydew' => [],
                ],
            ],
            'Fructe tropicale' => [
                'Ananas' => [],
                'Mango' => [],
                'Papaya' => [],
                'Fructul pasiunii' => [],
                'Dragon fruit' => [],
                'Guava' => [],
                'Lychee' => [],
                'Rambutan' => [],
                'Kaki' => [],
                'Carambola' => [],
            ],
            'Fructe exotice' => [
                'Rodie' => [],
                'Smochine' => [
                    'Smochine proaspete' => [],
                    'Smochine uscate' => [],
                ],
                'Curmale' => [
                    'Curmale proaspete' => [],
                    'Curmale uscate' => [],
                    'Curmale medjool' => [],
                ],
                'Kiwi' => [
                    'Kiwi verde' => [],
                    'Kiwi galben' => [],
                ],
                'Cocos' => [],
            ],
            'Fructe uscate' => [
                'Stafide' => [],
                'Caise uscate' => [],
                'Prune uscate' => [],
                'Merișoare uscate' => [],
                'Goji' => [],
                'Chipsuri de banane' => [],
                'Mix fructe uscate' => [],
            ],
            'Nuci și semințe' => [
                'Nuci' => [],
                'Alune' => [],
                'Migdale' => [],
                'Caju' => [],
                'Fistic' => [],
                'Semințe floarea-soarelui' => [],
                'Semințe dovleac' => [],
                'Semințe chia' => [],
                'Semințe in' => [],
            ],
        ],
    ];

    private const UNITS = [
        'A' => [
            ['name' => 'Kilogram', 'symbol' => 'kg'],
            ['name' => 'Tonă', 'symbol' => 't'],
            ['name' => 'Bucată', 'symbol' => 'buc'],
            ['name' => 'Legătură', 'symbol' => 'leg'],
            ['name' => 'Ladă', 'symbol' => 'ldă'],
        ],
        'B' => [
            ['name' => 'Kilogram', 'symbol' => 'kg'],
            ['name' => 'Palet', 'symbol' => 'plt'],
            ['name' => 'Cutie', 'symbol' => 'cut'],
            ['name' => 'Sac', 'symbol' => 'sac'],
            ['name' => 'Bucată', 'symbol' => 'buc'],
        ],
    ];

    /**
     * Per tenant: list of [productName, basePriceRon, leafCategory, imageUrl].
     * Each leafCategory must exist in CATEGORY_TREE. Image URLs from freshful.ro.
     *
     * @var array<string, list<array{0: string, 1: float, 2: string, 3: string}>>
     */
    private const PRODUCTS = [
        'A' => [
            ['Morcovi România 1kg', 2.50, 'Morcovi normali', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/2d/87/f39e5c40f71cce36c26d6922e646.jpg'],
            ['Pătrunjel rădăcină România 500g', 6.00, 'Pătrunjel rădăcină', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/ab/79/de85fe8d665c885629e2ccaced34.jpg'],
            ['Țelină rădăcină 1 buc', 6.50, 'Țelină rădăcină', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/1a/7b/351d8bd3f450bc72ae4cb39d6878.jpg'],
            ['Cartofi pentru fiert România 2.5kg', 2.30, 'Cartofi pentru copt', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/0c/cc/35cb57b5bf24b131d8240ea3049b.jpg'],
            ['Roșii cherry 500g', 14.00, 'Roșii cherry', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/43/3a/69bddbe45cbfbf69f242070a29da.jpg'],
            ['Ardei Kapia roșu 500g', 7.50, 'Ardei kapia', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/bf/57/d36e354b97829d128740b6c1153d.jpg'],
            ['Ardei California roșu 1 buc', 9.00, 'Ardei gras roșu', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/e4/00/8f62b5db871af4ef973056d20447.jpg'],
            ['Andive albe 500g', 11.00, 'Salate și frunze verzi', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/79/a6/1cbbdb521cf7ccf906597ba054b9.jpg'],
            ['Praz eco 400g', 8.00, 'Ceapă verde', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/bf/9a/96af886f9655da1a1b4715621a6c.jpg'],
            ['Varză albă nouă România 1 buc', 3.50, 'Varză albă', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/c1/ee/1a568a585682ae17a4d8be16031f.jpg'],
            ['Portocale netratate 1kg', 5.50, 'Portocale normale', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/61/5c/edae4d9e66f05204cb2964010940.jpg'],
            ['Lămâi netratate 500g', 9.00, 'Lămâi netratate', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/7e/4b/f3c9e2d2244b0c5c1d281f544bae.jpg'],
            ['Mandarine 1kg', 7.50, 'Mandarine', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/53/4e/a2813f669bb3b41c46658fc917ed.jpg'],
            ['Afine 125g', 35.00, 'Afine', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/25/00/507648a6dc7ca5d36b834653f829.jpg'],
            ['Cireșe 250g', 22.00, 'Cireșe', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/de/c9/2ebe26e730f3e71747578cb9dd1f.jpg'],
        ],
        'B' => [
            ['Avocado Hass 1 buc, min. 125g', 25.00, 'Avocado', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/17/13/2d95a7e5248fed646f7bcd28740c.jpg'],
            ['Mango 1 buc', 15.00, 'Mango', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/3b/81/32fa9a47bda68386307082fc0270.jpg'],
            ['Ananas 1 buc', 11.00, 'Ananas', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/e2/aa/70e405d1ffcbcf9f11fd782d9664.jpg'],
            ['Banane 1kg', 5.50, 'Banane normale', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/03/6d/6e8daecf3a54f168beeac724cfb2.jpg'],
            ['Ceapă galbenă 1kg', 2.50, 'Ceapă galbenă', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/2b/fb/4cdc2d2a09b2167a89f1fdb0c08d.jpg'],
            ['Ceapă roșie 1kg', 3.50, 'Ceapă roșie', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/40/70/5a36195924c3ef266da597f8cccd.jpg'],
            ['Usturoi 250g', 20.00, 'Usturoi alb', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/25/50/a0d8aeb3aa831fbbce4462ece7e3.jpg'],
            ['Pepene verde 1 buc, minim 5kg', 2.80, 'Pepene verde', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/ea/0e/4371518a11c343f681a9cf4a0499.jpg'],
            ['Pepene galben Cantaloupe eco minim 600g', 6.50, 'Pepene galben cantalup', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/3a/59/cb8fcdb0a96b4c0c5e4761406107.jpg'],
            ['Caise 500g', 12.00, 'Caise', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/66/c5/4c2745d3a794ea2b1a496255eba7.jpg'],
            ['Piersici 500g', 10.50, 'Piersici', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/d4/0e/2867bb8d109ccb9d1c3fe188281a.jpg'],
            ['Nectarine eco 300g', 13.00, 'Nectarine', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/f6/d3/71040a88587a5f39e60007492bbc.jpg'],
            ['Dovlecel 1 buc', 6.00, 'Dovlecei verzi', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/51/72/f65527b46007b4423e583f055c80.jpg'],
            ['Dovlecei România 700g', 5.50, 'Zucchini', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/78/14/0e3f68015d9112f889da48d71e08.jpg'],
            ['Castravete Fabio 1 buc', 7.00, 'Castraveți lungi', 'https://cdn.freshful.ro/media/cache/sylius_shop_product_thumbnail/05/07/f9fe6175d9590e7bdf579f3bb1b7.jpg'],
        ],
    ];

    private const SUPPLIERS = [
        'A' => [
            'Ferma Verde SRL',
            'Agricola Dâmbovița SA',
            'Livada Vrancea SRL',
            'Sere Olt Producție',
            'Grădina Argeș SRL',
        ],
        'B' => [
            'Legume Bihor SA',
            'Fructe Cluj SRL',
            'Hortinatur Mureș',
            'Recolta Iași SRL',
            'Agrocomplex Banat SA',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            // Customers in this system are supermarkets (global, tenant_id = null),
            // seeded with real company data by SupermarketPricesRoSeeder.
            $this->call(SupermarketPricesRoSeeder::class);
            $supermarkets = Customer::query()->global()->orderBy('name')->get();

            $tenants = $this->seedTenants();
            $this->seedRoles();
            $this->seedUsers();

            // Product categories are a single global taxonomy shared by all tenants.
            $categories = $this->seedGlobalCategories();

            // One shared global catalog (units/products/suppliers with tenant_id = null).
            $catalog = $this->seedGlobalCatalog($categories);

            // Documents (offers/orders) still belong to a tenant and reference the
            // shared catalog. Each tenant gets weekly inbound supplier offers, a
            // comprehensive set of customer offers (with linked supplier offers and,
            // for accepted ones, sales orders + supplier orders), plus standalone
            // supplier orders from the weekly offers. Numbers are auto-assigned by
            // each tenant's sequence.
            foreach ($tenants as $key => $tenant) {
                $this->seedSupplierOffers($key, $tenant, $catalog);
                $this->seedTenantDocuments($tenant, $catalog, $supermarkets);
                $this->seedStandaloneSupplierOrders($tenant, $catalog['units']['kg']->id);
            }

            // Replace the placeholder produce with a realistic Legume & Fructe
            // catalogue (supermarket shelf prices + supplier wholesale offers).
            $this->call(LegumeFructeCatalogSeeder::class);
        });
    }

    /**
     * @return array{A: Tenant, B: Tenant}
     */
    private function seedTenants(): array
    {
        $created = [];

        foreach (self::TENANTS as $key => $data) {
            $created[$key] = Tenant::firstOrCreate(
                ['name' => $data['name']],
                [...$data, 'is_active' => true],
            );
        }

        return $created;
    }

    private function seedRoles(): void
    {
        $this->bootstrapGlobalRoles();
    }

    /**
     * Users get a global role and are NOT attached to any tenant — they have
     * global access across the whole business.
     */
    private function seedUsers(): void
    {
        foreach (self::USERS as $entry) {
            $user = User::firstOrCreate(
                ['email' => $entry['email']],
                [
                    'name' => $entry['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $this->assignGlobalRole($user, $entry['role']);
        }
    }

    /**
     * Seed ONE shared global catalog (tenant_id = null): units, products and
     * suppliers used by every tenant's documents.
     *
     * @param  array<string, ProductCategory>  $categories  Shared global category map (name => category).
     * @return array{
     *     units: array<string, Unit>,
     *     products: list<Product>,
     *     base_prices: array<int, float>,
     *     suppliers: list<Supplier>
     * }
     */
    private function seedGlobalCatalog(array $categories): array
    {
        $units = [];
        foreach (self::UNITS['A'] as $unit) {
            $units[$unit['symbol']] = Unit::create([
                'tenant_id' => null,
                'name' => $unit['name'],
                'symbol' => $unit['symbol'],
            ]);
        }

        $defaultUnit = $units['kg'];

        $products = [];
        $basePrices = [];
        $index = 1;
        foreach (self::PRODUCTS['A'] as [$productName, $basePrice, $categoryName, $imageUrl]) {
            $slug = Str::slug($productName);
            $imagePath = $this->downloadImage($imageUrl, null, $slug);

            $product = Product::create([
                'tenant_id' => null,
                'product_category_id' => $categories[$categoryName]->id,
                'unit_id' => $defaultUnit->id,
                'sku' => sprintf('P-%03d', $index++),
                'name' => $productName,
                'status' => 'active',
                'description' => $productName.' — '.mb_strtolower($categoryName).'.',
                'image_path' => $imagePath,
            ]);
            $products[] = $product;
            $basePrices[$product->id] = (float) $basePrice;
        }

        $suppliers = [];
        foreach (self::SUPPLIERS['A'] as $i => $name) {
            $suppliers[] = Supplier::create([
                'tenant_id' => null,
                'name' => $name,
                'legal_name' => $name,
                'email' => $this->slug($name).'@suppliers.test',
                'country' => 'România',
                'city' => 'București',
                'status' => 'active',
                'contact_person' => 'Departament vânzări '.($i + 1),
                'payment_terms' => 'Net '.[14, 21, 30, 45][$i % 4].' zile',
            ]);
        }

        return [
            'units' => $units,
            'products' => $products,
            'base_prices' => $basePrices,
            'suppliers' => $suppliers,
        ];
    }

    /**
     * Seed the global product-category taxonomy once (tenant_id = null) and
     * return a flat [name => ProductCategory] map for product lookups.
     *
     * @return array<string, ProductCategory>
     */
    private function seedGlobalCategories(): array
    {
        $map = [];
        $this->seedCategoryTree(self::CATEGORY_TREE, null, $map);

        return $map;
    }

    /**
     * Recursively create the global category tree, wiring up parent_id, and
     * collect every node into a flat [name => ProductCategory] map.
     *
     * @param  array<string, mixed>  $tree
     * @param  array<string, ProductCategory>  $map
     */
    private function seedCategoryTree(array $tree, ?int $parentId, array &$map): void
    {
        foreach ($tree as $name => $children) {
            $category = ProductCategory::create([
                'tenant_id' => null,
                'name' => $name,
                'status' => 'active',
                'parent_id' => $parentId,
            ]);

            $map[$name] = $category;

            if ($children !== []) {
                $this->seedCategoryTree($children, $category->id, $map);
            }
        }
    }

    /**
     * @param  array{units: array<string, Unit>, products: list<Product>, base_prices: array<int, float>, suppliers: list<Supplier>}  $catalog
     * @return list<SupplierOfferItem>
     */
    private function seedSupplierOffers(string $key, Tenant $tenant, array $catalog): array
    {
        $items = [];
        $statuses = ['received', 'received', 'received', 'processed', 'approved', 'received', 'approved', 'received', 'processed', 'received'];
        $today = Carbon::today();
        $offerSeq = 0;

        foreach ($catalog['suppliers'] as $supplierIndex => $supplier) {
            for ($n = 0; $n < 3; $n++) {
                $receivedAt = $today->copy()->subDays(mt_rand(0, self::HISTORY_DAYS));

                $supplierOffer = SupplierOffer::create([
                    'tenant_id' => $tenant->id,
                    'supplier_id' => $supplier->id,
                    'received_at' => $receivedAt,
                    'valid_until' => $receivedAt->copy()->addDays(mt_rand(14, 40)),
                    'currency' => 'RON',
                    'status' => $statuses[$offerSeq % count($statuses)],
                    'source_type' => 'manual',
                    'notes' => 'Ofertă săptămânală produse proaspete.',
                ]);

                $productSample = collect($catalog['products'])->shuffle()->take(mt_rand(4, 7));
                $supplierBias = 0.92 + ($supplierIndex * 0.03);

                foreach ($productSample as $product) {
                    $basePrice = $catalog['base_prices'][$product->id] ?? 1.00;
                    $price = round($basePrice * $supplierBias * (0.95 + (mt_rand(0, 100) / 1000)), 4);

                    $items[] = SupplierOfferItem::create([
                        'tenant_id' => $tenant->id,
                        'supplier_offer_id' => $supplierOffer->id,
                        'product_id' => $product->id,
                        'unit_id' => $catalog['units']['kg']->id,
                        'quantity' => mt_rand(100, 800),
                        'purchase_price' => $price,
                        'currency' => 'RON',
                        'availability_date' => $receivedAt->copy()->addDays(mt_rand(1, 14)),
                    ]);
                }

                $offerSeq++;
            }
        }

        return $items;
    }

    /**
     * Seed a tenant's customer offers spread over the recent history, each sourced
     * from per-supplier linked supplier offers. Accepted offers additionally
     * produce a sales order and one supplier order per supplier (the accept flow).
     *
     * @param  array{products: list<Product>, units: array<string, Unit>, base_prices: array<int, float>, suppliers: list<Supplier>}  $catalog
     * @param  EloquentCollection<int, Customer>  $supermarkets
     */
    private function seedTenantDocuments(Tenant $tenant, array $catalog, EloquentCollection $supermarkets): void
    {
        $today = Carbon::today();
        $products = collect($catalog['products']);
        $suppliers = collect($catalog['suppliers'])->values();
        $unitId = $catalog['units']['kg']->id;

        // Build all offer specs first, then create them in date order so the
        // auto-assigned numbers ascend with time.
        $specs = [];
        for ($i = 0; $i < $this->offersPerTenant(); $i++) {
            $specs[] = [
                'date' => $today->copy()->subDays(mt_rand(0, self::HISTORY_DAYS)),
                'status' => $this->weighted(['draft' => 15, 'sent' => 25, 'accepted' => 45, 'rejected' => 10, 'expired' => 5]),
                'customer' => $supermarkets->random(),
                'margin' => 1.15 + (mt_rand(0, 20) / 100),
                'products' => $products->shuffle()->take(mt_rand(3, 6))->values(),
            ];
        }

        usort($specs, fn (array $a, array $b): int => $a['date']->timestamp <=> $b['date']->timestamp);

        foreach ($specs as $spec) {
            $this->seedCustomerOfferChain($tenant, $catalog, $spec, $suppliers, $unitId, $today);
        }
    }

    /**
     * Create a single customer offer with its linked supplier offers and, when
     * accepted, the resulting sales order and supplier orders.
     *
     * @param  array<string, mixed>  $catalog
     * @param  array{date: Carbon, status: string, customer: Customer, margin: float, products: \Illuminate\Support\Collection<int, Product>}  $spec
     * @param  \Illuminate\Support\Collection<int, Supplier>  $suppliers
     */
    private function seedCustomerOfferChain(Tenant $tenant, array $catalog, array $spec, $suppliers, int $unitId, Carbon $today): void
    {
        $offerDate = $spec['date'];
        $status = $spec['status'];
        $accepted = $status === 'accepted';
        $margin = $spec['margin'];

        // Assign a supplier + purchase price + quantity to each product.
        $assignments = [];
        foreach ($spec['products'] as $product) {
            $supplierIndex = mt_rand(0, $suppliers->count() - 1);
            $supplier = $suppliers[$supplierIndex];
            $basePrice = $catalog['base_prices'][$product->id] ?? 1.00;
            $bias = 0.92 + ($supplierIndex * 0.03);

            $assignments[] = [
                'product' => $product,
                'supplier' => $supplier,
                'purchase' => round($basePrice * $bias * (0.95 + (mt_rand(0, 100) / 1000)), 4),
                'qty' => mt_rand(20, 200),
            ];
        }

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $spec['customer']->id,
            'offer_date' => $offerDate,
            'valid_until' => $offerDate->copy()->addDays(mt_rand(10, 30)),
            'currency' => 'RON',
            'status' => $status,
            'sent_at' => in_array($status, ['sent', 'accepted'], true) ? $offerDate->copy()->addDays(mt_rand(0, 3)) : null,
            'notes' => 'Ofertă demo pentru '.$spec['customer']->name,
        ]);

        // One linked supplier offer per supplier, keeping a product => item map so
        // the customer offer items can reference their source.
        $linkedOffers = [];
        $sourceItems = [];

        foreach (collect($assignments)->groupBy(fn (array $a): int => $a['supplier']->id) as $supplierId => $group) {
            $supplierOffer = SupplierOffer::create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $supplierId,
                'customer_offer_id' => $offer->id,
                'received_at' => $offerDate->copy()->subDays(mt_rand(0, 5)),
                'valid_until' => $offerDate->copy()->addDays(mt_rand(14, 40)),
                'currency' => 'RON',
                'status' => $accepted ? 'approved' : 'received',
                'source_type' => 'manual',
                'notes' => 'Sursă pentru oferta clientului.',
            ]);

            $linkedOffers[$supplierId] = $supplierOffer;

            foreach ($group as $assignment) {
                $sourceItems[$assignment['product']->id] = SupplierOfferItem::create([
                    'tenant_id' => $tenant->id,
                    'supplier_offer_id' => $supplierOffer->id,
                    'product_id' => $assignment['product']->id,
                    'unit_id' => $unitId,
                    'quantity' => $assignment['qty'],
                    'purchase_price' => $assignment['purchase'],
                    'currency' => 'RON',
                    'availability_date' => $offerDate->copy()->addDays(mt_rand(1, 10)),
                ]);
            }
        }

        foreach ($assignments as $assignment) {
            CustomerOfferItem::create([
                'tenant_id' => $tenant->id,
                'customer_offer_id' => $offer->id,
                'product_id' => $assignment['product']->id,
                'supplier_id' => $assignment['supplier']->id,
                'supplier_offer_item_id' => $sourceItems[$assignment['product']->id]->id,
                'unit_id' => $unitId,
                'quantity' => $assignment['qty'],
                'purchase_price' => $assignment['purchase'],
                'sale_price' => round($assignment['purchase'] * $margin, 4),
                'tax_rate' => 9,
            ]);
        }

        // The customer-offer/item observers re-save the offer, so created_at and
        // updated_at would all collapse to seed time. Stamp a realistic timeline
        // instead: created on the offer date, last touched on the decision date a
        // few days after it was sent (this updated_at is what the supermarket
        // margin chart plots as the accept/reject time).
        $this->stampOfferTimeline($offer, $offerDate, $status, $today);

        if (! $accepted) {
            return;
        }

        $offer->refresh(); // totals are computed by the customer-offer observer

        $orderDate = $offerDate->copy()->addDays(mt_rand(1, 5));

        $salesOrder = SalesOrder::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'customer_id' => $offer->customer_id,
            'order_date' => $orderDate,
            'delivery_date' => $orderDate->copy()->addDays(mt_rand(3, 12)),
            'currency' => 'RON',
            'status' => $this->ageStatus($today, $orderDate, 'sales'),
            'subtotal' => $offer->subtotal,
            'tax_total' => $offer->tax_total,
            'total' => $offer->total,
            'notes' => 'Comandă din oferta acceptată.',
        ]);

        foreach ($offer->items as $item) {
            SalesOrderItem::create([
                'tenant_id' => $tenant->id,
                'sales_order_id' => $salesOrder->id,
                'product_id' => $item->product_id,
                'supplier_id' => $item->supplier_id,
                'unit_id' => $item->unit_id,
                'quantity' => $item->quantity,
                'purchase_price' => $item->purchase_price,
                'sale_price' => $item->sale_price,
                'margin_value' => $item->margin_value,
                'margin_percent' => $item->margin_percent,
                'line_total' => $item->line_total,
                'notes' => $item->notes,
            ]);
        }

        foreach ($linkedOffers as $supplierId => $supplierOffer) {
            $this->seedSupplierOrder($tenant, $supplierOffer->loadMissing('items'), $offer->id, $unitId, $today);
        }
    }

    /**
     * Backdate an offer's timestamps to a believable timeline. The observers re-save
     * the offer during seeding, collapsing created_at/updated_at to seed time; this
     * restores created_at to the offer date and sets updated_at to the decision date
     * (a few days after it was sent for accepted/rejected offers), which is what the
     * supermarket margin chart plots as the accept/reject moment.
     */
    private function stampOfferTimeline(CustomerOffer $offer, Carbon $offerDate, string $status, Carbon $today): void
    {
        $sentAt = $offer->sent_at instanceof Carbon ? $offer->sent_at : $offerDate;

        $base = in_array($status, ['accepted', 'rejected'], true)
            ? $sentAt->copy()->addDays(mt_rand(1, 10))
            : $sentAt->copy();

        if ($base->greaterThan($today)) {
            $base = $today->copy();
        }

        // Random time of day so several offers decided on the same day still land on
        // distinct points along the chart's time axis.
        $decidedAt = $base->copy()->setTime(mt_rand(8, 18), mt_rand(0, 59), mt_rand(0, 59));

        DB::table('customer_offers')
            ->where('id', $offer->id)
            ->update([
                'created_at' => $offerDate->copy()->startOfDay(),
                'updated_at' => $decidedAt,
            ]);
    }

    /**
     * Create standalone supplier orders from the weekly (unlinked) supplier offers
     * that were approved or processed.
     */
    private function seedStandaloneSupplierOrders(Tenant $tenant, int $unitId): void
    {
        $today = Carbon::today();

        $offers = SupplierOffer::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('customer_offer_id')
            ->whereIn('status', ['approved', 'processed'])
            ->with('items')
            ->get();

        foreach ($offers as $supplierOffer) {
            if (mt_rand(1, 100) > 65 || $supplierOffer->supplierOrder()->exists()) {
                continue;
            }

            $this->seedSupplierOrder($tenant, $supplierOffer, null, $unitId, $today);
        }
    }

    /**
     * Build one supplier order (and its items) from a supplier offer.
     */
    private function seedSupplierOrder(Tenant $tenant, SupplierOffer $supplierOffer, ?int $customerOfferId, int $unitId, Carbon $today): void
    {
        $items = $supplierOffer->items;

        if ($items->isEmpty()) {
            return;
        }

        $total = (float) $items->sum(fn (SupplierOfferItem $item): float => (float) $item->quantity * (float) $item->purchase_price);

        $base = $supplierOffer->received_at ? Carbon::parse($supplierOffer->received_at) : $today;
        $orderDate = $base->copy()->addDays(mt_rand(1, 6));

        $supplierOrder = SupplierOrder::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplierOffer->supplier_id,
            'supplier_offer_id' => $supplierOffer->id,
            'customer_offer_id' => $customerOfferId,
            'order_date' => $orderDate,
            'expected_date' => $orderDate->copy()->addDays(mt_rand(3, 14)),
            'currency' => 'RON',
            'status' => $this->ageStatus($today, $orderDate, 'supplier'),
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'notes' => $customerOfferId !== null ? 'Comandă furnizor din oferta acceptată.' : 'Comandă furnizor recurentă.',
        ]);

        foreach ($items as $item) {
            SupplierOrderItem::create([
                'tenant_id' => $tenant->id,
                'supplier_order_id' => $supplierOrder->id,
                'product_id' => $item->product_id,
                'unit_id' => $item->unit_id ?? $unitId,
                'quantity' => $item->quantity,
                'purchase_price' => $item->purchase_price,
                'currency' => 'RON',
                'line_total' => round((float) $item->quantity * (float) $item->purchase_price, 4),
            ]);
        }

        if ($customerOfferId === null) {
            $supplierOffer->update(['status' => 'approved']);
        }
    }

    private function offersPerTenant(): int
    {
        return (int) config('demo.customer_offers_per_tenant', 60);
    }

    /**
     * Weighted random pick: keys are values, values are relative weights.
     *
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));
        $cumulative = 0;

        foreach ($weights as $value => $weight) {
            $cumulative += $weight;

            if ($roll <= $cumulative) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }

    /**
     * Pick an order status weighted by how old the order is, so older orders are
     * more likely to be completed.
     */
    private function ageStatus(Carbon $today, Carbon $orderDate, string $side): string
    {
        $age = $today->diffInDays($orderDate);

        if ($side === 'sales') {
            return match (true) {
                $age > 60 => $this->weighted(['paid' => 55, 'delivered' => 20, 'invoiced' => 15, 'cancelled' => 10]),
                $age > 30 => $this->weighted(['invoiced' => 30, 'delivered' => 30, 'confirmed' => 25, 'cancelled' => 15]),
                default => $this->weighted(['confirmed' => 45, 'draft' => 35, 'delivered' => 15, 'cancelled' => 5]),
            };
        }

        return match (true) {
            $age > 60 => $this->weighted(['paid' => 50, 'received' => 25, 'invoiced' => 15, 'cancelled' => 10]),
            $age > 30 => $this->weighted(['invoiced' => 25, 'received' => 30, 'confirmed' => 25, 'in_preparation' => 10, 'cancelled' => 10]),
            default => $this->weighted(['confirmed' => 40, 'draft' => 30, 'in_preparation' => 20, 'cancelled' => 10]),
        };
    }

    private function downloadImage(string $url, ?int $tenantId, string $slug): ?string
    {
        if (app()->runningUnitTests()) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $extension = $this->extensionFromResponse($url, $response->header('Content-Type'));
            $path = sprintf('products/%s/%s.%s', $tenantId ?? 'shared', $slug, $extension);

            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    private function extensionFromResponse(string $url, ?string $contentType): string
    {
        return match (true) {
            str_contains((string) $contentType, 'png') => 'png',
            str_contains((string) $contentType, 'webp') => 'webp',
            str_ends_with($url, '.png') => 'png',
            str_ends_with($url, '.webp') => 'webp',
            default => 'jpg',
        };
    }

    private function slug(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
    }
}
