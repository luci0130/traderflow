<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Services\CustomerOfferConverter;
use App\Modules\Customers\Models\Customer;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\Product;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

use function setPermissionsTeamId;

class DemoDataSeeder extends Seeder
{
    private const ROLE_NAMES = ['super_admin', 'tenant_admin', 'manager', 'seller_agent', 'viewer'];

    private const TENANTS = [
        'A' => [
            'name' => 'GreenFields Trading',
            'legal_name' => 'GreenFields Trading SRL',
            'currency' => 'EUR',
            'country' => 'Romania',
            'city' => 'Bucharest',
            'email' => 'office@greenfields.test',
        ],
        'B' => [
            'name' => 'Mediterraneo Produce',
            'legal_name' => 'Mediterraneo Produce Srl',
            'currency' => 'EUR',
            'country' => 'Italy',
            'city' => 'Verona',
            'email' => 'info@mediterraneo.test',
        ],
    ];

    private const CATEGORIES = [
        'A' => ['Citrus Fruits', 'Stone Fruits', 'Berries', 'Leafy Vegetables', 'Root Vegetables'],
        'B' => ['Tropical Fruits', 'Melons', 'Cruciferous Vegetables', 'Tomatoes & Peppers', 'Squashes & Gourds'],
    ];

    private const UNITS = [
        'A' => [
            ['name' => 'Kilogram', 'symbol' => 'kg'],
            ['name' => 'Tonne', 'symbol' => 't'],
            ['name' => 'Crate', 'symbol' => 'crt'],
            ['name' => 'Pallet', 'symbol' => 'plt'],
            ['name' => 'Bag', 'symbol' => 'bag'],
        ],
        'B' => [
            ['name' => 'Kilogram', 'symbol' => 'kg'],
            ['name' => 'Box', 'symbol' => 'box'],
            ['name' => 'Net', 'symbol' => 'net'],
            ['name' => 'Bundle', 'symbol' => 'bdl'],
            ['name' => 'Piece', 'symbol' => 'pc'],
        ],
    ];

    /**
     * Per tenant: category => list of [productName, basePriceEur].
     */
    private const PRODUCTS = [
        'A' => [
            'Citrus Fruits' => [
                ['Valencia Orange', 0.85],
                ['Lemon Eureka', 1.20],
                ['Lime Persian', 1.45],
            ],
            'Stone Fruits' => [
                ['White Peach', 1.60],
                ['Red Plum', 1.30],
                ['Yellow Cherry', 3.20],
            ],
            'Berries' => [
                ['Strawberry Albion', 2.80],
                ['Blueberry Bluecrop', 4.10],
                ['Raspberry Tulameen', 4.60],
            ],
            'Leafy Vegetables' => [
                ['Baby Spinach', 1.50],
                ['Romaine Lettuce', 1.20],
                ['Curly Kale', 1.80],
            ],
            'Root Vegetables' => [
                ['Nantes Carrot', 0.45],
                ['Russet Potato', 0.35],
                ['Red Beetroot', 0.55],
            ],
        ],
        'B' => [
            'Tropical Fruits' => [
                ['Cavendish Banana', 0.75],
                ['Hass Avocado', 2.10],
                ['Ataulfo Mango', 1.90],
                ['Smooth Pineapple', 0.95],
            ],
            'Melons' => [
                ['Galia Melon', 0.95],
                ['Sugar Baby Watermelon', 0.55],
            ],
            'Cruciferous Vegetables' => [
                ['Calabrese Broccoli', 1.20],
                ['Snowball Cauliflower', 1.10],
                ['Savoy Cabbage', 0.70],
            ],
            'Tomatoes & Peppers' => [
                ['San Marzano Tomato', 1.40],
                ['Cherry Tomato', 1.95],
                ['Red Bell Pepper', 1.85],
            ],
            'Squashes & Gourds' => [
                ['Green Zucchini', 1.05],
                ['Hokkaido Pumpkin', 0.90],
                ['English Cucumber', 0.80],
            ],
        ],
    ];

    private const SUPPLIERS = [
        'A' => [
            'Carpathian Orchards SRL',
            'Danube Valley Produce',
            'Transylvania Berries Co',
            'Sun Garden Farms',
            'Black Sea Greens',
        ],
        'B' => [
            'Sicilia Agrumi Srl',
            'Bel Paese Frutta',
            'Verona Hortus',
            'Calabria Fresh',
            'Puglia Sole',
        ],
    ];

    private const CUSTOMERS = [
        'A' => [
            'CityMart Distribution',
            'FreshHub Logistics',
            'NorthLine Retail',
            'Capital Fresh Market',
            'Carpathian Catering Group',
        ],
        'B' => [
            'Riviera Wholesale',
            'Adriatico Foods',
            'Toscana Markets',
            'Lazio Restaurants Group',
            'Alpine Fresh Imports',
        ],
    ];

