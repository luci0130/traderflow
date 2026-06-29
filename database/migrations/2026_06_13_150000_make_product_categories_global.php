<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product categories are a single shared taxonomy across all tenants, so
     * tenant_id becomes nullable and global categories store null.
     */
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
        });
    }
};
