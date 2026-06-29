<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\ItemsRelationManager;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerOfferItemsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_table_formats_prices_in_the_offer_currency(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer A']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Apples']);

        // The offer is in RON, so the items table must format money in RON, not EUR.
        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'RON',
            'status' => 'draft',
            'offer_date' => today(),
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
        ]);

        CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'purchase_price' => 12.5,
            'sale_price' => 18,
            'tax_rate' => 0,
            'line_total' => 54,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $offer,
            'pageClass' => EditCustomerOffer::class,
        ])
            ->assertSuccessful()
            // Money is formatted as "RON 12.50" (with a non-breaking space), never as euro.
            ->assertSee("RON\u{a0}12.50")
            ->assertSee("RON\u{a0}18.00")
            ->assertDontSee('€');
    }

    public function test_edit_form_locks_product_and_producer_product_and_drops_unit(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer A']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Ceapă galbenă']);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'RON',
            'status' => 'draft',
            'offer_date' => today(),
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
        ]);

        $item = CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'purchase_price' => 1.9,
            'sale_price' => 3,
            'tax_rate' => 0,
            'line_total' => 3,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $offer,
            'pageClass' => EditCustomerOffer::class,
        ])
            ->mountTableAction('edit', $item)
            // Product and Producer product are locked on edit.
            ->assertFormFieldDisabled('product_id')
            ->assertFormFieldDisabled('supplier_product_id')
            // The unit field was removed entirely from the form.
            ->assertFormFieldDoesNotExist('unit_id');
    }
}
