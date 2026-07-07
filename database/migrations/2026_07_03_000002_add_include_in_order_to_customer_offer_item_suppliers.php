<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customer_offer_item_suppliers', 'include_in_order')) {
            return;
        }

        Schema::table('customer_offer_item_suppliers', function (Blueprint $table) {
            // Whether the seller includes ordering this product from this supplier.
            $table->boolean('include_in_order')->default(true)->after('secured_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('customer_offer_item_suppliers', function (Blueprint $table) {
            $table->dropColumn('include_in_order');
        });
    }
};
