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
        Schema::create('producer_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producer_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained('supplier_products')->nullOnDelete();
            $table->string('product_name')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('unit', 16)->nullable();
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->decimal('line_total', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('producer_order_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producer_order_items');
    }
};
