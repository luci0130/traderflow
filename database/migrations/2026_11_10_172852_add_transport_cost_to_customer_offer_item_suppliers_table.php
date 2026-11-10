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
        Schema::table('customer_offer_item_suppliers', function (Blueprint $table): void {
            $table->decimal('transport_cost', 15, 4)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_offer_item_suppliers', function (Blueprint $table): void {
            $table->dropColumn('transport_cost');
        });
    }
};
