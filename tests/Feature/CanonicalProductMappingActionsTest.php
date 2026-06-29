<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\Pages\EditCanonicalProduct;
use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\RelationManagers\SupermarketProductsRelationManager;
use App\Modules\MarketComparison\Filament\Resources\CanonicalProducts\RelationManagers\SupplierProductsRelationManager;
use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class CanonicalProductMappingActionsTest extends TestCase
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

    public function test_bulk_map_attaches_multiple_supermarket_products_and_moves_mapped_ones(): void
    {
        $target = CanonicalProduct::create(['name' => 'Roșii cherry']);
        $other = CanonicalProduct::create(['name' => 'Other group']);

        $unmapped = SupermarketProduct::create(['name' => 'Roșii cherry', 'package_size' => 250, 'package_unit' => 'g']);
        $mappedElsewhere = SupermarketProduct::create(['name' => 'Roșii cherry kg', 'package_unit' => 'kg']);
        $other->supermarketProducts()->attach($mappedElsewhere->id);

        Livewire::test(SupermarketProductsRelationManager::class, [
            'ownerRecord' => $target,
            'pageClass' => EditCanonicalProduct::class,
        ])
            ->callTableAction('map', null, ['records' => [$unmapped->id, $mappedElsewhere->id]])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsCanonicalizing(
            [$unmapped->id, $mappedElsewhere->id],
            $target->fresh()->supermarketProducts->pluck('id')->all(),
        );
        // The product was moved out of its previous canonical (one product = one group).
        $this->assertCount(0, $other->fresh()->supermarketProducts);
    }

    public function test_bulk_map_attaches_multiple_supplier_products(): void
    {
        $target = CanonicalProduct::create(['name' => 'Banane']);
        $supplier = Supplier::create(['tenant_id' => null, 'name' => 'Ferma Verde']);

        $a = SupplierProduct::create(['producer_id' => $supplier->id, 'name' => 'Banane', 'status' => 'active', 'currency' => 'RON']);
        $b = SupplierProduct::create(['producer_id' => $supplier->id, 'name' => 'Banane bio', 'status' => 'active', 'currency' => 'RON']);

        Livewire::test(SupplierProductsRelationManager::class, [
            'ownerRecord' => $target,
            'pageClass' => EditCanonicalProduct::class,
        ])
            ->callTableAction('map', null, ['records' => [$a->id, $b->id]])
            ->assertHasNoTableActionErrors();

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $target->fresh()->supplierProducts->pluck('id')->all(),
        );
    }
}
