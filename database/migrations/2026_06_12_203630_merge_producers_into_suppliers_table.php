<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
            $table->string('management_mode')->default('operator_managed')->after('tenant_id')->index();
            $table->boolean('is_producer')->default(false)->after('management_mode')->index();
            $table->unsignedBigInteger('merged_producer_id')->nullable()->after('is_producer')->unique();
            $table->string('registration_number')->nullable()->after('vat_number');
            $table->string('postal_code')->nullable()->after('city');
            $table->string('iban')->nullable()->after('payment_terms');
            $table->string('bank_name')->nullable()->after('iban');
            $table->string('bank_swift')->nullable()->after('bank_name');
            $table->string('default_currency', 3)->default('EUR')->after('bank_swift');
            $table->string('invoice_prefix')->nullable()->after('default_currency');
            $table->unsignedInteger('invoice_starting_number')->default(1)->after('invoice_prefix');
            $table->text('invoice_notes')->nullable()->after('invoice_starting_number');
            $table->string('logo_path')->nullable()->after('invoice_notes');
        });

        DB::table('suppliers')->update([
            'management_mode' => 'operator_managed',
            'is_producer' => false,
        ]);

        DB::table('suppliers')
            ->whereNotNull('contact_person')
            ->orderBy('id')
            ->get()
            ->each(function (object $supplier): void {
                DB::table('supplier_contacts')->insert([
                    'supplier_id' => $supplier->id,
                    'user_id' => null,
                    'name' => $supplier->contact_person,
                    'role_in_company' => null,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'is_primary' => true,
                    'can_access_portal' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('producers')
            ->orderBy('id')
            ->get()
            ->each(function (object $producer): void {
                DB::table('suppliers')->insert([
                    'tenant_id' => null,
                    'management_mode' => 'self_managed',
                    'is_producer' => true,
                    'merged_producer_id' => $producer->id,
                    'name' => $producer->name,
                    'legal_name' => $producer->legal_name,
                    'vat_number' => $producer->vat_number,
                    'registration_number' => $producer->registration_number,
                    'email' => $producer->email,
                    'phone' => $producer->phone,
                    'country' => $producer->country,
                    'city' => $producer->city,
                    'postal_code' => $producer->postal_code,
                    'address' => $producer->address,
                    'status' => $producer->status,
                    'notes' => $producer->notes,
                    'iban' => $producer->iban,
                    'bank_name' => $producer->bank_name,
                    'bank_swift' => $producer->bank_swift,
                    'default_currency' => $producer->default_currency,
                    'invoice_prefix' => $producer->invoice_prefix,
                    'invoice_starting_number' => $producer->invoice_starting_number,
                    'invoice_notes' => $producer->invoice_notes,
                    'logo_path' => $producer->logo_path,
                    'created_at' => $producer->created_at,
                    'updated_at' => $producer->updated_at,
                ]);
            });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        DB::table('supplier_contacts')
            ->whereIn('supplier_id', fn ($query) => $query
                ->select('id')
                ->from('suppliers')
                ->whereNotNull('merged_producer_id'))
            ->delete();

        DB::table('suppliers')
            ->whereNotNull('merged_producer_id')
            ->delete();

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique(['merged_producer_id']);
            $table->dropIndex(['management_mode']);
            $table->dropIndex(['is_producer']);
            $table->dropColumn([
                'management_mode',
                'is_producer',
                'merged_producer_id',
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
            $table->foreignId('tenant_id')->nullable(false)->change();
        });

        Schema::enableForeignKeyConstraints();
    }
};
