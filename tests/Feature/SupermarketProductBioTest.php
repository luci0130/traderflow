<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages\CreateSupermarketProduct;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages\ListSupermarketProducts;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\SupermarketProductResource;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class SupermarketProductBioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Acme', 'is_active' => true, 'currency' => 'EUR']);

        Filament::setCurrentPanel('admin');

        setPermissionsTeamId($tenant->getKey());
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $tenant->getKey()]);

        $user = User::factory()->create();
        $tenant->users()->attach($user, ['role' => 'super_admin']);
        $user->assignRole($role);

        session(['tenant_id' => $tenant->getKey()]);

        $this->actingAs($user);
    }

    public function test_supermarket_product_can_be_flagged_as_bio(): void
    {
        Livewire::test(CreateSupermarketProduct::class)
            ->fillForm([
                'name' => 'Rosii bio',
                'is_bio' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = SupermarketProduct::query()->where('name', 'Rosii bio')->firstOrFail();

        $this->assertTrue($product->is_bio);
    }

    public function test_supermarket_product_defaults_to_not_bio(): void
    {
        $product = SupermarketProduct::create(['name' => 'Rosii normale']);

        $this->assertFalse($product->fresh()->is_bio);
    }

    public function test_category_is_selected_from_the_product_category_taxonomy(): void
    {
        ProductCategory::create(['name' => 'Legume']);
        // A legacy free-text value already on a product stays selectable.
        SupermarketProduct::create(['name' => 'Veche', 'category' => 'Broccoli']);

        $options = SupermarketProductResource::categoryOptions();
        $this->assertArrayHasKey('Legume', $options);
        $this->assertArrayHasKey('Broccoli', $options);

        Livewire::test(CreateSupermarketProduct::class)
            ->fillForm([
                'name' => 'Morcovi',
                'category' => 'Legume',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Legume', SupermarketProduct::query()->where('name', 'Morcovi')->value('category'));
    }

    public function test_status_and_description_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('supermarket_products', 'status'));
        $this->assertTrue(Schema::hasColumn('supermarket_products', 'description'));
    }

    public function test_create_form_defaults_status_origin_unit_and_packaging(): void
    {
        $vracId = PackagingMethod::query()->where('name', 'Vrac')->value('id');

        Livewire::test(CreateSupermarketProduct::class)
            ->assertFormSet([
                'status' => 'active',
                'origin' => 'RO',
                'package_unit' => 'kg',
                'packaging_method_id' => $vracId,
            ]);
    }

    public function test_create_persists_the_advanced_status_and_description(): void
    {
        Livewire::test(CreateSupermarketProduct::class)
            ->fillForm([
                'name' => 'Mere bio',
                'status' => 'archived',
                'brand' => 'Golden',
                'description' => 'Mere din livada proprie.',
                'origin' => 'RO',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = SupermarketProduct::query()->where('name', 'Mere bio')->firstOrFail();

        $this->assertSame('archived', $product->status);
        $this->assertSame('Golden', $product->brand);
        $this->assertSame('Mere din livada proprie.', $product->description);
        $this->assertSame('RO', $product->origin);
    }

    public function test_bulk_action_maps_selected_products_to_a_canonical_and_moves_grouped_ones(): void
    {
        $target = CanonicalProduct::create(['name' => 'Ardei kapia']);
        $other = CanonicalProduct::create(['name' => 'Other']);

        $a = SupermarketProduct::create(['name' => 'Ardei kapia']);
        $b = SupermarketProduct::create(['name' => 'Ardei kapia rosu']);
        $other->supermarketProducts()->attach($b->id);

        Livewire::test(ListSupermarketProducts::class)
            ->callTableBulkAction('mapToCanonical', [$a->id, $b->id], ['canonical_product_id' => $target->id])
            ->assertHasNoTableBulkActionErrors();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $target->fresh()->supermarketProducts->pluck('id')->all());
        $this->assertCount(0, $other->fresh()->supermarketProducts);
    }
}
