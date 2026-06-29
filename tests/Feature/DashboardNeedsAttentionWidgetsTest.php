<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Dashboard\Filament\Widgets\SupplierLeadsToConvertWidget;
use App\Modules\Suppliers\Models\SupplierLead;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class DashboardNeedsAttentionWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_leads_widget_lists_only_unconverted_leads(): void
    {
        setPermissionsTeamId(null);
        Role::create(['name' => 'purchasing_agent', 'guard_name' => 'web', 'tenant_id' => null]);

        $user = User::factory()->create();
        $user->assignRole('purchasing_agent');
        $this->actingAs($user->refresh());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $openLeads = SupplierLead::factory()->count(2)->create();
        $convertedLead = SupplierLead::factory()->converted()->create();

        Livewire::test(SupplierLeadsToConvertWidget::class)
            ->assertCanSeeTableRecords($openLeads)
            ->assertCanNotSeeTableRecords([$convertedLead]);
    }
}
