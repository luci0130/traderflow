<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\MarketComparison\Filament\Resources\Transporters\Pages\ListTransporters;
use App\Modules\MarketComparison\Models\Transporter;
use App\Modules\MarketComparison\Models\TransportRoute;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class TransporterTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticateSuperAdmin(): void
    {
        $tenant = Tenant::create(['name' => 'Transporter Co', 'is_active' => true, 'currency' => 'RON']);

        Filament::setCurrentPanel('admin');

        setPermissionsTeamId($tenant->getKey());
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $tenant->getKey()]);

        $user = User::factory()->create();
        $tenant->users()->attach($user, ['role' => 'super_admin']);
        $user->assignRole($role);

        session(['tenant_id' => $tenant->getKey()]);

        $this->actingAs($user);
    }

    public function test_transporter_tables_are_global_reference_data(): void
    {
        foreach (['transporters', 'transport_routes'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertFalse(
                Schema::hasColumn($table, 'tenant_id'),
                "Table [{$table}] should remain global and must not have a tenant_id column.",
            );
        }
    }

    public function test_transporter_resource_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('filament.admin.resources.transporters.index'));
        $this->assertTrue(Route::has('filament.admin.resources.transporters.create'));
    }

    public function test_route_cost_is_calculated_from_distance_and_cost_per_km(): void
    {
        $transporter = Transporter::create([
            'name' => 'Trans Iberia SRL',
            'phone' => '+40 740 000 000',
            'cost_per_km' => 1.5,
        ]);

        $route = TransportRoute::create([
            'transporter_id' => $transporter->id,
            'origin' => 'Spania',
            'destination' => 'Turda',
            'distance_km' => 2600,
        ]);

        $this->assertSame(3900.0, $route->resolved_cost);
    }

    public function test_an_explicit_estimated_cost_wins_over_the_calculated_one(): void
    {
        $transporter = Transporter::create([
            'name' => 'Trans Iberia SRL',
            'cost_per_km' => 1.5,
        ]);

        $route = TransportRoute::create([
            'transporter_id' => $transporter->id,
            'origin' => 'Spania',
            'destination' => 'Turda',
            'distance_km' => 2600,
            'estimated_cost' => 3500,
        ]);

        $this->assertSame(3500.0, $route->resolved_cost);
    }

    public function test_transporters_can_be_filtered_by_city(): void
    {
        $this->authenticateSuperAdmin();

        $cluj = Transporter::create(['name' => 'Cluj Trans', 'city' => 'Cluj-Napoca']);
        $iasi = Transporter::create(['name' => 'Iasi Trans', 'city' => 'Iasi']);

        Livewire::test(ListTransporters::class)
            ->assertCanSeeTableRecords([$cluj, $iasi])
            ->filterTable('city', 'Cluj-Napoca')
            ->assertCanSeeTableRecords([$cluj])
            ->assertCanNotSeeTableRecords([$iasi]);
    }

    public function test_route_cost_is_null_without_distance_or_cost_per_km(): void
    {
        $transporter = Transporter::create(['name' => 'Trans Iberia SRL']);

        $route = TransportRoute::create([
            'transporter_id' => $transporter->id,
            'origin' => 'Spania',
            'destination' => 'Turda',
        ]);

        $this->assertNull($route->resolved_cost);
    }
}
