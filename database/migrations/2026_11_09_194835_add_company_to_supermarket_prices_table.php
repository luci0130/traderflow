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
        Schema::table('supermarket_prices', function (Blueprint $table): void {
            $table->foreignId('supplier_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['supplier_lead_id', 'observed_at']);
            $table->index(['supplier_id', 'observed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supermarket_prices', function (Blueprint $table): void {
            $table->dropIndex(['supplier_lead_id', 'observed_at']);
            $table->dropIndex(['supplier_id', 'observed_at']);
            $table->dropConstrainedForeignId('supplier_lead_id');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
