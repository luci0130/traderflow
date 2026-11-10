<?php

namespace App\Modules\CustomerOffers\Enums;

enum CustomerOfferPdfTemplate: string
{
    case SimpleEn = 'simple_en';
    case SimpleRo = 'simple_ro';
    case Complex = 'complex';

    public function label(): string
    {
        return match ($this) {
            self::SimpleEn => 'EN - Simple Offer',
            self::SimpleRo => 'RO - Simple Offer',
            self::Complex => 'Complex Offer',
        };
    }

    public function view(): string
    {
        return match ($this) {
            self::Complex => 'pdf.customer-offer',
            self::SimpleEn, self::SimpleRo => 'pdf.customer-offer-simple',
        };
    }

    public function locale(): string
    {
        return $this === self::SimpleEn ? 'en' : 'ro';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $template): array => [$template->value => $template->label()])->all();
    }
}
