<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products and units become a single shared catalog across all tenants,
     * so tenant_id becomes nullable and global catalog rows store null.
     * Mirrors make_product_categories_global.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
        });
    }
};
