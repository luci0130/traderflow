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
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->foreignId('packaging_method_id')
                ->nullable()
                ->after('default_packaging')
                ->constrained('packaging_methods')
                ->nullOnDelete();
        });

        Schema::table('supermarket_products', function (Blueprint $table) {
            $table->foreignId('packaging_method_id')
                ->nullable()
                ->after('barcode')
                ->constrained('packaging_methods')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supermarket_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('packaging_method_id');
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('packaging_method_id');
        });
    }
};
