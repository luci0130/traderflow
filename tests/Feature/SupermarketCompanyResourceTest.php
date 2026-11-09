<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Supermarkets\Filament\RelationManagers\SupermarketPricesRelationManager;
use App\Modules\Supermarkets\Filament\RelationManagers\SupermarketProductsRelationManager;
use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages\CreateSupermarketPrice;
use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\Pages\EditSupermarketPrice;
use App\Modules\Supermarkets\Filament\Resources\SupermarketPrices\SupermarketPriceResource;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\EditSupplierLead;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\ListSupplierLeads;
use App\Modules\Suppliers\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierLead;
use Database\Factories\SupermarketFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupermarketCompanyResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Tenant A']);
        $this->user = User::factory()->create();
        $this->tenant->users()->attach($this->user);
        setPermissionsTeamId($this->tenant->id);

        foreach (['SupermarketPrice', 'SupermarketProduct', 'SupplierLead', 'Supplier'] as $model) {
            foreach (['ViewAny', 'View', 'Create', 'Update'] as $ability) {
                $this->user->givePermissionTo(Permission::firstOrCreate(['name' => $ability.':'.$model, 'guard_name' => 'web']));
            }
        }

        $this->actingAs($this->user);
        session(['tenant_id' => $this->tenant->id]);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->tenant);
    }

    /** @return array<string, mixed> */
    private function priceData(): array
    {
        return [
            'supermarket_id' => SupermarketFactory::new()->create()->id,
            'supermarket_product_id' => SupermarketProduct::factory()->create()->id,
            'price' => 10, 'currency' => 'RON', 'observed_at' => '2026-03-15', 'source' => 'manual',
        ];
    }

    public function test_a_price_can_be_created_without_a_company(): void
    {
        Livewire::test(CreateSupermarketPrice::class)->fillForm($this->priceData())
            ->call('create')->assertHasNoFormErrors();

        $price = SupermarketPrice::query()->sole();
        $this->assertNull($price->company);
        $this->assertSame($this->user->id, $price->recorded_by);
    }

    public function test_an_existing_lead_can_be_selected_on_the_price_form(): void
    {
        $lead = SupplierLead::factory()->create();
        Livewire::test(CreateSupermarketPrice::class)->fillForm([...$this->priceData(), 'company' => 'lead:'.$lead->id])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($lead->id, SupermarketPrice::query()->sole()->supplier_lead_id);
        $this->assertCount(1, $lead->supermarketProducts);
    }

    public function test_an_existing_supplier_can_be_selected_on_the_price_form(): void
    {
        $supplier = Supplier::create(['name' => 'Existing company']);
        Livewire::test(CreateSupermarketPrice::class)->fillForm([...$this->priceData(), 'company' => 'supplier:'.$supplier->id])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame($supplier->id, SupermarketPrice::query()->sole()->supplier_id);
    }

    public function test_a_new_lead_can_be_created_inline_and_selected_with_only_its_name(): void
    {
        Livewire::test(CreateSupermarketPrice::class)->fillForm($this->priceData())
            ->callAction(TestAction::make('createOption')->schemaComponent('company'), data: ['name' => 'Firma de pe eticheta'])
            ->assertHasNoActionErrors()
            ->call('create')->assertHasNoFormErrors();

        $lead = SupplierLead::query()->sole();
        $this->assertSame('Firma de pe eticheta', $lead->name);
        $this->assertSame($this->user->id, $lead->created_by);
        $this->assertSame($lead->id, SupermarketPrice::query()->sole()->supplier_lead_id);
    }

    public function test_inline_creation_validates_the_name_and_email_and_saves_contact_details(): void
    {
        $page = Livewire::test(CreateSupermarketPrice::class)
            ->callAction(TestAction::make('createOption')->schemaComponent('company'), data: ['name' => '', 'email' => 'invalid'])
            ->assertHasActionErrors(['name' => 'required', 'email' => 'email']);

        $this->assertDatabaseCount('supplier_leads', 0);

        Livewire::test(CreateSupermarketPrice::class)->callAction(TestAction::make('createOption')->schemaComponent('company'), data: [
            'name' => 'Firma completa', 'country' => 'Romania', 'address' => 'Strada Fermei 10',
            'phone' => '+40700000000', 'email' => 'contact@example.test', 'website' => 'https://example.test',
        ])->assertHasNoActionErrors();

        $this->assertDatabaseHas('supplier_leads', [
            'name' => 'Firma completa', 'address' => 'Strada Fermei 10', 'email' => 'contact@example.test',
            'website' => 'https://example.test', 'phone' => '+40700000000', 'country' => 'Romania',
        ]);
    }

    public function test_inline_lead_creation_requires_lead_creation_permission(): void
    {
        $this->user->revokePermissionTo('Create:SupplierLead');
        Livewire::test(CreateSupermarketPrice::class)
            ->assertActionHidden(TestAction::make('createOption')->schemaComponent('company'))
            ->mountAction(TestAction::make('createOption')->schemaComponent('company'))
            ->assertActionNotMounted();

        $this->assertDatabaseCount('supplier_leads', 0);
    }

    public function test_a_supplier_from_another_tenant_cannot_be_selected_or_submitted(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other tenant']);
        $supplier = Supplier::create(['name' => 'Private supplier', 'tenant_id' => $otherTenant->id]);
        $this->assertArrayNotHasKey('supplier:'.$supplier->id, SupermarketPriceResource::companyOptions()[__('Suppliers')]);

        Livewire::test(CreateSupermarketPrice::class)->fillForm([...$this->priceData(), 'company' => 'supplier:'.$supplier->id])
            ->call('create')->assertHasFormErrors(['company']);
        $this->assertDatabaseCount('supermarket_prices', 0);
    }

    public function test_a_company_can_be_changed_and_cleared_when_editing_a_price(): void
    {
        $lead = SupplierLead::factory()->create();
        $supplier = Supplier::create(['name' => 'New company']);
        $price = SupermarketPrice::factory()->create(['company' => 'lead:'.$lead->id]);

        Livewire::test(EditSupermarketPrice::class, ['record' => $price->id])
            ->assertFormSet(['company' => 'lead:'.$lead->id])
            ->fillForm(['company' => 'supplier:'.$supplier->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($supplier->id, $price->fresh()->supplier_id);
        $this->assertNull($price->fresh()->supplier_lead_id);

        Livewire::test(EditSupermarketPrice::class, ['record' => $price->id])
            ->fillForm(['company' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($price->fresh()->company);
    }

    public function test_conversion_preserves_address_products_and_observations_without_duplicates(): void
    {
        $lead = SupplierLead::factory()->create(['address' => 'Strada Fermei 10']);
        $products = SupermarketProduct::factory()->count(2)->create();
        $lead->supermarketProducts()->attach($products->modelKeys());
        $price = SupermarketPrice::factory()->create(['supplier_lead_id' => $lead->id, 'supermarket_product_id' => $products[0]->id]);

        Livewire::test(ListSupplierLeads::class)->callTableAction('convertToSupplier', $lead)->assertHasNoTableActionErrors();

        $supplier = $lead->fresh()->convertedSupplier;
        $this->assertSame('Strada Fermei 10', $supplier->address);
        $this->assertCount(2, $supplier->supermarketProducts);
        $this->assertTrue($supplier->supermarketPrices->contains($price));
        $this->assertDatabaseCount('supermarket_product_companies', 2);
        Livewire::test(ListSupplierLeads::class)->assertTableActionHidden('convertToSupplier', $lead->fresh());
    }

    public function test_history_is_scoped_to_the_company_on_lead_and_supplier_pages(): void
    {
        $lead = SupplierLead::factory()->converted()->create();
        $prices = collect([
            SupermarketPrice::factory()->create(['company' => 'lead:'.$lead->id, 'observed_at' => '2026-03-15', 'price' => 10]),
            SupermarketPrice::factory()->create(['company' => 'supplier:'.$lead->converted_supplier_id, 'observed_at' => '2026-04-15', 'price' => 11]),
        ]);
        $other = SupermarketPrice::factory()->create(['supplier_lead_id' => SupplierLead::factory()->create()->id]);

        foreach ([[$lead, EditSupplierLead::class], [$lead->convertedSupplier, EditSupplier::class]] as [$owner, $page]) {
            Livewire::test(SupermarketPricesRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => $page])
                ->assertSuccessful()->assertCanSeeTableRecords($prices)->assertCanNotSeeTableRecords([$other])
                ->filterTable('observed_at', ['from' => '2026-04-01'])
                ->assertCanSeeTableRecords([$prices[1]])->assertCanNotSeeTableRecords([$prices[0]]);
        }
    }

    public function test_products_without_prices_can_be_associated_from_both_company_pages(): void
    {
        $lead = SupplierLead::factory()->create();
        $supplier = Supplier::create(['name' => 'Existing supplier']);
        $product = SupermarketProduct::factory()->create();

        foreach ([[$lead, EditSupplierLead::class], [$supplier, EditSupplier::class]] as [$owner, $page]) {
            Livewire::test(SupermarketProductsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => $page])
                ->callTableAction('attach', data: ['recordId' => $product->id])
                ->assertHasNoTableActionErrors()->assertCanSeeTableRecords([$product]);
        }

        $this->assertDatabaseCount('supermarket_prices', 0);
    }

    public function test_history_and_product_association_check_owner_permissions_and_tenant_visibility(): void
    {
        $lead = SupplierLead::factory()->create();
        $this->user->revokePermissionTo('Update:SupplierLead');
        $this->user->revokePermissionTo('View:SupplierLead');
        $this->assertFalse(SupermarketPricesRelationManager::canViewForRecord($lead, EditSupplierLead::class));
        $this->assertFalse(SupermarketProductsRelationManager::canViewForRecord($lead, EditSupplierLead::class));

        $otherTenant = Tenant::create(['name' => 'Other tenant']);
        $supplier = Supplier::create(['name' => 'Private supplier', 'tenant_id' => $otherTenant->id]);
        $this->assertFalse(SupermarketPricesRelationManager::canViewForRecord($supplier, EditSupplier::class));
        $this->assertFalse(SupermarketProductsRelationManager::canViewForRecord($supplier, EditSupplier::class));
    }

    public function test_products_with_price_history_remain_associated_when_editing_the_lead(): void
    {
        $lead = SupplierLead::factory()->create(['phone' => null]);
        $price = SupermarketPrice::factory()->create(['supplier_lead_id' => $lead->id]);
        $extra = SupermarketProduct::factory()->create();
        $lead->supermarketProducts()->attach($extra);

        Livewire::test(EditSupplierLead::class, ['record' => $lead->id])
            ->fillForm(['supermarketProducts' => []])->call('save')->assertHasNoFormErrors();

        $this->assertTrue($lead->fresh()->supermarketProducts->contains($price->product));
        $this->assertFalse($lead->fresh()->supermarketProducts->contains($extra));
        $this->assertModelExists($price);
    }
}
