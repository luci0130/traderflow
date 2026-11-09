<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('supplier_leads')->whereNotNull('supermarket_product_id')
            ->orderBy('id')->chunkById(500, function (Collection $leads): void {
                foreach ($leads as $lead) {
                    DB::table('supermarket_product_companies')->insertOrIgnore([
                        'supplier_lead_id' => $lead->id,
                        'supplier_id' => $lead->converted_supplier_id,
                        'supermarket_product_id' => $lead->supermarket_product_id,
                        'created_at' => $lead->created_at,
                        'updated_at' => $lead->updated_at,
                    ]);
                }
            });
    }

    /**
     * Links are retained until the schema migration removes their table.
     */
    public function down(): void {}
};
