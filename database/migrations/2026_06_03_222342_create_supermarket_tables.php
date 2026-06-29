<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Supermarket price data is global reference data, shared across all tenants,
     * so none of these tables carry a `tenant_id`.
     */
    public function up(): void
    {
        Schema::create('supermarkets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->string('country', 2)->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('supermarket_products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('category')->nullable();
            $table->string('barcode')->nullable()->index();
            $table->decimal('package_size', 15, 4)->nullable();
            $table->string('package_unit', 16)->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('supermarket_price_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supermarket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path');
            $table->string('store_label')->nullable();
            $table->date('taken_at')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('supermarket_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supermarket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supermarket_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supermarket_price_photo_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('price', 15, 4);
            $table->string('currency', 3)->default('RON');
            $table->boolean('is_promo')->default(false);
            $table->decimal('promo_price', 15, 4)->nullable();
            $table->date('observed_at');
            $table->string('source')->default('photo');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['supermarket_id', 'supermarket_product_id', 'observed_at']);
            $table->index('source');
            $table->index('observed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supermarket_prices');
        Schema::dropIfExists('supermarket_price_photos');
        Schema::dropIfExists('supermarket_products');
        Schema::dropIfExists('supermarkets');
    }
};
