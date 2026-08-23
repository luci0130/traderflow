<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Supermarkets\Filament\Resources\SupermarketProducts\Pages\CreateSupermarketProduct;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class CreateSupermarketProductSupplierLeadTest extends TestCase
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
            Permission::firstOrCreate(['name' => 'ViewAny:SupermarketProduct', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'Create:SupermarketProduct', 'guard_name' => 'web']),
        );

        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->tenant);
    }

    private function grantLeadPermission(): void
    {
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'Create:SupplierLead', 'guard_name' => 'web']),
        );
    }

    public function test_a_supplier_lead_added_while_creating_is_linked_to_the_new_product(): void
    {
        $this->grantLeadPermission();

        Livewire::test(CreateSupermarketProduct::class)
            ->fillForm([
                'name' => 'Roșii cherry',
                'status' => 'active',
                'vat_rate' => 9,
            ])
            ->callAction('addSupplierLead', data: [
                'name' => 'Ferma Bio Ardeal',
                'country' => 'România',
                'website' => 'https://ferma-ardeal.test',
                'email' => 'contact@ferma-ardeal.test',
                'phone' => '+40 745 123 456',
                'notes' => 'Sells organic tomatoes.',
            ])
            ->assertHasNoActionErrors()
            ->call('create')
            ->assertHasNoFormErrors();

        $product = SupermarketProduct::query()->where('name', 'Roșii cherry')->sole();

        $this->assertDatabaseHas('supplier_leads', [
            'name' => 'Ferma Bio Ardeal',
            'country' => 'România',
            'website' => 'https://ferma-ardeal.test',
            'email' => 'contact@ferma-ardeal.test',
            'phone' => '+40 745 123 456',
            'supermarket_product_id' => $product->getKey(),
            'created_by' => $this->user->id,
            'converted_supplier_id' => null,
        ]);
    }

    public function test_leads_are_only_persisted_once_the_product_is_created(): void
    {
        $this->grantLeadPermission();

        Livewire::test(CreateSupermarketProduct::class)
            ->callAction('addSupplierLead', data: [
                'name' => 'Gradina Verde SRL',
                'email' => 'office@gradina-verde.test',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseCount('supplier_leads', 0);
    }

    public function test_the_modal_opens_from_the_create_page_header(): void
    {
        $this->grantLeadPermission();

        Livewire::test(CreateSupermarketProduct::class)
            ->fillForm(['name' => 'Roșii cherry'])
            ->mountAction('addSupplierLead')
            ->assertActionMounted('addSupplierLead');
    }

    public function test_the_action_is_hidden_without_the_lead_permission(): void
    {
        Livewire::test(CreateSupermarketProduct::class)
            ->assertActionHidden('addSupplierLead');
    }
}
