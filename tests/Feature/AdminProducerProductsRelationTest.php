<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Suppliers\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers\ProductsRelationManager;
use App\Modules\Producers\Models\Producer;
use App\Modules\Producers\Models\SupplierProduct;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class AdminProducerProductsRelationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    private Producer $producer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme', 'is_active' => true, 'currency' => 'EUR']);

        Filament::setCurrentPanel('admin');

        setPermissionsTeamId($this->tenant->getKey());
        $superRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $this->tenant->getKey()]);

        $this->superAdmin = User::factory()->create(['email' => 'super@acme.test']);
        $this->tenant->users()->attach($this->superAdmin, ['role' => 'super_admin']);
        $this->superAdmin->assignRole($superRole);

        $this->producer = Producer::create(['name' => 'Producer A', 'status' => 'active']);
    }

    public function test_relation_manager_is_registered_on_producer_resource(): void
    {
        $this->assertContains(ProductsRelationManager::class, SupplierResource::getRelations());
    }

    public function test_relation_manager_shows_only_this_producers_products(): void
    {
        $own = SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'Our Apples',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(5),
        ]);

        $otherProducer = Producer::create(['name' => 'Other', 'status' => 'active']);
        $foreign = SupplierProduct::create([
            'producer_id' => $otherProducer->id,
            'name' => 'Their Apples',
            'status' => 'active',
            'currency' => 'EUR',
            'valid_until' => today()->addDays(5),
        ]);

        $this->actingAs($this->superAdmin);
        Filament::setTenant($this->tenant);

        Livewire::test(ProductsRelationManager::class, [
            'ownerRecord' => $this->producer,
            'pageClass' => SupplierResource\Pages\EditSupplier::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_badge_reflects_total_supplier_products(): void
    {
        SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'A',
            'status' => 'active',
            'currency' => 'EUR',
        ]);
        SupplierProduct::create([
            'producer_id' => $this->producer->id,
            'name' => 'B',
            'status' => 'archived',
            'currency' => 'EUR',
        ]);

        $this->assertSame('2', ProductsRelationManager::getBadge($this->producer->fresh(), SupplierResource\Pages\EditSupplier::class));
    }

    public function test_badge_is_null_when_no_products(): void
    {
        $this->assertNull(ProductsRelationManager::getBadge($this->producer, SupplierResource\Pages\EditSupplier::class));
    }
}
