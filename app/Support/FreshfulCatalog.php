<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Parses product name/photo pairs out of a Freshful listing page and matches
 * them to a category by (diacritic-insensitive) Romanian name. Freshful renders
 * each product card server-side as `<img alt="{name}" src="{cdn thumbnail}">`,
 * so no private API is needed — the reachable public HTML is enough.
 */
class FreshfulCatalog
{
    /**
     * Extract every product picture from a listing page's HTML.
     *
     * @return Collection<int, array{name: string, image: string}>
     */
    public function parseProducts(string $html): Collection
    {
        preg_match_all(
            '/<img\s+alt="([^"]+)"[^>]*\ssrc="(https:\/\/cdn\.freshful\.ro\/media\/cache\/[^"]+)"/i',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        return collect($matches)
            ->map(fn (array $m): array => [
                'name' => html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5),
                'image' => $m[2],
            ])
            ->unique('image')
            ->values();
    }

    /**
     * Pick the best product photo for a category name. Requires the category
     * name to appear as a whole phrase in the product name, preferring a product
     * that starts with it and, among those, the shortest (most generic) name.
     *
     * @param  Collection<int, array{name: string, image: string}>  $products
     */
    public function bestImageFor(string $categoryName, Collection $products): ?string
    {
        $needle = $this->normalize($categoryName);

        if ($needle === '') {
            return null;
        }

        $pattern = '/\b'.preg_quote($needle, '/').'\b/';

        return $products
            ->filter(fn (array $p): bool => (bool) preg_match($pattern, $this->normalize($p['name'])))
            ->sortBy(fn (array $p): array => [
                Str::startsWith($this->normalize($p['name']), $needle) ? 0 : 1,
                mb_strlen($p['name']),
            ])
            ->first()['image'] ?? null;
    }

    /**
     * Lower-cased, diacritic-folded, whitespace-collapsed form for matching.
     */
    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(Str::ascii($value))));
    }
}
