<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Producers\Models\Producer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class ProducerPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_producer_user_can_access_producer_panel(): void
    {
        [$user, $producer] = $this->createProducerUser();
        $producer->update(['status' => 'active']);
        $user->load('producer');

        $panel = Filament::getPanel('producer');
        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_inactive_producer_user_cannot_access_producer_panel(): void
    {
        [$user, $producer] = $this->createProducerUser();
        $producer->update(['status' => 'inactive']);
        $user->load('producer');

        $panel = Filament::getPanel('producer');
        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_producer_user_cannot_access_admin_panel(): void
    {
        [$user] = $this->createProducerUser();

        $panel = Filament::getPanel('admin');
        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_admin_user_cannot_access_producer_panel(): void
    {
        $user = User::factory()->create();

        $panel = Filament::getPanel('producer');
        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_producer_panel_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('filament.producer.auth.login'));
        $this->assertTrue(Route::has('filament.producer.auth.register'));
        $this->assertTrue(Route::has('filament.producer.pages.producer-dashboard'));
    }

    /**
     * @return array{User, Producer}
     */
    private function createProducerUser(): array
    {
        setPermissionsTeamId(null);

        Role::firstOrCreate([
            'name' => 'producer',
            'guard_name' => 'web',
            'tenant_id' => null,
        ]);

        $producer = Producer::create(['name' => 'Acme Producer', 'status' => 'active']);
        $user = User::factory()->create(['producer_id' => $producer->id]);
        $user->assignRole('producer');

        return [$user->fresh(['producer']), $producer];
    }
}
