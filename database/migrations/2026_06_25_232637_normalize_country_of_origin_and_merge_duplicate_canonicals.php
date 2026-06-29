<?php

use App\Support\Countries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Coerce every stored origin onto the canonical ISO country code, then merge
     * canonical products that became duplicates once their origins lined up
     * (e.g. one grouped under "MA", another under "Maroc").
     */
    public function up(): void
    {
        $this->normalizeColumn('supplier_products', 'country_of_origin');
        $this->normalizeColumn('supermarket_products', 'origin');
        $this->normalizeColumn('canonical_products', 'country_of_origin');

        $this->mergeDuplicateCanonicals();
    }

    public function down(): void
    {
        // Irreversible data cleanup; codes are a superset-safe representation.
    }

    private function normalizeColumn(string $table, string $column): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $normalized = Countries::normalize($row->{$column});

                    if ($normalized !== $row->{$column}) {
                        DB::table($table)->where('id', $row->id)->update([$column => $normalized]);
                    }
                }
            });
    }

    private function mergeDuplicateCanonicals(): void
    {
        $groups = DB::table('canonical_products')
            ->get()
            ->groupBy(fn (object $product): string => implode('|', [
                Str::lower(trim((string) $product->name)),
                $product->product_category_id,
                Str::lower((string) $product->country_of_origin),
                $product->package_size,
                Str::lower((string) $product->package_unit),
            ]));

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $sorted = $group->sortBy('id')->values();
            $keepId = (int) $sorted->first()->id;
            $duplicateIds = $sorted->slice(1)->pluck('id')->map(fn ($id): int => (int) $id)->all();

            $this->repointPivot('canonical_supplier_product', 'supplier_product_id', $keepId, $duplicateIds);
            $this->repointPivot('canonical_supermarket_product', 'supermarket_product_id', $keepId, $duplicateIds);

            DB::table('canonical_products')->whereIn('id', $duplicateIds)->delete();
        }
    }

    /**
     * @param  array<int, int>  $duplicateIds
     */
    private function repointPivot(string $pivot, string $foreignKey, int $keepId, array $duplicateIds): void
    {
        $alreadyMapped = DB::table($pivot)
            ->where('canonical_product_id', $keepId)
            ->pluck($foreignKey)
            ->all();

        $duplicatePivotRows = DB::table($pivot)
            ->whereIn('canonical_product_id', $duplicateIds)
            ->get();

        foreach ($duplicatePivotRows as $pivotRow) {
            if (in_array($pivotRow->{$foreignKey}, $alreadyMapped)) {
                DB::table($pivot)->where('id', $pivotRow->id)->delete();

                continue;
            }

            DB::table($pivot)->where('id', $pivotRow->id)->update(['canonical_product_id' => $keepId]);
            $alreadyMapped[] = $pivotRow->{$foreignKey};
        }
    }
};
