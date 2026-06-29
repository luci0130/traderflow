<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical products now reuse the single global product_categories taxonomy
     * instead of a separate canonical_categories table.
     */
    public function up(): void
    {
        Schema::table('canonical_products', function (Blueprint $table) {
            $table->foreignId('product_category_id')->nullable()->after('id')->constrained('product_categories')->nullOnDelete();
        });

        Schema::table('canonical_products', function (Blueprint $table) {
            $table->dropIndex(['canonical_category_id', 'name']);
            $table->dropConstrainedForeignId('canonical_category_id');
            $table->index(['product_category_id', 'name']);
        });

        Schema::dropIfExists('canonical_categories');
    }

    public function down(): void
    {
        Schema::create('canonical_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('canonical_categories')->nullOnDelete();
            $table->timestamps();

            $table->index(['parent_id', 'name']);
        });

        Schema::table('canonical_products', function (Blueprint $table) {
            $table->dropIndex(['product_category_id', 'name']);
            $table->dropConstrainedForeignId('product_category_id');
            $table->foreignId('canonical_category_id')->nullable()->after('id')->constrained('canonical_categories')->nullOnDelete();
            $table->index(['canonical_category_id', 'name']);
        });
    }
};
