<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Canonical products unify similar supplier products and supermarket products
     * under a single shared definition. Like the supplier/supermarket catalogs they
     * sit on top of, they are global reference data and carry no `tenant_id`.
     */
    public function up(): void
    {
        Schema::create('canonical_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('canonical_categories')->nullOnDelete();
            $table->timestamps();

            $table->index(['parent_id', 'name']);
        });

        Schema::create('canonical_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('variety')->nullable();
            $table->string('caliber')->nullable();
            $table->string('packaging_variant')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['canonical_category_id', 'name']);
            $table->index('name');
        });

        Schema::create('canonical_supplier_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('supplier_product_id');
        });

        Schema::create('canonical_supermarket_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canonical_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supermarket_product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('supermarket_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canonical_supermarket_product');
        Schema::dropIfExists('canonical_supplier_product');
        Schema::dropIfExists('canonical_products');
        Schema::dropIfExists('canonical_categories');
    }
};
