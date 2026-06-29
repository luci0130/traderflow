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

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->change();
            $table->string('type')->default('customer')->after('tenant_id')->index();
            $table->string('slug')->nullable()->after('name')->unique();
            $table->string('logo')->nullable()->after('slug');
            $table->boolean('is_active')->default(true)->after('logo')->index();
        });

        Schema::table('supermarket_price_photos', function (Blueprint $table) {
            $table->dropForeign(['supermarket_id']);
        });

        Schema::table('supermarket_prices', function (Blueprint $table) {
            $table->dropForeign(['supermarket_id']);
        });

        DB::table('customers')->update(['type' => 'customer']);

        $supermarketIdMap = [];

        DB::table('supermarkets')
            ->orderBy('id')
            ->get()
            ->each(function (object $supermarket) use (&$supermarketIdMap): void {
                $supermarketIdMap[$supermarket->id] = DB::table('customers')->insertGetId([
                    'tenant_id' => null,
                    'type' => 'supermarket',
                    'name' => $supermarket->name,
                    'slug' => $supermarket->slug,
                    'logo' => $supermarket->logo,
                    'country' => $supermarket->country,
                    'status' => $supermarket->is_active ? 'active' : 'inactive',
                    'is_active' => $supermarket->is_active,
                    'created_at' => $supermarket->created_at,
                    'updated_at' => $supermarket->updated_at,
                ]);
            });

        foreach ($supermarketIdMap as $oldId => $newId) {
            DB::table('supermarket_price_photos')
                ->where('supermarket_id', $oldId)
                ->update(['supermarket_id' => $newId]);

            DB::table('supermarket_prices')
                ->where('supermarket_id', $oldId)
                ->update(['supermarket_id' => $newId]);
        }

        Schema::table('supermarket_price_photos', function (Blueprint $table) {
            $table->foreign('supermarket_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });

        Schema::table('supermarket_prices', function (Blueprint $table) {
            $table->foreign('supermarket_id')
                ->references('id')
                ->on('customers')
                ->cascadeOnDelete();
        });

        Schema::dropIfExists('supermarkets');

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('supermarkets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->string('country', 2)->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('supermarket_price_photos', function (Blueprint $table) {
            $table->dropForeign(['supermarket_id']);
        });

        Schema::table('supermarket_prices', function (Blueprint $table) {
            $table->dropForeign(['supermarket_id']);
        });

        DB::table('customers')
            ->where('type', 'supermarket')
            ->orderBy('id')
            ->get()
            ->each(function (object $supermarket): void {
                DB::table('supermarkets')->insert([
                    'id' => $supermarket->id,
                    'name' => $supermarket->name,
                    'slug' => $supermarket->slug ?: 'supermarket-'.$supermarket->id,
                    'logo' => $supermarket->logo,
                    'country' => $supermarket->country,
                    'is_active' => $supermarket->is_active,
                    'created_at' => $supermarket->created_at,
                    'updated_at' => $supermarket->updated_at,
                ]);
            });

        DB::table('customers')->where('type', 'supermarket')->delete();

        Schema::table('supermarket_price_photos', function (Blueprint $table) {
            $table->foreign('supermarket_id')
                ->references('id')
                ->on('supermarkets')
                ->nullOnDelete();
        });

        Schema::table('supermarket_prices', function (Blueprint $table) {
            $table->foreign('supermarket_id')
                ->references('id')
                ->on('supermarkets')
                ->cascadeOnDelete();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropIndex(['type']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['type', 'slug', 'logo', 'is_active']);
            $table->foreignId('tenant_id')->nullable(false)->change();
        });

        Schema::enableForeignKeyConstraints();
    }
};
