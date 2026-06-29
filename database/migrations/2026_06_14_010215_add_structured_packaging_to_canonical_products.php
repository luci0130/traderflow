<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical packaging becomes structured (method + size + unit) so a variant
     * like "Plasă 4 kg" can be captured. `packaging_variant` is kept as a denormalized
     * label, auto-composed from these fields by the model on save.
     */
    public function up(): void
    {
        Schema::table('canonical_products', function (Blueprint $table) {
            $table->foreignId('packaging_method_id')->nullable()->after('caliber')->constrained('packaging_methods')->nullOnDelete();
            $table->decimal('package_size', 15, 4)->nullable()->after('packaging_method_id');
            $table->string('package_unit', 16)->nullable()->after('package_size');
        });

        // Backfill structured fields from the existing free-text variant label.
        foreach (DB::table('canonical_products')->whereNotNull('packaging_variant')->get() as $row) {
            if (preg_match('/([\d.,]+)\s*([\p{L}]+)/u', (string) $row->packaging_variant, $matches) === 1) {
                $size = (float) str_replace(',', '.', $matches[1]);
                $unit = $matches[2];
            } else {
                $size = null;
                $unit = trim((string) $row->packaging_variant) ?: null;
            }

            DB::table('canonical_products')->where('id', $row->id)->update([
                'package_size' => $size,
                'package_unit' => $unit,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('canonical_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('packaging_method_id');
            $table->dropColumn(['package_size', 'package_unit']);
        });
    }
};
