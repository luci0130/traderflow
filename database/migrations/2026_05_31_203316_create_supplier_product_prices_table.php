<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('supplier_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_quantity_value', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['supplier_product_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_product_prices');
    }
};
