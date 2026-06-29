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
        Schema::table('producers', function (Blueprint $table) {
            $table->string('registration_number')->nullable()->after('vat_number');
            $table->string('postal_code', 16)->nullable()->after('city');
            $table->string('iban', 34)->nullable()->after('address');
            $table->string('bank_name')->nullable()->after('iban');
            $table->string('bank_swift', 11)->nullable()->after('bank_name');
            $table->string('default_currency', 3)->default('EUR')->after('bank_swift');
            $table->string('invoice_prefix', 16)->nullable()->after('default_currency');
            $table->unsignedInteger('invoice_starting_number')->default(1)->after('invoice_prefix');
            $table->text('invoice_notes')->nullable()->after('invoice_starting_number');
            $table->string('logo_path')->nullable()->after('invoice_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('producers', function (Blueprint $table) {
            $table->dropColumn([
                'registration_number',
                'postal_code',
                'iban',
                'bank_name',
                'bank_swift',
                'default_currency',
                'invoice_prefix',
                'invoice_starting_number',
                'invoice_notes',
                'logo_path',
            ]);
        });
    }
};
