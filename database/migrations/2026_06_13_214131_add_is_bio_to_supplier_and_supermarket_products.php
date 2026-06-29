<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flag products (supplier and supermarket) as organic ("bio").
     */
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->boolean('is_bio')->default(false)->after('status');
        });

        Schema::table('supermarket_products', function (Blueprint $table) {
            $table->boolean('is_bio')->default(false)->after('quality');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropColumn('is_bio');
        });

        Schema::table('supermarket_products', function (Blueprint $table) {
            $table->dropColumn('is_bio');
        });
    }
};
