<?php

namespace App\Modules\MarketComparison\Services;

use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

/**
 * Suggests canonical products that likely match a supplier or supermarket
 * product, using cheap deterministic signals: exact/partial name matches,
 * variety matches and (for supermarket products) barcodes already mapped
 * to a canonical product.
 */
class CanonicalMatchSuggester
{
    private const SCORE_BARCODE_MATCH = 10;

    private const SCORE_EXACT_NAME = 5;

    private const SCORE_NAME_TOKEN = 2;

    private const SCORE_VARIETY = 1;

    /**
     * @return EloquentCollection<int, CanonicalProduct>
     */
    public function suggestForSupplierProduct(SupplierProduct $product, int $limit = 5): EloquentCollection
    {
        return $this->rankCandidates(
            name: (string) $product->name,
            variety: $product->variety,
            barcode: null,
            limit: $limit,
        );
    }

    /**
     * @return EloquentCollection<int, CanonicalProduct>
     */
    public function suggestForSupermarketProduct(SupermarketProduct $product, int $limit = 5): EloquentCollection
    {
        return $this->rankCandidates(
            name: (string) $product->name,
            variety: null,
            barcode: $product->barcode,
            limit: $limit,
        );
    }

    /**
     * @return EloquentCollection<int, CanonicalProduct>
     */
    private function rankCandidates(string $name, ?string $variety, ?string $barcode, int $limit): EloquentCollection
    {
        $tokens = $this->tokenize($name);

        if ($tokens === [] && blank($variety) && blank($barcode)) {
            return new EloquentCollection;
        }

        $candidates = CanonicalProduct::query()
            ->where(function (Builder $query) use ($tokens, $variety, $barcode): void {
                foreach ($tokens as $token) {
                    $query
                        ->orWhere('name', 'like', "%{$token}%")
                        ->orWhere('variety', 'like', "%{$token}%");
                }

                if (filled($variety)) {
                    $query->orWhere('variety', 'like', "%{$variety}%");
                }

                if (filled($barcode)) {
                    $query->orWhereHas(
                        'supermarketProducts',
                        fn (Builder $query): Builder => $query->where('barcode', $barcode),
                    );
                }
            })
            ->when(filled($barcode), fn (Builder $query): Builder => $query->with('supermarketProducts'))
            ->get();

        return $candidates
            ->sortByDesc(fn (CanonicalProduct $candidate): int => $this->score($candidate, $name, $tokens, $variety, $barcode))
            ->take($limit)
            ->values();
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function score(CanonicalProduct $candidate, string $name, array $tokens, ?string $variety, ?string $barcode): int
    {
        $score = 0;
        $candidateName = Str::lower($candidate->name);

        if (filled($barcode) && $candidate->supermarketProducts->contains('barcode', $barcode)) {
            $score += self::SCORE_BARCODE_MATCH;
        }

        if ($candidateName === Str::lower($name)) {
            $score += self::SCORE_EXACT_NAME;
        }

        foreach ($tokens as $token) {
            if (str_contains($candidateName, $token)) {
                $score += self::SCORE_NAME_TOKEN;
            }
        }

        if (filled($variety) && filled($candidate->variety) && str_contains(Str::lower($candidate->variety), Str::lower($variety))) {
            $score += self::SCORE_VARIETY;
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $name): array
    {
        return collect(preg_split('/[^\pL\pN]+/u', Str::lower($name)) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()
            ->values()
            ->all();
    }
}
