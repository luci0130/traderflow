<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sourcing costs (packaging, transport, commission, profit margin) are set once
     * per supplier and act as defaults for all of that supplier's products. Each
     * product can override individual costs; a NULL override column means
     * "inherit the supplier default". Global reference data, no `tenant_id`.
     */
    public function up(): void
    {
        Schema::create('supplier_cost_defaults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->decimal('packaging_cost', 15, 4)->nullable();
            $table->decimal('transport_cost', 15, 4)->nullable();
            $table->decimal('commission', 15, 4)->nullable();
            $table->decimal('profit_margin', 15, 4)->nullable();
            $table->string('cost_basis')->default('per_unit');
            $table->string('currency', 3)->default('EUR');
            $table->timestamps();

            $table->unique('supplier_id');
        });

        Schema::create('supplier_product_cost_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->decimal('packaging_cost', 15, 4)->nullable();
            $table->decimal('transport_cost', 15, 4)->nullable();
            $table->decimal('commission', 15, 4)->nullable();
            $table->decimal('profit_margin', 15, 4)->nullable();
            $table->timestamps();

            $table->unique('supplier_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_product_cost_overrides');
        Schema::dropIfExists('supplier_cost_defaults');
    }
};
