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
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key');
            $table->string('prefix')->nullable();
            $table->string('suffix')->nullable();
            $table->unsignedSmallInteger('padding')->default(5);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedInteger('step')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        // Seed the default set of sequences for every existing tenant so they show
        // up in settings immediately.
        $types = config('number_sequences.types', []);
        $now = now();

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ($types as $key => $config) {
                DB::table('number_sequences')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'key' => $key,
                    'prefix' => $config['prefix'] ?? '',
                    'suffix' => null,
                    'padding' => $config['padding'] ?? 5,
                    'next_number' => 1,
                    'step' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
