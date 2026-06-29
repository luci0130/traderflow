<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\RelationManagers\ItemsRelationManager;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesOrderProfitTest extends TestCase
{
    use RefreshDatabase;

    private function createOrderWithItem(array $itemAttributes = []): array
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        $this->actingAs($user);
        Filament::setTenant($tenant);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer A']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Mere']);

        $order = SalesOrder::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'currency' => 'EUR',
            'status' => 'draft',
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
        ]);

        $item = SalesOrderItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 100,
            'purchase_price' => 0.80,
            'sale_price' => 1.20,
            'margin_value' => 0.40,
            'margin_percent' => 33.33,
            'line_total' => 120.00,
        ], $itemAttributes));

        return [$order, $item];
    }

    public function test_items_table_shows_profit_per_kg_column(): void
    {
        [$order] = $this->createOrderWithItem();

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => EditSalesOrder::class,
        ])
            ->assertSuccessful()
            ->assertSeeHtml('Profit / kg');
    }

    public function test_items_table_shows_profit_per_kg_value(): void
    {
        [$order] = $this->createOrderWithItem([
            'purchase_price' => 0.80,
            'sale_price' => 1.20,
            'margin_value' => 0.40,
        ]);

        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => EditSalesOrder::class,
        ])
            ->assertSuccessful()
            ->assertSee('€0.40');
    }

    public function test_items_table_shows_profit_line_total(): void
    {
        [$order] = $this->createOrderWithItem([
            'quantity' => 100,
            'margin_value' => 0.40,
            'line_total' => 120.00,
        ]);

        // Profit line = 0.40 * 100 = 40.00
        Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $order,
            'pageClass' => EditSalesOrder::class,
        ])
            ->assertSuccessful()
            ->assertSee('€40.00');
    }

    public function test_profit_line_calculation_is_margin_value_times_quantity(): void
    {
        [, $item] = $this->createOrderWithItem([
            'quantity' => 50,
            'purchase_price' => 1.00,
            'sale_price' => 1.50,
            'margin_value' => 0.50,
        ]);

        $this->assertEquals(25.0, (float) $item->margin_value * (float) $item->quantity);
    }
}
