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
        Schema::table('supplier_leads', function (Blueprint $table) {
            $table->foreignId('supermarket_product_id')
                ->nullable()
                ->after('created_by')
                ->constrained('supermarket_products')
                ->nullOnDelete();

            $table->index('supermarket_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supermarket_product_id');
        });
    }
};
