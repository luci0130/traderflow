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
        Schema::create('currency_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            $table->date('rate_date')->index();
            $table->string('source', 30);
            $table->unique(['base_currency', 'quote_currency', 'rate_date']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currency_exchange_rates');
    }
};
