<?php

namespace App\View\Components;

use App\Support\PriceFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Price extends Component
{
    public function __construct(
        public float|int|string|null $amount,
        public string $currency,
        public bool $showOriginal = true,
    ) {}

    public function render(): View
    {
        return view('components.price', [
            'formatted' => app(PriceFormatter::class)->format($this->amount, $this->currency, showOriginal: $this->showOriginal),
        ]);
    }
}
