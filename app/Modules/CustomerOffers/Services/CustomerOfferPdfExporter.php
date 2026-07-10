<?php

namespace App\Modules\CustomerOffers\Services;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\TenantSettings\Models\TenantSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a customer offer into the branded "Ofertă" PDF. Every value is sourced
 * from the offer being exported: the merchant block from its tenant, the buyer
 * block from its customer and the products table from its items. The visual
 * chrome (banner, feature icons, QR) is generated in the Blade template.
 */
class CustomerOfferPdfExporter
{
    /** Brand red used throughout the document. */
    public const RED = '#C1272D';

    /** Tenant setting key holding the merchant's bank accounts (JSON array of {bank, iban}). */
    public const BANK_ACCOUNTS_SETTING = 'offer_bank_accounts';

    /** Folder (under public/) where drop-in offer images live. */
    public const ASSET_DIR = 'images/offer-pdf';

    /** Image extensions accepted for drop-in assets, in match priority order. */
    private const ASSET_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Write the offer PDF to a temporary file and return its path. The caller is
     * responsible for streaming and deleting it.
     */
    public function export(CustomerOffer $offer): string
    {
        $html = View::make('pdf.customer-offer', $this->buildViewData($offer))->render();

        $tempDir = storage_path('app/mpdf');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'default_font' => 'dejavusans',
            'tempDir' => $tempDir,
        ]);

        $mpdf->WriteHTML($html);

        $path = tempnam(sys_get_temp_dir(), 'offer_').'.pdf';
        $mpdf->Output($path, Destination::FILE);

        return $path;
    }

    /**
     * The full data set the template renders, mapped from the offer's own
     * records. Kept public so it can be asserted directly in tests.
     *
     * @return array<string, mixed>
     */
    public function buildViewData(CustomerOffer $offer): array
    {
        $offer->loadMissing([
            'tenant',
            'customer',
            'items.product.category',
            'items.supplierProduct',
            'items.unit',
        ]);

        $tenant = $offer->tenant;
        $customer = $offer->customer;
        $currency = $offer->currency ?: 'RON';

        $items = $offer->items->values()
            ->map(fn (CustomerOfferItem $item, int $index): array => $this->mapItem($item, $index, $currency))
            ->all();

        return [
            'red' => self::RED,
            'supplier' => [
                'brand' => $tenant?->name ?: __('Ofertă'),
                'name' => $tenant?->legal_name ?: ($tenant?->name ?? '-'),
                'cif' => $this->orDash($tenant?->vat_number),
                'reg' => $this->orDash($tenant?->registration_number),
                'address' => $this->addressLine($tenant?->address, $tenant?->city),
                'phone' => $tenant?->phone ?: null,
                'email' => $tenant?->email ?: null,
                'website' => $tenant?->website ?: null,
                'logo' => $this->logoPath($tenant),
                'banks' => $this->bankAccounts($tenant),
            ],
            'buyer' => [
                'name' => $customer?->legal_name ?: ($customer?->name ?? '-'),
                'cif' => $this->orDash($customer?->vat_number),
                'reg' => '-',
                'address' => $this->orDash($customer?->address),
                'locality' => $this->orDash($customer?->city),
                'phone' => $this->orDash($customer?->phone),
                'bank' => '-',
                'email' => $this->orDash($customer?->email),
                'ref' => $this->orDash($customer?->contact_person),
            ],
            'offerNumber' => $offer->offer_number ?: (string) $offer->getKey(),
            'offerDate' => $offer->offer_date?->format('d.m.Y') ?? '-',
            'currency' => $currency,
            'items' => $items,
            'total' => $this->number($this->resolveTotal($offer)),
            'notes' => $this->notes($offer),
            'qr' => $tenant?->website ?: config('app.url'),
            // Drop-in header/footer artwork; falls back to the bundled banner.
            'banner' => $this->assetImage('banner') ?? public_path(self::ASSET_DIR.'/banner.png'),
            'signature' => $this->assetImage('signature'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItem(CustomerOfferItem $item, int $index, string $currency): array
    {
        $supplierProduct = $item->supplierProduct;

        $description = collect([
            filled($supplierProduct?->quality) ? $supplierProduct->quality : null,
            filled($supplierProduct?->caliber) ? __('calibru').' '.$supplierProduct->caliber : null,
        ])->filter()->implode(', ');

        $quantity = (float) $item->quantity;
        $price = (float) $item->sale_price;
        $value = $item->line_total !== null ? (float) $item->line_total : $quantity * $price;

        return [
            'nr' => $index + 1,
            'name' => $item->product?->name ?: ($supplierProduct?->name ?? '-'),
            'description' => $description,
            'image' => $this->itemImagePath($item),
            'um' => $item->unit?->symbol ?: 'KG',
            'quantity' => $this->number($quantity),
            'unit_price' => $this->number($price),
            'currency' => $currency,
            'discount' => $this->number(0).'%',
            'value' => $this->number($value),
        ];
    }

    private function resolveTotal(CustomerOffer $offer): float
    {
        if ($offer->total !== null && (float) $offer->total > 0) {
            return (float) $offer->total;
        }

        return (float) $offer->items->sum(
            fn (CustomerOfferItem $item): float => $item->line_total !== null
                ? (float) $item->line_total
                : (float) $item->quantity * (float) $item->sale_price,
        );
    }

    /**
     * Absolute filesystem path to the item's product image, resolved in order:
     * the product's own/category picture, the supplier product's picture, then a
     * drop-in file at public/images/offer-pdf/products/{name-slug|id}.{ext}.
     * Null when none exists so the template shows a placeholder.
     */
    private function itemImagePath(CustomerOfferItem $item): ?string
    {
        $relative = $item->product?->display_image_path ?: $item->supplierProduct?->image_path;

        if (filled($relative)) {
            $disk = Storage::disk('public');

            if ($disk->exists($relative)) {
                return $disk->path($relative);
            }
        }

        $product = $item->product;

        if ($product !== null) {
            return $this->assetImage('products/'.Str::slug((string) $product->name))
                ?? $this->assetImage('products/'.$product->getKey());
        }

        return null;
    }

    /**
     * The merchant logo: the tenant's uploaded logo, else a drop-in file at
     * public/images/offer-pdf/logo.{ext}, else null (the template draws a
     * monogram).
     */
    private function logoPath(?Tenant $tenant): ?string
    {
        if ($tenant !== null && filled($tenant->logo)) {
            $disk = Storage::disk('public');

            if ($disk->exists($tenant->logo)) {
                return $disk->path($tenant->logo);
            }
        }

        return $this->assetImage('logo');
    }

    /**
     * Absolute path to a drop-in asset under public/images/offer-pdf/, trying the
     * accepted extensions in priority order. Null when no matching file exists.
     */
    private function assetImage(string $nameWithoutExtension): ?string
    {
        foreach (self::ASSET_EXTENSIONS as $extension) {
            $path = public_path(self::ASSET_DIR.'/'.$nameWithoutExtension.'.'.$extension);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The merchant's bank accounts, stored as a JSON tenant setting so they can be
     * configured per merchant without a schema change.
     *
     * @return array<int, array{bank: string, iban: string}>
     */
    private function bankAccounts(?Tenant $tenant): array
    {
        if ($tenant === null) {
            return [];
        }

        $raw = TenantSetting::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('key', self::BANK_ACCOUNTS_SETTING)
            ->value('value');

        if (blank($raw)) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(fn ($entry): array => [
                'bank' => (string) ($entry['bank'] ?? ''),
                'iban' => (string) ($entry['iban'] ?? ''),
            ])
            ->filter(fn (array $entry): bool => $entry['bank'] !== '' || $entry['iban'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function notes(CustomerOffer $offer): array
    {
        if (filled($offer->notes)) {
            return array_values(array_filter(
                preg_split('/\r\n|\r|\n/', trim(strip_tags((string) $offer->notes))) ?: [],
                fn (string $line): bool => trim($line) !== '',
            ));
        }

        return [
            __('Oferta este valabilă 30 de zile de la data emiterii.'),
            __('Termene de livrare: În funcție de stoc și comandă.'),
            __('Plata: Conform înțelegerii.'),
        ];
    }

    private function addressLine(?string $address, ?string $city): string
    {
        $line = collect([$address, $city])->filter()->implode(', ');

        return $line !== '' ? $line : '-';
    }

    private function orDash(?string $value): string
    {
        return filled($value) ? $value : '-';
    }

    private function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}
