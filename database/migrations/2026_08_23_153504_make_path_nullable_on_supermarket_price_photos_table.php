<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stored file is purged once a photo has been reviewed, so the row keeps
     * the audit trail (and the prices link back to it) without the path.
     */
    public function up(): void
    {
        Schema::table('supermarket_price_photos', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supermarket_price_photos', function (Blueprint $table) {
            $table->string('path')->nullable(false)->change();
        });
    }
};
