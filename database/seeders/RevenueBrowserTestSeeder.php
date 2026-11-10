<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Services\CustomerOfferAcceptor;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\Suppliers\Models\Supplier;

class RevenueBrowserTestSeeder extends CustomerOfferEditingTestSeeder
{
    public function run(): void
    {
        parent::run();
        $tenant = Tenant::query()->firstOrFail();
        $customer = Customer::query()->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();
        $product = Product::create(['name' => 'Revenue fixture tomatoes']);

        foreach (['REV-ESTIMATE', 'REV-REALIZED'] as $number) {
            $offer = CustomerOffer::create([
                'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'currency' => 'EUR',
                'offer_number' => $number, 'offer_date' => today(), 'status' => 'draft',
                'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
            ]);
            $item = $offer->items()->create([
                'tenant_id' => $tenant->id, 'product_id' => $product->id,
                'quantity' => 100, 'sale_price' => 10, 'purchase_price' => 8.5, 'tax_rate' => 0,
            ]);
            $item->suppliers()->create([
                'supplier_id' => $supplier->id, 'priority' => 1, 'unit_price' => 7.5,
                'secured_quantity' => 100, 'transport_cost' => 100, 'currency' => 'EUR',
            ]);

            if ($number === 'REV-REALIZED') {
                app(CustomerOfferAcceptor::class)->accept($offer)->update(['status' => 'paid']);
            }
        }
    }
}
