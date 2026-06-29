<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured packaging size (e.g. 10 of unit "kg" per crate) alongside the
     * existing packaging method and unit.
     */
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->decimal('package_size', 15, 4)->nullable()->after('packaging_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropColumn('package_size');
        });
    }
};