    /**
     * Users with their tenant and role.
     *
     * @var list<array{name: string, email: string, tenant: string, role: string}>
     */
    private const USERS = [
        ['name' => 'Alice Ionescu', 'email' => 'alice@traderflow.test', 'tenant' => 'A', 'role' => 'super_admin'],
        ['name' => 'Bogdan Popescu', 'email' => 'bogdan@greenfields.test', 'tenant' => 'A', 'role' => 'tenant_admin'],
        ['name' => 'Carla Marin', 'email' => 'carla@greenfields.test', 'tenant' => 'A', 'role' => 'seller_agent'],
        ['name' => 'Dario Rossi', 'email' => 'dario@mediterraneo.test', 'tenant' => 'B', 'role' => 'manager'],
        ['name' => 'Elena Conti', 'email' => 'elena@mediterraneo.test', 'tenant' => 'B', 'role' => 'viewer'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $tenants = $this->seedTenants();
            $this->seedRoles($tenants);
            $this->seedUsers($tenants);

            $catalogs = [
                'A' => $this->seedTenantCatalog('A', $tenants['A']),
                'B' => $this->seedTenantCatalog('B', $tenants['B']),
            ];

            $supplierOfferItems = [
                'A' => $this->seedSupplierOffers('A', $tenants['A'], $catalogs['A']),
                'B' => $this->seedSupplierOffers('B', $tenants['B'], $catalogs['B']),
            ];

            $this->seedCustomerOffersAndSalesOrders($tenants['A'], $catalogs['A'], $supplierOfferItems['A']);
        });
    }

    /**
     * @return array{A: Tenant, B: Tenant}
     */
    private function seedTenants(): array
    {
        $created = [];

        foreach (self::TENANTS as $key => $data) {
            $created[$key] = Tenant::create([
                ...$data,
                'is_active' => true,
            ]);
        }

        return $created;
    }

    /**
     * @param  array{A: Tenant, B: Tenant}  $tenants
     */
    private function seedRoles(array $tenants): void
    {
        foreach ($tenants as $tenant) {
            foreach (self::ROLE_NAMES as $name) {
                Role::firstOrCreate([
                    'name' => $name,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->getKey(),
                ]);
            }
        }
    }

    /**
     * @param  array{A: Tenant, B: Tenant}  $tenants
     */
    private function seedUsers(array $tenants): void
    {
        foreach (self::USERS as $entry) {
            $tenant = $tenants[$entry['tenant']];

            $user = User::create([
                'name' => $entry['name'],
                'email' => $entry['email'],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]);

            $tenant->users()->attach($user, ['role' => $entry['role']]);

            setPermissionsTeamId($tenant->getKey());
            $user->assignRole($entry['role']);
        }
    }

    /**
     * @return array{
     *     categories: array<string, ProductCategory>,
     *     units: array<string, Unit>,
     *     products: list<Product>,
     *     base_prices: array<int, float>,
     *     suppliers: list<Supplier>,
     *     customers: list<Customer>
     * }
     */
    private function seedTenantCatalog(string $key, Tenant $tenant): array
    {
        $categories = [];
        foreach (self::CATEGORIES[$key] as $name) {
            $categories[$name] = ProductCategory::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'status' => 'active',
            ]);
        }

        $units = [];
        foreach (self::UNITS[$key] as $unit) {
            $units[$unit['symbol']] = Unit::create([
                'tenant_id' => $tenant->id,
                'name' => $unit['name'],
                'symbol' => $unit['symbol'],
            ]);
        }

        $defaultUnit = $units['kg'];

        $products = [];
        $basePrices = [];
        $index = 1;
        foreach (self::PRODUCTS[$key] as $categoryName => $entries) {
            foreach ($entries as [$productName, $basePrice]) {
                $product = Product::create([
                    'tenant_id' => $tenant->id,
                    'product_category_id' => $categories[$categoryName]->id,
                    'unit_id' => $defaultUnit->id,
                    'sku' => sprintf('%s-%03d', $key, $index++),
                    'name' => $productName,
                    'status' => 'active',
                    'description' => $productName.' — fresh '.strtolower($categoryName).'.',
                ]);
                $products[] = $product;
                $basePrices[$product->id] = (float) $basePrice;
            }
        }

        $suppliers = [];
        foreach (self::SUPPLIERS[$key] as $i => $name) {
            $suppliers[] = Supplier::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'legal_name' => $name,
                'email' => $this->slug($name).'@suppliers.test',
                'country' => $tenant->country,
                'city' => $tenant->city,
                'status' => 'active',
                'contact_person' => 'Sales Desk '.($i + 1),
                'payment_terms' => 'Net '.[14, 21, 30, 45][$i % 4].' days',
            ]);
        }

        $customers = [];
        foreach (self::CUSTOMERS[$key] as $i => $name) {
            $customers[] = Customer::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'legal_name' => $name,
                'email' => $this->slug($name).'@customers.test',
                'country' => $tenant->country,
                'city' => $tenant->city,
                'status' => 'active',
                'contact_person' => 'Purchasing '.($i + 1),
                'payment_terms' => 'Net '.[7, 14, 30][$i % 3].' days',
            ]);
        }

        return [
            'categories' => $categories,
            'units' => $units,
            'products' => $products,
            'base_prices' => $basePrices,
            'suppliers' => $suppliers,
            'customers' => $customers,
        ];
    }

    /**
     * @param  array{units: array<string, Unit>, products: list<Product>, base_prices: array<int, float>, suppliers: list<Supplier>}  $catalog
     * @return list<SupplierOfferItem>
     */
    private function seedSupplierOffers(string $key, Tenant $tenant, array $catalog): array
    {
        $items = [];
        $offerSeq = 1;
        $statuses = ['received', 'received', 'received', 'processed', 'approved', 'received', 'approved', 'received', 'processed', 'received'];
        $today = Carbon::today();

        foreach ($catalog['suppliers'] as $supplierIndex => $supplier) {
            for ($n = 0; $n < 2; $n++) {
                $supplierOffer = SupplierOffer::create([
                    'tenant_id' => $tenant->id,
                    'supplier_id' => $supplier->id,
                    'offer_number' => sprintf('SO-%s-%04d', $key, $offerSeq),
                    'received_at' => $today->copy()->subDays(mt_rand(0, 20)),
                    'valid_until' => $today->copy()->addDays(mt_rand(7, 40)),
                    'currency' => 'EUR',
                    'status' => $statuses[($offerSeq - 1) % count($statuses)],
                    'source_type' => 'manual',
                    'notes' => 'Weekly produce offer.',
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
                        'currency' => 'EUR',
                        'availability_date' => $today->copy()->addDays(mt_rand(1, 14)),
                    ]);
                }

                $offerSeq++;
            }
        }

        return $items;
    }

    /**
     * @param  array{products: list<Product>, customers: list<Customer>, units: array<string, Unit>}  $catalog
     * @param  list<SupplierOfferItem>  $supplierOfferItems
     */
    private function seedCustomerOffersAndSalesOrders(Tenant $tenant, array $catalog, array $supplierOfferItems): void
    {
        $statuses = ['draft', 'sent', 'sent', 'accepted', 'accepted'];
        $today = Carbon::today();

        $bestPerProduct = collect($supplierOfferItems)
            ->groupBy('product_id')
            ->map(fn ($group) => $group->sortBy('purchase_price')->first())
            ->values();

        $converter = app(CustomerOfferConverter::class);

        foreach ($catalog['customers'] as $index => $customer) {
            $status = $statuses[$index];

            $offer = CustomerOffer::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'offer_number' => sprintf('CO-%04d', $index + 1),
                'offer_date' => $today->copy()->subDays(mt_rand(0, 10)),
                'valid_until' => $today->copy()->addDays(mt_rand(7, 21)),
                'currency' => 'EUR',
                'status' => $status,
                'sent_at' => in_array($status, ['sent', 'accepted'], true) ? now() : null,
                'notes' => 'Demo offer for '.$customer->name,
            ]);

            $picks = $bestPerProduct->shuffle()->take(mt_rand(3, 5));

            foreach ($picks as $supplierItem) {
                $purchasePrice = (float) $supplierItem->purchase_price;
                $salePrice = round($purchasePrice * 1.25, 4);

                CustomerOfferItem::create([
                    'tenant_id' => $tenant->id,
                    'customer_offer_id' => $offer->id,
                    'product_id' => $supplierItem->product_id,
                    'supplier_id' => $supplierItem->supplierOffer?->supplier_id,
                    'supplier_offer_item_id' => $supplierItem->id,
                    'unit_id' => $supplierItem->unit_id,
                    'quantity' => mt_rand(20, 200),
                    'purchase_price' => $purchasePrice,
                    'sale_price' => $salePrice,
                    'tax_rate' => 9,
                ]);
            }

            if ($status === 'accepted') {
                $converter->convert($offer->refresh());
            }

            // Backdate the timestamps so reports keyed on updated_at (the accept/
            // reject moment) don't collapse to seed time. created_at follows the
            // offer date; updated_at the decision date a few days after sending.
            $offerDate = Carbon::parse($offer->offer_date);
            $sentAt = $offer->sent_at ? Carbon::parse($offer->sent_at) : $offerDate;
            $decidedAt = $status === 'accepted'
                ? $sentAt->copy()->addDays(mt_rand(1, 10))->setTime(mt_rand(8, 18), mt_rand(0, 59))
                : $sentAt->copy()->setTime(mt_rand(8, 18), mt_rand(0, 59));

            if ($decidedAt->greaterThan($today)) {
                $decidedAt = $today->copy()->setTime(mt_rand(8, 18), mt_rand(0, 59));
            }

            DB::table('customer_offers')
                ->where('id', $offer->id)
                ->update([
                    'created_at' => $offerDate->copy()->startOfDay(),
                    'updated_at' => $decidedAt,
                ]);
        }
    }

    private function slug(string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
    }
}
