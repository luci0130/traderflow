<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\ProductCategoryTree;
use App\Modules\ProductCategories\Models\ProductCategory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class ProductCategoryTreePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_tree_page_is_registered_on_the_product_category_resource(): void
    {
        $this->assertTrue(class_exists(ProductCategoryTree::class));
        $this->assertTrue(Route::has('filament.admin.resources.product-categories.tree'));
    }

    public function test_tree_page_component_renders_categories_for_the_active_tenant(): void
    {
        [$tenant, $user] = $this->createTenantUser();
        $otherTenant = Tenant::create(['name' => 'Tenant B']);

        $root = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fresh Food',
        ]);
        ProductCategory::create([
            'tenant_id' => $tenant->id,
            'parent_id' => $root->id,
            'name' => 'Vegetables',
        ]);
        ProductCategory::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Tenant Root',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ProductCategoryTree::class)
            ->assertSee('Product Categories')
            ->assertSee('Fresh Food')
            ->assertSee('Vegetables')
            ->assertDontSee('Other Tenant Root')
            // Parent nodes are collapsed by default and expandable client-side.
            ->assertSeeHtml('x-data="{ expanded: false }"')
            ->assertSeeHtml('x-collapse')
            ->assertSee('Expand all')
            ->assertSee('Collapse all')
            // No ID label, no child-count badge.
            ->assertDontSee('(ID:');
    }

    public function test_tree_page_includes_global_categories_for_the_active_tenant(): void
    {
        [$tenant, $user] = $this->createTenantUser();

        ProductCategory::create([
            'tenant_id' => null,
            'name' => 'Global Root',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ProductCategoryTree::class)
            ->assertSee('Global Root');
    }

    public function test_selected_category_can_be_edited_from_the_tree_page(): void
    {
        [$tenant, $user] = $this->createTenantUser();

        $root = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fresh Food',
        ]);
        $child = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'parent_id' => $root->id,
            'name' => 'Vegetables',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ProductCategoryTree::class)
            ->call('selectCategory', $child->id)
            ->set('data.name', 'Leafy Vegetables')
            ->set('data.parent_id', null)
            ->set('data.status', 'inactive')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_categories', [
            'id' => $child->id,
            'tenant_id' => $tenant->id,
            'parent_id' => null,
            'name' => 'Leafy Vegetables',
            'status' => 'inactive',
        ]);
    }

    public function test_parent_category_can_be_cleared_from_the_tree_page(): void
    {
        [$tenant, $user] = $this->createTenantUser();

        $root = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fresh Food',
        ]);
        $child = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'parent_id' => $root->id,
            'name' => 'Vegetables',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ProductCategoryTree::class)
            ->call('selectCategory', $child->id)
            ->set('data.parent_id', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_categories', [
            'id' => $child->id,
            'parent_id' => null,
        ]);
    }

    public function test_parent_category_field_allows_the_empty_placeholder(): void
    {
        [$tenant, $user] = $this->createTenantUser();

        ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fresh Food',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ProductCategoryTree::class)
            ->assertFormFieldExists('parent_id', function (Select $field): bool {
                return $field->canSelectPlaceholder()
                    && (($field->getOptions()[''] ?? null) === 'No parent category');
            });
    }

    public function test_parent_category_options_preserve_database_ids(): void
    {
        [$tenant, $user] = $this->createTenantUser();

        $freshFood = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fresh Food',
        ]);
        $legumes = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Legumes',
        ]);
        $vegetables = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'parent_id' => $freshFood->id,
            'name' => 'Vegetables',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ProductCategoryTree::class)
            ->call('selectCategory', $vegetables->id)
            ->assertSet('data.parent_id', $freshFood->id)
            ->assertFormFieldExists('parent_id', function (Select $field) use ($freshFood, $legumes, $vegetables): bool {
                $options = $field->getOptions();

                return ($options[$freshFood->id] ?? null) === 'Fresh Food'
                    && ($options[$legumes->id] ?? null) === 'Legumes'
                    && ! array_key_exists($vegetables->id, $options);
            });
    }

    public function test_category_cannot_be_moved_under_one_of_its_children(): void
    {
        [$tenant, $user] = $this->createTenantUser();

        $root = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fresh Food',
        ]);
        $child = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'parent_id' => $root->id,
            'name' => 'Vegetables',
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ProductCategoryTree::class)
            ->call('selectCategory', $root->id)
            ->set('data.parent_id', $child->id)
            ->call('save')
            ->assertHasErrors(['data.parent_id']);

        $this->assertDatabaseHas('product_categories', [
            'id' => $root->id,
            'parent_id' => null,
        ]);
    }

    /**
     * @return array{Tenant, User}
     */
    private function createTenantUser(): array
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();

        $tenant->users()->attach($user);

        setPermissionsTeamId($tenant->id);
        Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);
        $user->assignRole('super_admin');

        session(['tenant_id' => $tenant->id]);

        return [$tenant, $user];
    }
}
