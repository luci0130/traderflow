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
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->string('quality')->nullable()->after('caliber');
        });

        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->string('type')->nullable()->after('caliber');
        });

        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->dropColumn('quality');
        });
    }
};
