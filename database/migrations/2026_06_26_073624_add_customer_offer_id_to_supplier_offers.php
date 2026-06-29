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
        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->foreignId('customer_offer_id')
                ->nullable()
                ->after('supplier_id')
                ->constrained('customer_offers')
                ->nullOnDelete();
            $table->index(['tenant_id', 'customer_offer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'customer_offer_id']);
            $table->dropConstrainedForeignId('customer_offer_id');
        });
    }
};
