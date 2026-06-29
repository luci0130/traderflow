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
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producer_id')->constrained()->cascadeOnDelete();
            $table->string('image_path')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('variety')->nullable();
            $table->string('country_of_origin', 2)->nullable();
            $table->string('caliber')->nullable();
            $table->string('type')->nullable();
            $table->string('category')->nullable();
            $table->string('default_packaging')->nullable();
            $table->decimal('min_quantity_value', 15, 4)->nullable();
            $table->string('min_quantity_unit', 16)->default('kg');
            $table->decimal('unit_price', 15, 4)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->date('valid_until')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['producer_id', 'status']);
            $table->index('valid_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
