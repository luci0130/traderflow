<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\Sidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class AdminSidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        setPermissionsTeamId(null);
        Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]);

        Filament::setCurrentPanel('admin');
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user->refresh());

        return $user;
    }

    public function test_sidebar_renders_with_group_and_item_icons(): void
    {
        $this->actingAsSuperAdmin();

        // Rendering the whole sidebar exercises the overridden group view for
        // every navigation group. Filament's stock view throws when a group and
        // its items both have icons; a successful render proves the override
        // lets both icon sets coexist.
        Livewire::test(Sidebar::class)
            ->assertOk()
            ->assertSee(__('Administration'))
            ->assertSee(__('Supermarkets'));
    }

    public function test_configured_groups_are_collapsed_and_carry_an_icon(): void
    {
        $this->actingAsSuperAdmin();

        $groups = collect(Filament::getCurrentPanel()->getNavigation())
            ->keyBy(fn ($group): ?string => $group->getLabel());

        foreach ($this->expectedGroupOrder() as $label) {
            $group = $groups->get($label);

            $this->assertNotNull($group, "Navigation group [{$label}] is missing.");
            $this->assertTrue($group->isCollapsed(), "Navigation group [{$label}] should start collapsed.");
            $this->assertNotNull($group->getIcon(), "Navigation group [{$label}] should have an icon.");
        }
    }

    public function test_groups_render_in_the_configured_order(): void
    {
        $this->actingAsSuperAdmin();

        $renderedOrder = collect(Filament::getCurrentPanel()->getNavigation())
            ->map(fn ($group): ?string => $group->getLabel())
            ->filter()
            ->values()
            ->all();

        $this->assertSame($this->expectedGroupOrder(), $renderedOrder);
    }

    /**
     * @return list<string>
     */
    private function expectedGroupOrder(): array
    {
        return [
            __('Entities'),
            __('Catalog'),
            __('Purchasing'),
            __('Sales'),
            __('Analytics'),
            __('Reports'),
            __('Supermarkets'),
            __('Administration'),
        ];
    }
}
