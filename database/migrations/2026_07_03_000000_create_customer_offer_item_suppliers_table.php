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
        // The buy/sourcing side of a customer offer line: the prioritized suppliers
        // the purchasing agent contacts, with their price snapshot and the landed
        // cost + secured quantity the agent fills in. Scoped through its offer line
        // (already tenant-bound), so it carries no tenant_id.
        Schema::create('customer_offer_item_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_offer_item_id')->constrained('customer_offer_items')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained('supplier_products')->nullOnDelete();
            $table->unsignedTinyInteger('priority');
            $table->decimal('unit_price', 15, 4)->nullable();
            $table->decimal('landed_cost', 15, 4)->nullable();
            $table->string('currency')->default('EUR');
            $table->decimal('quantity_available', 15, 4)->nullable();
            $table->string('status')->default('pending');
            $table->decimal('secured_quantity', 15, 4)->nullable();
            $table->timestamps();

            $table->unique(['customer_offer_item_id', 'priority']);
            $table->index('supplier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_offer_item_suppliers');
    }
};
