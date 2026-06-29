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
        Schema::table('supermarket_price_photos', function (Blueprint $table) {
            $table->foreignId('customer_location_id')
                ->nullable()
                ->after('supermarket_id')
                ->constrained('customer_locations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supermarket_price_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_location_id');
        });
    }
};
