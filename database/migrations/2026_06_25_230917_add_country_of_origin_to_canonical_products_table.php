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
        Schema::table('canonical_products', function (Blueprint $table) {
            $table->string('country_of_origin')->nullable()->after('variety');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('canonical_products', function (Blueprint $table) {
            $table->dropColumn('country_of_origin');
        });
    }
};
