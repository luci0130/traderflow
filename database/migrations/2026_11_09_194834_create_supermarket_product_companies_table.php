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
        Schema::create('supermarket_product_companies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supermarket_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['supplier_lead_id', 'supermarket_product_id'], 'supermarket_company_lead_product_unique');
            $table->unique(['supplier_id', 'supermarket_product_id'], 'supermarket_company_supplier_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supermarket_product_companies');
    }
};
