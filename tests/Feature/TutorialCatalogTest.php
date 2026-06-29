<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TutorialCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class TutorialCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The catalog's role gating relies on global (tenant_id = NULL) roles.
        setPermissionsTeamId(null);

        foreach (['super_admin', 'sales_agent', 'purchasing_agent'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web', 'tenant_id' => null]);
        }
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->refresh();
    }

    /**
     * @return array<int, string>
     */
    private function keysFor(User $user): array
    {
        return array_column(TutorialCatalog::forUser($user), 'key');
    }

    public function test_guest_gets_no_tutorials(): void
    {
        $this->assertSame([], TutorialCatalog::forUser(null));
    }

    public function test_sales_agent_only_sees_sales_tutorials(): void
    {
        $keys = $this->keysFor($this->userWithRole('sales_agent'));

        $this->assertContains('admin_welcome', $keys);
        $this->assertContains('add_customer', $keys);
        $this->assertContains('create_customer_offer', $keys);

        $this->assertNotContains('add_supplier', $keys);
        $this->assertNotContains('create_supplier_offer', $keys);
        $this->assertNotContains('add_supplier_product', $keys);
        $this->assertNotContains('add_product', $keys);
    }

    public function test_purchasing_agent_only_sees_purchasing_tutorials(): void
    {
        $keys = $this->keysFor($this->userWithRole('purchasing_agent'));

        $this->assertContains('admin_welcome', $keys);
        $this->assertContains('add_supplier', $keys);
        $this->assertContains('create_supplier_offer', $keys);
        $this->assertContains('add_supplier_product', $keys);

        $this->assertNotContains('add_customer', $keys);
        $this->assertNotContains('create_customer_offer', $keys);
        $this->assertNotContains('add_product', $keys);
    }

    public function test_super_admin_sees_every_tutorial(): void
    {
        $keys = $this->keysFor($this->userWithRole('super_admin'));

        $this->assertEqualsCanonicalizing([
            'admin_welcome',
            'add_customer',
            'create_customer_offer',
            'add_supplier',
            'create_supplier_offer',
            'add_supplier_product',
            'add_product',
        ], $keys);
    }
}
