<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Producers\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Modules\Producers\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Modules\Producers\Models\Producer;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\PackagingMethod;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class SupplierProductResourceTest extends TestCase
{
    use RefreshDatabase;

    private Producer $producer;

    private User $producerUser;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('producer');

        setPermissionsTeamId(null);

        $role = Role::create([
            'name' => 'producer',
            'guard_name' => 'web',
            'tenant_id' => null,
        ]);

        foreach (['ViewAny:SupplierProduct', 'View:SupplierProduct', 'Create:SupplierProduct', 'Update:SupplierProduct', 'Delete:SupplierProduct'] as $name) {
            $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }

        $this->producer = Producer::create(['name' => 'Acme Producer', 'status' => 'active']);
        $this->producerUser = User::factory()->create(['producer_id' => $this->producer->id]);
        $this->producerUser->assignRole('producer');
    }

    public function test_producer_only_sees_their_own_products_on_list_page(): void
    {
        $own = SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'My Apples',
            'status' => 'active',
            'currency' => 'EUR',
        ]);

        $otherProducer = Producer::create(['name' => 'Other', 'status' => 'active']);
        $foreign = SupplierProduct::create([
            'producer_id' => $otherProducer->id,
            'name' => 'Their Apples',
            'status' => 'active',
            'currency' => 'EUR',
        ]);

        $this->actingAs($this->producerUser);

        Livewire::test(ListSupplierProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_quick_dynamic_rows_create_products_and_default_price_rows(): void
    {
        $packagingMethod = PackagingMethod::query()->where('name', 'Plasă')->firstOrFail();

        $this->actingAs($this->producerUser);

        Livewire::test(ListSupplierProducts::class)
            ->set('quickProducts.rows', [
                [
                    'name' => 'Golden Apples',
                    'packaging_method_id' => $packagingMethod->id,
                    'quantity_available' => '1200',
                    'valid_until' => today()->addWeeks(2)->toDateString(),
                    'min_quantity_unit' => 'kg',
                    'currency' => 'RON',
                    'min_quantity_value' => '100',
                    'unit_price' => '4.50',
                ],
            ])
            ->call('createQuickProducts')
            ->assertHasNoErrors();

        $product = SupplierProduct::query()->where('name', 'Golden Apples')->firstOrFail();

        $this->assertSame($this->producer->id, $product->producer_id);
        $this->assertSame($packagingMethod->id, $product->packaging_method_id);
        $this->assertSame('1200.0000', $product->quantity_available);
        $this->assertSame('100.0000', $product->min_quantity_value);
        $this->assertSame('kg', $product->min_quantity_unit);
        $this->assertSame('4.5000', $product->unit_price);
        $this->assertSame('RON', $product->currency);

        $this->assertDatabaseHas('supplier_product_prices', [
            'supplier_product_id' => $product->id,
            'min_quantity_value' => 100,
            'unit_price' => 4.5,
            'sort_order' => 0,
        ]);
    }

    public function test_advanced_pricing_rows_are_persisted_and_first_row_stays_default_price(): void
    {
        $product = SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'Tiered Apples',
            'status' => 'active',
            'currency' => 'RON',
            'min_quantity_unit' => 'kg',
            'min_quantity_value' => 100,
            'unit_price' => 4.5,
        ]);

        SupplierProductResource::replacePriceBreaks($product, [
            ['min_quantity_value' => 100, 'unit_price' => 4.5],
            ['min_quantity_value' => 500, 'unit_price' => 4.1],
        ]);

        $product->refresh();

        $this->assertSame('100.0000', $product->min_quantity_value);
        $this->assertSame('4.5000', $product->unit_price);
        $this->assertSame([100, 500], $product->prices()->pluck('min_quantity_value')->map(fn (string $value): int => (int) $value)->all());
        $this->assertSame([4.5, 4.1], $product->prices()->pluck('unit_price')->map(fn (string $value): float => (float) $value)->all());
    }

    public function test_producer_panel_home_url_opens_my_products(): void
    {
        $this->actingAs($this->producerUser);

        $this->assertSame(
            SupplierProductResource::getUrl('index'),
            Filament::getPanel('producer')->getHomeUrl(),
        );
    }

    public function test_is_offer_valid_accessor_reflects_status_and_valid_until(): void
    {
        $valid = SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'Valid',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(5),
        ]);

        $expired = SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'Expired',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->subDay(),
        ]);

        $archived = SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'Archived',
            'status' => 'archived',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(5),
        ]);

        $this->assertTrue($valid->is_offer_valid);
        $this->assertFalse($expired->is_offer_valid);
        $this->assertFalse($archived->is_offer_valid);
    }

    public function test_supplier_product_policy_blocks_actions_on_another_producers_row(): void
    {
        $otherProducer = Producer::create(['name' => 'Other', 'status' => 'active']);
        $foreign = SupplierProduct::create([
            'producer_id' => $otherProducer->id,
            'name' => 'Their Apples',
            'status' => 'active',
            'currency' => 'EUR',
        ]);

        $this->actingAs($this->producerUser);

        $this->assertTrue($this->producerUser->can('viewAny', SupplierProduct::class));
        $this->assertFalse($this->producerUser->can('update', $foreign));
        $this->assertFalse($this->producerUser->can('delete', $foreign));
    }
}
