<?php

namespace App\Support;

use App\Modules\ProductCategories\Models\ProductCategory;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a product category *name* to its stored image, so products that have
 * no picture of their own can fall back to their category's. Supplier and
 * supermarket products keep their category as a free-text name that matches the
 * ProductCategory taxonomy ("Roșii", "Cartofi", …). The name→path map is built
 * once per instance; bind this as a singleton so a whole table render costs a
 * single query.
 */
class CategoryImages
{
    /**
     * @var array<string, string>|null
     */
    private ?array $map = null;

    public function pathFor(?string $categoryName): ?string
    {
        if (blank($categoryName)) {
            return null;
        }

        $this->map ??= ProductCategory::query()
            ->whereNotNull('image_path')
            // Global (tenant_id null) categories are ordered last so, on a name
            // clash, they overwrite tenant copies and win.
            ->orderByRaw('tenant_id is null')
            ->pluck('image_path', 'name')
            ->mapWithKeys(fn (string $path, string $name): array => [$this->key($name) => $path])
            ->all();

        return $this->map[$this->key($categoryName)] ?? null;
    }

    public function urlFor(?string $categoryName): ?string
    {
        $path = $this->pathFor($categoryName);

        return filled($path) ? Storage::disk('public')->url($path) : null;
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
