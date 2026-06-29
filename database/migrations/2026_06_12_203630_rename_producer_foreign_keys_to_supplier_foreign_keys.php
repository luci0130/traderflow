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

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['producer_id']);
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropForeign(['producer_id']);
        });

        Schema::table('producer_orders', function (Blueprint $table) {
            $table->dropForeign(['producer_id']);
        });

        DB::table('users')
            ->whereNotNull('producer_id')
            ->update([
                'producer_id' => DB::raw('(select id from suppliers where suppliers.merged_producer_id = users.producer_id)'),
            ]);

        DB::table('supplier_products')
            ->whereNotNull('producer_id')
            ->update([
                'producer_id' => DB::raw('(select id from suppliers where suppliers.merged_producer_id = supplier_products.producer_id)'),
            ]);

        DB::table('producer_orders')
            ->whereNotNull('producer_id')
            ->update([
                'producer_id' => DB::raw('(select id from suppliers where suppliers.merged_producer_id = producer_orders.producer_id)'),
            ]);

        DB::table('users')
            ->whereNotNull('producer_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $user): void {
                DB::table('supplier_contacts')->updateOrInsert(
                    [
                        'supplier_id' => $user->producer_id,
                        'user_id' => $user->id,
                    ],
                    [
                        'name' => $user->name,
                        'role_in_company' => 'Account administrator',
                        'email' => $user->email,
                        'phone' => $user->phone ?? null,
                        'is_primary' => true,
                        'can_access_portal' => true,
                        'created_at' => $user->created_at,
                        'updated_at' => now(),
                    ],
                );
            });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('producer_id')
                ->references('id')
                ->on('suppliers')
                ->nullOnDelete();
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->foreign('producer_id')
                ->references('id')
                ->on('suppliers')
                ->cascadeOnDelete();
        });

        Schema::table('producer_orders', function (Blueprint $table) {
            $table->foreign('producer_id')
                ->references('id')
                ->on('suppliers')
                ->cascadeOnDelete();
        });

        Schema::dropIfExists('producers');

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('producers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->string('registration_number')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('iban')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_swift')->nullable();
            $table->string('default_currency', 3)->default('EUR');
            $table->string('invoice_prefix')->nullable();
            $table->unsignedInteger('invoice_starting_number')->default(1);
            $table->text('invoice_notes')->nullable();
            $table->string('logo_path')->nullable();
        });

        DB::table('suppliers')
            ->whereNotNull('merged_producer_id')
            ->orderBy('merged_producer_id')
            ->get()
            ->each(function (object $supplier): void {
                DB::table('producers')->insert([
                    'id' => $supplier->merged_producer_id,
                    'name' => $supplier->name,
                    'legal_name' => $supplier->legal_name,
                    'vat_number' => $supplier->vat_number,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'country' => $supplier->country,
                    'city' => $supplier->city,
                    'address' => $supplier->address,
                    'status' => $supplier->status,
                    'notes' => $supplier->notes,
                    'registration_number' => $supplier->registration_number,
                    'postal_code' => $supplier->postal_code,
                    'iban' => $supplier->iban,
                    'bank_name' => $supplier->bank_name,
                    'bank_swift' => $supplier->bank_swift,
                    'default_currency' => $supplier->default_currency,
                    'invoice_prefix' => $supplier->invoice_prefix,
                    'invoice_starting_number' => $supplier->invoice_starting_number,
                    'invoice_notes' => $supplier->invoice_notes,
                    'logo_path' => $supplier->logo_path,
                    'created_at' => $supplier->created_at,
                    'updated_at' => $supplier->updated_at,
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['producer_id']);
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropForeign(['producer_id']);
        });

        Schema::table('producer_orders', function (Blueprint $table) {
            $table->dropForeign(['producer_id']);
        });

        DB::table('users')
            ->whereNotNull('producer_id')
            ->update([
                'producer_id' => DB::raw('(select merged_producer_id from suppliers where suppliers.id = users.producer_id)'),
            ]);

        DB::table('supplier_products')
            ->whereNotNull('producer_id')
            ->update([
                'producer_id' => DB::raw('(select merged_producer_id from suppliers where suppliers.id = supplier_products.producer_id)'),
            ]);

        DB::table('producer_orders')
            ->whereNotNull('producer_id')
            ->update([
                'producer_id' => DB::raw('(select merged_producer_id from suppliers where suppliers.id = producer_orders.producer_id)'),
            ]);

        DB::table('supplier_contacts')
            ->whereNotNull('user_id')
            ->where('can_access_portal', true)
            ->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('producer_id')
                ->references('id')
                ->on('producers')
                ->nullOnDelete();
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->foreign('producer_id')
                ->references('id')
                ->on('producers')
                ->cascadeOnDelete();
        });

        Schema::table('producer_orders', function (Blueprint $table) {
            $table->foreign('producer_id')
                ->references('id')
                ->on('producers')
                ->cascadeOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }
};
