<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A location can be its own legal/billing entity (distinct name, fiscal code,
     * bank account) when it invoices separately from the owning customer.
     */
    public function up(): void
    {
        Schema::table('customer_locations', function (Blueprint $table) {
            $table->boolean('is_separate_legal_entity')->default(false)->after('type');
            $table->string('legal_name')->nullable()->after('is_separate_legal_entity');
            $table->string('fiscal_code')->nullable()->after('legal_name');
            $table->string('bank_name')->nullable()->after('fiscal_code');
            $table->string('bank_account')->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('customer_locations', function (Blueprint $table) {
            $table->dropColumn([
                'is_separate_legal_entity',
                'legal_name',
                'fiscal_code',
                'bank_name',
                'bank_account',
            ]);
        });
    }
};
