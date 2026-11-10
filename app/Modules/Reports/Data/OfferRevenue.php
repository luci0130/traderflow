<?php

namespace App\Modules\Reports\Data;

final readonly class OfferRevenue
{
    public function __construct(
        public ?float $salesTotal,
        public ?float $productCost,
        public ?float $transportCost,
        public bool $usesEstimatedQuantities,
        public bool $hasBundledCosts,
        public bool $isComplete,
    ) {}

    public function margin(): ?float
    {
        return $this->salesTotal !== null && $this->productCost !== null
            ? round($this->salesTotal - $this->productCost, 4) : null;
    }

    public function profit(): ?float
    {
        $margin = $this->margin();

        return $this->isComplete && $margin !== null && $this->transportCost !== null
            ? round($margin - $this->transportCost, 4) : null;
    }
}
