<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\CreateSupplierLead;
use App\Modules\Suppliers\Filament\Resources\SupplierLeads\Pages\ListSupplierLeads;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Models\SupplierLead;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class SupplierLeadResourceTest extends TestCase
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

        setPermissionsTeamId($this->tenant->getKey());
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:SupplierLead', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Create:SupplierLead', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Update:SupplierLead', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Create:Supplier', 'guard_name' => 'web']),
        );

        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    public function test_creating_a_lead_stamps_the_creator(): void
    {
        Livewire::test(CreateSupplierLead::class)
            ->fillForm([
                'name' => 'Agro Banat SRL',
                'country' => 'România',
                'email' => 'office@agro-banat.test',
                'phone' => '+40 256 111 222',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('supplier_leads', [
            'name' => 'Agro Banat SRL',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_converting_a_lead_creates_a_supplier_and_marks_it_converted(): void
    {
        $lead = SupplierLead::factory()->create([
            'name' => 'Legume Proaspete SRL',
            'country' => 'România',
            'email' => 'vanzari@legume.test',
            'phone' => '+40 21 999 888',
            'notes' => 'Wholesale vegetables.',
        ]);

        Livewire::test(ListSupplierLeads::class)
            ->callTableAction('convertToSupplier', $lead)
            ->assertHasNoTableActionErrors();

        $supplier = Supplier::query()->where('name', 'Legume Proaspete SRL')->first();

        $this->assertNotNull($supplier);
        $this->assertNull($supplier->tenant_id);
        $this->assertSame('vanzari@legume.test', $supplier->email);

        $lead->refresh();
        $this->assertSame($supplier->getKey(), $lead->converted_supplier_id);
        $this->assertNotNull($lead->converted_at);
        $this->assertTrue($lead->isConverted());
    }

    public function test_a_converted_lead_cannot_be_converted_again(): void
    {
        $lead = SupplierLead::factory()->converted()->create();

        Livewire::test(ListSupplierLeads::class)
            ->assertTableActionHidden('convertToSupplier', $lead);
    }
}
