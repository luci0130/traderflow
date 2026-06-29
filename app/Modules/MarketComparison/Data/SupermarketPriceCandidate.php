<?php

namespace App\Modules\MarketComparison\Data;

use App\Modules\Supermarkets\Models\SupermarketPrice;

/**
 * One observed supermarket price for a canonical product.
 */
class SupermarketPriceCandidate
{
    public function __construct(
        public SupermarketPrice $price,
        public ?string $supermarketName,
        public float $grossPrice,
        public ?float $priceExclVat,
        public string $currency,
        public ?string $observedAt,
        public bool $isPromo,
        public ?float $promoPrice,
    ) {}
}
