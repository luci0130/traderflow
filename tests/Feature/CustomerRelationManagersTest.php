<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Filament\RelationManagers\ContactsRelationManager;
use App\Modules\Customers\Filament\RelationManagers\LocationsRelationManager;
use App\Modules\Customers\Filament\RelationManagers\OrdersRelationManager;
use App\Modules\Customers\Filament\RelationManagers\ProductsRelationManager;
use App\Modules\Customers\Filament\Resources\Customers\CustomerResource;
use App\Modules\Customers\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Customers\Enums\CustomerLocationType;
use App\Modules\Customers\Models\CustomerLocation;
use App\Modules\Documents\Filament\RelationManagers\DocumentsRelationManager;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Database\Factories\SupermarketFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerRelationManagersTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Tenant A']);
        $this->user = User::factory()->create();
        $this->tenant->users()->attach($this->user);

        $this->actingAs($this->user);
        session(['tenant_id' => $this->tenant->id]);
    }

    public function test_resource_registers_contacts_locations_products_orders_and_documents(): void
    {
        $relations = CustomerResource::getRelations();

        $this->assertContains(ContactsRelationManager::class, $relations);
        $this->assertContains(LocationsRelationManager::class, $relations);
        $this->assertContains(ProductsRelationManager::class, $relations);
        $this->assertContains(OrdersRelationManager::class, $relations);
        $this->assertContains(DocumentsRelationManager::class, $relations);
    }

    public function test_locations_tab_lists_only_locations_for_the_customer(): void
    {
        $supermarket = SupermarketFactory::new()->create(['name' => 'Lidl']);
        $otherSupermarket = SupermarketFactory::new()->create(['name' => 'Kaufland']);

        $visible = CustomerLocation::factory()->create([
            'customer_id' => $supermarket->id,
            'name' => 'Lidl Manastur',
        ]);
        $hidden = CustomerLocation::factory()->create([
            'customer_id' => $otherSupermarket->id,
            'name' => 'Kaufland Marasti',
        ]);

        Livewire::test(LocationsRelationManager::class, [
            'ownerRecord' => $supermarket,
            'pageClass' => EditCustomer::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    public function test_location_can_be_created_as_a_separate_legal_entity_with_billing_details(): void
    {
        $supermarket = SupermarketFactory::new()->create(['name' => 'Lidl']);

        Livewire::test(LocationsRelationManager::class, [
            'ownerRecord' => $supermarket,
            'pageClass' => EditCustomer::class,
        ])
            ->callTableAction('create', data: [
                'name' => 'Lidl Logistic',
                'type' => CustomerLocationType::Warehouse->value,
                'city' => 'Cluj-Napoca',
                'is_separate_legal_entity' => true,
                'legal_name' => 'Lidl Logistic SRL',
                'fiscal_code' => 'RO12345678',
                'bank_name' => 'ING Bank',
                'bank_account' => 'RO49INGB0000000000000000',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('customer_locations', [
            'customer_id' => $supermarket->id,
            'name' => 'Lidl Logistic',
            'is_separate_legal_entity' => true,
            'legal_name' => 'Lidl Logistic SRL',
            'fiscal_code' => 'RO12345678',
            'bank_account' => 'RO49INGB0000000000000000',
        ]);
    }

    public function test_orders_tab_lists_only_the_active_tenant_orders_for_the_customer(): void
    {
        $otherTenant = Tenant::create(['name' => 'Tenant B']);
        $customer = SupermarketFactory::new()->create(['name' => 'Kaufland']);

        $mine = SalesOrder::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $customer->id,
            'order_number' => 'SO-A',
            'status' => 'draft',
            'currency' => 'EUR',
        ]);
        $other = SalesOrder::create([
            'tenant_id' => $otherTenant->id,
            'customer_id' => $customer->id,
            'order_number' => 'SO-B',
            'status' => 'draft',
            'currency' => 'EUR',
        ]);

        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => EditCustomer::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_products_tab_lists_supermarket_products_linked_through_prices(): void
    {
        $supermarket = SupermarketFactory::new()->create(['name' => 'Lidl']);
        $linked = SupermarketProduct::factory()->create(['name' => 'Milk']);
        $unlinked = SupermarketProduct::factory()->create(['name' => 'Bread']);

        SupermarketPrice::factory()->create([
            'supermarket_id' => $supermarket->id,
            'supermarket_product_id' => $linked->id,
        ]);

        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $supermarket,
            'pageClass' => EditCustomer::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$linked])
            ->assertCanNotSeeTableRecords([$unlinked]);
    }
}
