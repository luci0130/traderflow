<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Producers\Models\Producer;
use App\Modules\Suppliers\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers\UsersRelationManager;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierContact;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierUnificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_producers_are_stored_as_self_managed_global_suppliers(): void
    {
        $producer = Producer::create([
            'name' => 'Acme Producer',
            'email' => 'producer@example.test',
            'status' => 'active',
        ]);

        $this->assertFalse(Schema::hasTable('producers'));

        $supplier = Supplier::query()->withoutGlobalScopes()->findOrFail($producer->id);

        $this->assertNull($supplier->tenant_id);
        $this->assertTrue($supplier->is_producer);
        $this->assertSame(Supplier::MANAGEMENT_MODE_SELF, $supplier->management_mode);
        $this->assertSame('Acme Producer', $supplier->name);
    }

    public function test_suppliers_support_global_scope_and_contacts_without_user_accounts(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Tenant']);

        $globalSupplier = Supplier::create([
            'tenant_id' => null,
            'name' => 'Global Supplier',
        ]);

        $tenantSupplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tenant Supplier',
        ]);

        $otherTenantSupplier = Supplier::create([
            'tenant_id' => Tenant::create(['name' => 'Other Tenant'])->id,
            'name' => 'Other Supplier',
        ]);

        $contact = SupplierContact::create([
            'supplier_id' => $globalSupplier->id,
            'name' => 'Maria Popescu',
            'role_in_company' => 'Sales manager',
            'email' => 'maria@example.test',
            'phone' => '+40 700 000 000',
            'is_primary' => true,
        ]);

        $visibleSupplierIds = Supplier::query()
            ->visibleToTenant($tenant->id)
            ->pluck('id')
            ->all();

        $this->assertContains($globalSupplier->id, $visibleSupplierIds);
        $this->assertContains($tenantSupplier->id, $visibleSupplierIds);
        $this->assertNotContains($otherTenantSupplier->id, $visibleSupplierIds);
        $this->assertNull($contact->user_id);
        $this->assertSame('Sales manager', $contact->role_in_company);
    }

    public function test_supplier_resource_shows_global_suppliers_inside_a_tenant(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Tenant']);
        $otherTenant = Tenant::create(['name' => 'Other Tenant']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);

        $globalSupplier = Supplier::create([
            'tenant_id' => null,
            'name' => 'Global Supplier',
        ]);
        $tenantSupplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tenant Supplier',
        ]);
        $otherTenantSupplier = Supplier::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Supplier',
        ]);

        $this->actingAs($user);
        session(['tenant_id' => $tenant->id]);
        Filament::setCurrentPanel(filament()->getDefaultPanel());
        Filament::setTenant($tenant);
        Filament::bootCurrentPanel();

        // Suppliers are part of the shared global catalog: every supplier is
        // visible regardless of the active tenant.
        $visibleSupplierIds = SupplierResource::getEloquentQuery()
            ->pluck('id')
            ->all();

        $this->assertContains($globalSupplier->id, $visibleSupplierIds);
        $this->assertContains($tenantSupplier->id, $visibleSupplierIds);
        $this->assertContains($otherTenantSupplier->id, $visibleSupplierIds);

        Filament::setTenant(null, isQuiet: true);
    }

    public function test_supplier_resource_exposes_supplier_users_for_impersonation(): void
    {
        $this->assertContains(UsersRelationManager::class, SupplierResource::getRelations());
    }
}
