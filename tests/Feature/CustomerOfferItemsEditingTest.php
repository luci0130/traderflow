<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Components\OfferProductPicker;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\CreateCustomerOffer;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\ListCustomerOffers;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\RelationManagers\ItemsRelationManager;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Services\CustomerOfferDuplicator;
use App\Modules\Customers\Models\Customer;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\MarketComparison\Services\SupermarketOfferBuilder;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\Suppliers\Models\Supplier;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerOfferItemsEditingTest extends TestCase
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
        Filament::setTenant($this->tenant);
        setPermissionsTeamId($this->tenant->id);
        $this->user->givePermissionTo(array_map(
            fn (string $name): Permission => Permission::firstOrCreate(['name' => $name.':CustomerOffer', 'guard_name' => 'web']),
            ['ViewAny', 'View', 'Create', 'Update'],
        ));
    }

    private function offer(): CustomerOffer
    {
        return CustomerOffer::create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'Client'])->id,
            'currency' => 'RON', 'status' => 'draft', 'offer_date' => today(),
            'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);
    }

    /** @return array{CanonicalProduct, SupplierProduct} */
    private function marketProduct(string $name = 'Tomatoes'): array
    {
        $canonical = CanonicalProduct::factory()->create(['name' => $name]);
        $supplierProduct = $this->supplierProduct($canonical);

        return [$canonical, $supplierProduct];
    }

    private function supplierProduct(CanonicalProduct $canonical): SupplierProduct
    {
        $supplier = Supplier::create(['name' => 'Supplier '.Supplier::query()->count()]);
        $product = SupplierProduct::create([
            'producer_id' => $supplier->id, 'name' => $canonical->name,
            'status' => 'active', 'unit_price' => 5, 'currency' => 'RON', 'quantity_available' => 100,
        ]);
        $canonical->supplierProducts()->attach($product);

        return $product;
    }

    private function board(CustomerOffer $offer): Testable
    {
        return Livewire::test(ItemsRelationManager::class, [
            'ownerRecord' => $offer, 'pageClass' => EditCustomerOffer::class,
        ]);
    }

    /** @return array<string, array{string}> */
    public static function agentRoles(): array
    {
        return ['sales' => ['sales_agent'], 'purchasing' => ['purchasing_agent']];
    }

    #[DataProvider('agentRoles')]
    public function test_both_agents_can_add_products_to_a_manually_created_offer(string $role): void
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]);
        $this->user->assignRole($role);
        $customer = Customer::create(['name' => 'Manual client', 'tenant_id' => $this->tenant->id]);

        Livewire::test(CreateCustomerOffer::class)
            ->fillForm(['tenant_id' => $this->tenant->id, 'customer_id' => $customer->id, 'currency' => 'RON', 'status' => 'draft'])
            ->call('create')->assertHasNoFormErrors();
        $offer = CustomerOffer::query()->sole();
        [$canonical, $supplierProduct] = $this->marketProduct();

        $this->board($offer)->assertActionVisible('addProducts');
        Livewire::test(OfferProductPicker::class, ['offerId' => $offer->id])
            ->assertSee('Tomatoes')
            ->call('toggleProductSelection', $canonical->id, $supplierProduct->id)
            ->call('addSelected')
            ->assertHasNoErrors()
            ->assertDispatched('offer-products-added', offerId: $offer->id);

        $this->assertSame(1, CustomerOffer::query()->count());
        $item = $offer->items()->sole();
        $this->assertSame('100.0000', $item->quantity);
        $this->assertSame($supplierProduct->id, $item->suppliers()->sole()->supplier_product_id);
    }

    public function test_add_product_opens_the_market_comparison_table_in_a_screen_width_modal(): void
    {
        $offer = $this->offer();
        $this->marketProduct();

        $board = $this->board($offer)->mountAction('addProducts')->assertMountedActionModalSee('Add selected products')->assertMountedActionModalSee('Tomatoes');
        $this->assertSame(Width::Screen, $board->instance()->addProductsAction()->getModalWidth());
        $board->assertMountedActionModalSeeHtml('min-w-[1200px]');
    }

    public function test_adding_suppliers_preserves_existing_item_and_supplier_values_and_skips_duplicates(): void
    {
        $offer = $this->offer();
        [$canonical, $first] = $this->marketProduct();
        $second = $this->supplierProduct($canonical);
        $builder = app(SupermarketOfferBuilder::class);
        $builder->appendSelection($offer, [$canonical->id => [$first->id]]);
        $item = $offer->items()->sole();
        $item->product->update(['tenant_id' => null]);
        $item->update(['quantity' => 33, 'sale_price' => 12, 'purchase_price' => 7]);
        $existing = $item->suppliers()->sole();
        $existing->update(['landed_cost' => 7, 'secured_quantity' => 20, 'include_in_order' => false]);
        $itemAttributes = $item->fresh()->getAttributes();
        $supplierAttributes = $existing->fresh()->getAttributes();

        $builder->appendSelection($offer, [$canonical->id => [$first->id, $second->id]]);
        $builder->appendSelection($offer, [$canonical->id => [$second->id]]);

        $this->assertSame(1, $offer->items()->count());
        $this->assertSame(1, Product::query()->count());
        $this->assertSame($itemAttributes, $item->fresh()->getAttributes());
        $this->assertSame($supplierAttributes, $existing->fresh()->getAttributes());
        $this->assertSame([1, 2], $item->suppliers()->pluck('priority')->all());
        $this->assertSame([$first->id, $second->id], $item->suppliers()->pluck('supplier_product_id')->all());
    }

    public function test_invalid_or_expired_supplier_selection_does_not_silently_use_another_supplier(): void
    {
        $offer = $this->offer();
        [$canonical, $product] = $this->marketProduct();
        $product->update(['valid_until' => today()->subDay()]);

        try {
            app(SupermarketOfferBuilder::class)->appendSelection($offer, [$canonical->id => [$product->id]]);
            $this->fail('Expected invalid selection to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selection', $exception->errors());
        }

        $this->assertSame(0, $offer->items()->count());
    }

    public function test_suppliers_from_a_different_canonical_product_are_rejected(): void
    {
        $offer = $this->offer();
        [$canonical] = $this->marketProduct();
        [, $other] = $this->marketProduct('Peppers');
        $this->expectException(ValidationException::class);
        app(SupermarketOfferBuilder::class)->appendSelection($offer, [$canonical->id => [$other->id]]);
    }

    public function test_empty_selection_shows_validation_and_does_not_modify_the_offer(): void
    {
        $offer = $this->offer();
        Livewire::test(OfferProductPicker::class, ['offerId' => $offer->id])
            ->call('addSelected')->assertHasErrors('selection');
        $this->assertSame(0, $offer->items()->count());
    }

    public function test_removing_a_product_removes_all_its_rows_and_recalculates_totals_without_deleting_catalog_records(): void
    {
        $offer = $this->offer();
        [$canonical, $supplierProduct] = $this->marketProduct();
        app(SupermarketOfferBuilder::class)->appendSelection($offer, [$canonical->id => [$supplierProduct->id]]);
        $item = $offer->items()->sole();
        $productId = $item->product_id;
        $rowId = $item->suppliers()->sole()->id;

        $this->board($offer)->callAction('deleteItem', arguments: ['item' => $item->id])->assertHasNoActionErrors();

        $this->assertModelMissing($item);
        $this->assertDatabaseMissing('customer_offer_item_suppliers', ['id' => $rowId]);
        $this->assertDatabaseHas('products', ['id' => $productId]);
        $this->assertModelExists($supplierProduct);
        $this->assertDatabaseHas('suppliers', ['id' => $supplierProduct->producer_id]);
        $this->assertSame('0.0000', $offer->fresh()->total);
    }

    public function test_removing_a_supplier_only_affects_that_product_and_renumbers_remaining_priorities(): void
    {
        $offer = $this->offer();
        [$canonical, $first] = $this->marketProduct();
        $second = $this->supplierProduct($canonical);
        app(SupermarketOfferBuilder::class)->appendSelection($offer, [$canonical->id => [$first->id, $second->id]]);
        $item = $offer->items()->sole();
        $removed = $item->suppliers()->first();
        $retained = $item->suppliers()->where('supplier_product_id', $second->id)->sole();
        $retained->update(['landed_cost' => 8, 'secured_quantity' => 10]);

        $otherProduct = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
        $otherItem = $offer->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $otherProduct->id, 'quantity' => 1, 'sale_price' => 1]);
        $otherRow = $otherItem->suppliers()->create(['supplier_id' => $first->producer_id, 'priority' => 1]);

        $this->board($offer)->callAction('deleteSupplier', arguments: ['supplier' => $removed->id])->assertHasNoActionErrors();

        $this->assertModelMissing($removed);
        $this->assertModelExists($otherRow);
        $this->assertSame(1, $retained->fresh()->priority);
        $this->assertSame($second->producer_id, $item->fresh()->supplier_id);
        $this->assertSame('8.0000', $item->fresh()->purchase_price);
    }

    public function test_removing_the_last_supplier_also_removes_the_product(): void
    {
        $offer = $this->offer();
        [$canonical, $product] = $this->marketProduct();
        app(SupermarketOfferBuilder::class)->appendSelection($offer, [$canonical->id => [$product->id]]);
        $item = $offer->items()->sole();

        $this->board($offer)->callAction('deleteSupplier', arguments: ['supplier' => $item->suppliers()->sole()->id])
            ->assertHasNoActionErrors();

        $this->assertModelMissing($item);
        $this->assertSame('0.0000', $offer->fresh()->total);
    }

    public function test_removing_an_item_from_another_offer_is_forbidden(): void
    {
        $offer = $this->offer();
        $otherOffer = $this->offer();
        [$canonical, $product] = $this->marketProduct();
        app(SupermarketOfferBuilder::class)->appendSelection($otherOffer, [$canonical->id => [$product->id]]);
        $item = $otherOffer->items()->sole();

        try {
            $this->board($offer)->callAction('deleteItem', arguments: ['item' => $item->id]);
            $this->fail('Expected an item outside this offer to be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertModelExists($item);
        }
        $this->assertModelExists($item);
    }

    public function test_picker_cannot_access_another_tenants_offer(): void
    {
        $offer = $this->offer();
        $otherTenant = Tenant::create(['name' => 'Other']);
        $offer->update(['tenant_id' => $otherTenant->id]);

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(OfferProductPicker::class, ['offerId' => $offer->id]);
    }

    public function test_view_only_users_cannot_open_the_picker_or_remove_rows(): void
    {
        $offer = $this->offer();
        $this->user->revokePermissionTo('Update:CustomerOffer');

        $this->board($offer)->assertActionHidden('addProducts')->assertActionHidden('deleteItem')->assertActionHidden('deleteSupplier');
        Livewire::test(OfferProductPicker::class, ['offerId' => $offer->id])->assertForbidden();
    }

    public function test_accepted_offers_are_read_only_including_existing_inline_methods(): void
    {
        $offer = $this->offer();
        [$canonical, $product] = $this->marketProduct();
        app(SupermarketOfferBuilder::class)->appendSelection($offer, [$canonical->id => [$product->id]]);
        $item = $offer->items()->sole();
        $row = $item->suppliers()->sole();
        $offer->update(['status' => 'accepted']);
        $original = $item->getAttributes();
        $supplierOriginal = $row->getAttributes();

        $this->board($offer)->assertActionHidden('addProducts')->assertActionHidden('deleteItem')->assertActionHidden('deleteSupplier')
            ->call('saveSale', $item->id, 'sale_price', 100)
            ->call('saveSourcing', $row->id, 'landed_cost', 100)
            ->call('saveInclude', $row->id, false);
        Livewire::test(OfferProductPicker::class, ['offerId' => $offer->id])->assertForbidden();
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->id])
            ->assertSuccessful()->assertActionHidden('sendOfferEmail')->assertActionVisible('duplicateOffer')
            ->assertFormFieldIsDisabled('status');

        $this->assertSame($original, $item->fresh()->getAttributes());
        $this->assertSame($supplierOriginal, $row->fresh()->getAttributes());
    }

    public function test_an_offer_accepted_after_the_edit_page_was_opened_cannot_be_saved(): void
    {
        $offer = $this->offer();
        $page = Livewire::test(EditCustomerOffer::class, ['record' => $offer->id]);
        $offer->update(['status' => 'accepted']);
        $page->fillForm(['notes' => 'Should not save', 'status' => 'draft'])->call('save')->assertForbidden();
        $this->assertSame('accepted', $offer->fresh()->status);
        $this->assertNull($offer->fresh()->notes);
    }

    public function test_accepted_offer_cannot_be_appended_to_directly(): void
    {
        $offer = $this->offer();
        [$canonical, $product] = $this->marketProduct();
        $offer->update(['status' => 'accepted']);
        $this->expectException(HttpException::class);
        app(SupermarketOfferBuilder::class)->appendSelection($offer, [$canonical->id => [$product->id]]);
    }

    public function test_duplicate_action_deep_copies_accepted_offer_into_an_editable_draft_without_orders(): void
    {
        $offer = $this->offer();
        [$canonical, $product] = $this->marketProduct();
        $second = $this->supplierProduct($canonical);
        app(SupermarketOfferBuilder::class)->appendSelection($offer, [$canonical->id => [$product->id, $second->id]]);
        $item = $offer->items()->sole();
        $item->update(['quantity' => 17, 'sale_price' => 11]);
        $item->suppliers()->first()->update(['landed_cost' => 6, 'secured_quantity' => 9]);
        $offer->update(['status' => 'accepted', 'sent_at' => now()]);
        $order = SalesOrder::create([
            'tenant_id' => $this->tenant->id, 'customer_offer_id' => $offer->id,
            'customer_id' => $offer->customer_id, 'currency' => 'RON', 'status' => 'draft',
            'order_date' => today(), 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
        ]);

        Livewire::test(EditCustomerOffer::class, ['record' => $offer->id])
            ->callAction('duplicateOffer')->assertHasNoActionErrors()->assertRedirect();

        $duplicate = CustomerOffer::query()->whereKeyNot($offer->id)->sole();
        $this->assertSame('draft', $duplicate->status);
        $this->assertNotSame($offer->offer_number, $duplicate->offer_number);
        $this->assertNull($duplicate->sent_at);
        $this->assertFalse($duplicate->isLocked());
        $this->assertFalse($duplicate->salesOrder()->exists());
        $this->assertFalse($duplicate->supplierOffers()->exists());
        $copiedItem = $duplicate->items()->sole();
        $this->assertNotSame($item->id, $copiedItem->id);
        $this->assertSame($item->sale_price, $copiedItem->sale_price);
        $this->assertSame($item->quantity, $copiedItem->quantity);
        $this->assertSame($item->suppliers()->pluck('supplier_id')->all(), $copiedItem->suppliers()->pluck('supplier_id')->all());
        $this->assertSame('9.0000', $copiedItem->suppliers()->first()->secured_quantity);
        $this->assertSame($offer->fresh()->total, $duplicate->total);
        $this->assertSame('accepted', $offer->fresh()->status);
        $this->assertModelExists($order);
        $this->board($duplicate)->assertActionVisible('addProducts');
    }

    public function test_duplicate_requires_create_permission(): void
    {
        $offer = $this->offer();
        $this->user->revokePermissionTo('Create:CustomerOffer');
        Livewire::test(EditCustomerOffer::class, ['record' => $offer->id])->assertActionHidden('duplicateOffer');
        $this->expectException(AuthorizationException::class);
        app(CustomerOfferDuplicator::class)->duplicate($offer);
    }

    public function test_bulk_delete_cannot_remove_an_accepted_offer(): void
    {
        $offer = $this->offer();
        $offer->update(['status' => 'accepted']);
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'DeleteAny:CustomerOffer', 'guard_name' => 'web']));

        Livewire::test(ListCustomerOffers::class)->callTableBulkAction('delete', [$offer])->assertForbidden();

        $this->assertModelExists($offer);
    }
}
