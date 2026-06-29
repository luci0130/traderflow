<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transporters get a basic location (city/county/country) so they can be
     * filtered by city, consistent with suppliers and customers.
     */
    public function up(): void
    {
        Schema::table('transporters', function (Blueprint $table) {
            $table->string('country')->nullable()->after('email');
            $table->string('county')->nullable()->after('country');
            $table->string('city')->nullable()->after('county');

            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::table('transporters', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropColumn(['country', 'county', 'city']);
        });
    }
};
