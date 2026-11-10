<?php

namespace Database\Seeders;

use App\Models\CurrencyExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class CustomerOfferEditingTestSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::create(['name' => 'Offer browser test']);
        $user = User::factory()->create(['email' => 'mihai@traderflow.test']);
        $tenant->users()->attach($user);
        setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]));
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Browser customer']);
        CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON',
            'offer_date' => today(), 'status' => 'draft', 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
        CurrencyExchangeRate::factory()->create();
        CurrencyExchangeRate::factory()->inverse()->create();
        $canonical = CanonicalProduct::factory()->create(['name' => 'Browser tomatoes']);

        foreach (['First supplier', 'Second supplier'] as $name) {
            $supplier = Supplier::create(['name' => $name]);
            $product = SupplierProduct::create([
                'producer_id' => $supplier->id, 'name' => $canonical->name,
                'status' => 'active', 'unit_price' => 5, 'currency' => 'RON', 'quantity_available' => 100,
            ]);
            $canonical->supplierProducts()->attach($product);
        }

        for ($index = 1; $index <= 12; $index++) {
            CanonicalProduct::factory()->create(['name' => sprintf('Scroll fixture product %02d', $index)]);
        }
    }
}
