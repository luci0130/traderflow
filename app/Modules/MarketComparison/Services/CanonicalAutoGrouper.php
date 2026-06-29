<?php

namespace App\Modules\MarketComparison\Services;

use App\Modules\MarketComparison\Models\CanonicalProduct;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use App\Support\Countries;
use Illuminate\Support\Str;

/**
 * Automatically groups unmapped supplier and supermarket products into canonical
 * products, keyed on (normalized name + category + country of origin + packaging).
 * Products already mapped to a canonical are left untouched, so the run is safe and
 * repeatable — a human can still split or merge afterwards.
 */
class CanonicalAutoGrouper
{
    /**
     * @var array<string, CanonicalProduct>
     */
    private array $cache = [];

    /**
     * @var array<string, ?int>
     */
    private array $categoryIdCache = [];

    /**
     * @return array{canonicals_created: int, supplier_mapped: int, supermarket_mapped: int}
     */
    public function group(): array
    {
        $this->cache = [];
        $createdBefore = CanonicalProduct::query()->count();
        $supplierMapped = 0;
        $supermarketMapped = 0;

        SupplierProduct::query()
            ->whereDoesntHave('canonicalProducts')
            ->orderBy('id')
            ->each(function (SupplierProduct $product) use (&$supplierMapped): void {
                [$size, $unit] = $this->supplierPackaging($product);
                $canonical = $this->resolveCanonical(
                    name: (string) $product->name,
                    categoryName: (string) $product->category,
                    countryOfOrigin: $product->country_of_origin,
                    size: $size,
                    unit: $unit,
                    variety: $product->variety,
                    caliber: $product->caliber,
                );

                $canonical->supplierProducts()->attach($product->getKey());
                $supplierMapped++;
            });

        SupermarketProduct::query()
            ->whereDoesntHave('canonicalProducts')
            ->orderBy('id')
            ->each(function (SupermarketProduct $product) use (&$supermarketMapped): void {
                [$size, $unit] = $this->supermarketPackaging($product);
                $canonical = $this->resolveCanonical(
                    name: (string) $product->name,
                    categoryName: (string) $product->category,
                    countryOfOrigin: $product->origin,
                    size: $size,
                    unit: $unit,
                    variety: null,
                    caliber: $product->caliber,
                );

                $canonical->supermarketProducts()->attach($product->getKey());
                $supermarketMapped++;
            });

        return [
            'canonicals_created' => CanonicalProduct::query()->count() - $createdBefore,
            'supplier_mapped' => $supplierMapped,
            'supermarket_mapped' => $supermarketMapped,
        ];
    }

    private function resolveCanonical(string $name, string $categoryName, ?string $countryOfOrigin, ?float $size, ?string $unit, ?string $variety, ?string $caliber): CanonicalProduct
    {
        $categoryId = $this->categoryId($categoryName);
        $country = $this->normalizeCountry($countryOfOrigin);
        $key = Str::lower(trim($name)).'|'.$categoryId.'|'.Str::lower((string) $country).'|'.$size.'|'.Str::lower((string) $unit);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $canonical = CanonicalProduct::firstOrCreate(
            [
                'name' => $name,
                'product_category_id' => $categoryId,
                'country_of_origin' => $country,
                'package_size' => $size,
                'package_unit' => $unit,
            ],
            [
                'variety' => $variety,
                'caliber' => $caliber,
                'notes' => __('Auto-grouped by name + category + country of origin + packaging.'),
            ],
        );

        return $this->cache[$key] = $canonical;
    }

    private function normalizeCountry(?string $country): ?string
    {
        return Countries::normalize($country);
    }

    private function categoryId(string $categoryName): ?int
    {
        $categoryName = trim($categoryName);

        if ($categoryName === '') {
            return null;
        }

        return $this->categoryIdCache[$categoryName] ??= ProductCategory::query()
            ->where('name', $categoryName)
            ->value('id');
    }

    /**
     * @return array{0: float|null, 1: string|null}
     */
    private function supplierPackaging(SupplierProduct $product): array
    {
        [$size, $unit] = $this->parsePackage((string) $product->default_packaging);

        if ($unit === null) {
            return [null, $this->normalizeUnit((string) $product->min_quantity_unit)];
        }

        return [$size, $unit];
    }

    /**
     * @return array{0: float|null, 1: string|null}
     */
    private function supermarketPackaging(SupermarketProduct $product): array
    {
        $size = $product->package_size !== null ? (float) $product->package_size : null;

        return [$size > 0 ? $size : null, $this->normalizeUnit((string) $product->package_unit)];
    }

    private function normalizeUnit(string $unit): ?string
    {
        $unit = Str::lower(trim($unit));

        return $unit !== '' ? $unit : null;
    }

    /**
     * @return array{0: float|null, 1: string|null}
     */
    private function parsePackage(string $value): array
    {
        if (preg_match('/([\d.,]+)\s*(kg|g|ml|l|buc)\b/u', Str::lower($value), $matches) === 1) {
            return [(float) str_replace(',', '.', $matches[1]), $matches[2]];
        }

        return [null, null];
    }
}
