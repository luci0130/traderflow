<?php

namespace Tests\Feature;

use App\Modules\MarketComparison\Models\SupplierCostDefault;
use App\Modules\MarketComparison\Models\SupplierProductCostOverride;
use App\Modules\MarketComparison\Services\SupplierCostResolver;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierCostResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_cost_tables_are_global_reference_data(): void
    {
        foreach (['supplier_cost_defaults', 'supplier_product_cost_overrides'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertFalse(
                Schema::hasColumn($table, 'tenant_id'),
                "Table [{$table}] should remain global and must not have a tenant_id column.",
            );
        }
    }

    public function test_costs_fall_back_to_supplier_defaults_when_no_override_exists(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        SupplierCostDefault::create([
            'supplier_id' => $supplier->id,
            'packaging_cost' => 0.5,
            'transport_cost' => 1.2,
            'commission' => 0.3,
            'profit_margin' => 1.0,
        ]);
        $product = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
        ]);

        $costs = app(SupplierCostResolver::class)->resolve($product);

        $this->assertSame(0.5, $costs->packagingCost);
        $this->assertSame(1.2, $costs->transportCost);
        $this->assertSame(0.3, $costs->commission);
        $this->assertSame(1.0, $costs->profitMargin);
    }

    public function test_a_filled_override_field_wins_while_null_fields_inherit_the_default(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        SupplierCostDefault::create([
            'supplier_id' => $supplier->id,
            'packaging_cost' => 0.5,
            'transport_cost' => 1.2,
            'commission' => 0.3,
            'profit_margin' => 1.0,
        ]);
        $product = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
        ]);
        SupplierProductCostOverride::create([
            'supplier_product_id' => $product->id,
            'transport_cost' => 2.5,
        ]);

        $costs = app(SupplierCostResolver::class)->resolve($product);

        $this->assertSame(2.5, $costs->transportCost);
        $this->assertSame(0.5, $costs->packagingCost);
        $this->assertSame(0.3, $costs->commission);
        $this->assertSame(1.0, $costs->profitMargin);
    }

    public function test_costs_default_to_zero_without_defaults_or_overrides(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        $product = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'currency' => 'RON',
        ]);

        $costs = app(SupplierCostResolver::class)->resolve($product);

        $this->assertSame(0.0, $costs->packagingCost);
        $this->assertSame(0.0, $costs->transportCost);
        $this->assertSame(0.0, $costs->commission);
        $this->assertSame(0.0, $costs->profitMargin);
        $this->assertSame('RON', $costs->currency);
    }

    public function test_landed_cost_adds_all_sourcing_costs_to_the_unit_price(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        SupplierCostDefault::create([
            'supplier_id' => $supplier->id,
            'packaging_cost' => 0.5,
            'transport_cost' => 1.2,
            'commission' => 0.3,
            'profit_margin' => 1.0,
        ]);
        $product = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 5,
        ]);

        $costs = app(SupplierCostResolver::class)->resolve($product);

        $this->assertSame(2.0, $costs->additionalCostPerUnit());
        $this->assertSame(7.0, $costs->landedCost(5.0));
        $this->assertSame(8.0, $costs->targetSalePrice(5.0));
    }

    public function test_percent_basis_applies_the_margin_as_a_percentage_of_the_landed_cost(): void
    {
        $supplier = Supplier::create(['name' => 'Ferma Verde SRL']);
        SupplierCostDefault::create([
            'supplier_id' => $supplier->id,
            'packaging_cost' => 1,
            'transport_cost' => 2,
            'commission' => 1,
            'profit_margin' => 25,
            'cost_basis' => SupplierCostDefault::COST_BASIS_PERCENT,
        ]);
        $product = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Portocale',
            'status' => 'active',
            'unit_price' => 6,
        ]);

        $costs = app(SupplierCostResolver::class)->resolve($product);

        $this->assertSame(10.0, $costs->landedCost(6.0));
        $this->assertSame(12.5, $costs->targetSalePrice(6.0));
    }
}
