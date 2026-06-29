<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Transporters and their indicative routes (e.g. Spain -> Turda) are used to
     * estimate transport costs before making an offer. Global reference data,
     * no `tenant_id`.
     */
    public function up(): void
    {
        Schema::create('transporters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('cost_per_km', 15, 4)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transporter_id')->constrained()->cascadeOnDelete();
            $table->string('origin');
            $table->string('destination');
            $table->decimal('distance_km', 15, 4)->nullable();
            $table->decimal('estimated_cost', 15, 4)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->timestamps();

            $table->index(['origin', 'destination']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_routes');
        Schema::dropIfExists('transporters');
    }
};
