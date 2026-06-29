<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers and supermarkets are no longer distinguished by `type`.
     * Globally shared records (supermarkets) are identified by a null tenant_id.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('type')->default('customer')->after('tenant_id')->index();
        });
    }
};
