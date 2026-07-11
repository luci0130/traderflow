<?php

namespace App\Modules\CustomerOffers\Services;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\TenantSettings\Services\TenantBankAccounts;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
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
    public const RED = '#C32026';

    /** Tenant setting key holding the merchant's bank accounts. */
    public const BANK_ACCOUNTS_SETTING = TenantBankAccounts::KEY;

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

        $fontData = (new FontVariables)->getDefaults()['fontdata'];
        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            // Reserve space for the red footer bar, which is pinned to the bottom
            // of every page via an mpdf page footer in the template.
            'margin_bottom' => 20,
            'margin_footer' => 0,
            // Inter, embedded as selectable/searchable text. Separate families give
            // per-weight control (mpdf only exposes R/B per family).
            'fontDir' => array_merge($fontDirs, [resource_path('fonts/inter')]),
            'fontdata' => $fontData + [
                'inter' => ['R' => 'Inter-Regular.ttf', 'B' => 'Inter-Bold.ttf'],
                'intermedium' => ['R' => 'Inter-Medium.ttf'],
                'intersemibold' => ['R' => 'Inter-SemiBold.ttf'],
            ],
            'default_font' => 'inter',
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
            'items.suppliers',
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
                'banks' => $tenant !== null ? TenantBankAccounts::get($tenant) : [],
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
            // Only show the photo column when at least one line actually has one,
            // so offers without product images don't carry an empty column.
            'hasProductImages' => collect($items)->contains(fn (array $item): bool => $item['image'] !== null),
            'total' => $this->number($this->resolveTotal($offer)),
            'notes' => $this->notes($offer),
            'qr' => $tenant?->website ?: config('app.url'),
            // Drop-in header/footer artwork; falls back to the bundled banner.
            'banner' => $this->pdfSafeImage($this->assetImage('banner') ?? public_path(self::ASSET_DIR.'/banner.png')),
            'signature' => ($signature = $this->assetImage('signature')) !== null ? $this->pdfSafeImage($signature) : null,
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

        $quantity = $this->securedQuantity($item);
        $price = (float) $item->sale_price;
        $value = $quantity * $price;

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

    /**
     * The offered quantity for a line: the total secured across its chosen
     * suppliers (what the offer commits to), falling back to the desired
     * quantity when nothing has been sourced yet.
     */
    private function securedQuantity(CustomerOfferItem $item): float
    {
        $secured = $item->totalSecuredQuantity();

        return $secured > 0 ? $secured : (float) $item->quantity;
    }

    private function resolveTotal(CustomerOffer $offer): float
    {
        return (float) $offer->items->sum(
            fn (CustomerOfferItem $item): float => $this->securedQuantity($item) * (float) $item->sale_price,
        );
    }

    /**
     * Absolute filesystem path to the item's product image. Mirrors the offer
     * editor's resolution (items-board): the product's own picture or its
     * category's, then the chosen supplier product's own picture or its
     * category's (matched by the free-text category name). Finally a drop-in file
     * at public/images/offer-pdf/products/{name-slug|id}.{ext}. Null when none
     * exists so the template shows a placeholder.
     */
    private function itemImagePath(CustomerOfferItem $item): ?string
    {
        $relative = $item->product?->display_image_path ?: $item->supplierProduct?->display_image_path;

        if (filled($relative)) {
            $disk = Storage::disk('public');

            if ($disk->exists($relative)) {
                return $disk->path($relative);
            }
        }

        $product = $item->product;

        if ($product !== null) {
            $drop = $this->assetImage('products/'.Str::slug((string) $product->name))
                ?? $this->assetImage('products/'.$product->getKey());

            if ($drop !== null) {
                return $drop;
            }
        }

        return null;
    }

    /**
     * The merchant logo uploaded on the tenant (checked on both the public and the
     * default upload disk), else a drop-in file at public/images/offer-pdf/logo.*,
     * else null (the template draws a monogram). SVG logos are rasterised so mpdf
     * embeds them cleanly.
     */
    private function logoPath(?Tenant $tenant): ?string
    {
        if ($tenant !== null && filled($tenant->logo)) {
            foreach (['public', config('filament.default_filesystem_disk', 'local')] as $diskName) {
                $disk = Storage::disk($diskName);

                if ($disk->exists($tenant->logo)) {
                    return $this->pdfSafeImage($disk->path($tenant->logo));
                }
            }
        }

        $asset = $this->assetImage('logo');

        return $asset !== null ? $this->pdfSafeImage($asset) : null;
    }

    /**
     * Returns a path mpdf can embed safely. SVG sources corrupt mpdf's colour
     * state, so they are rasterised to a temporary PNG via imagick; other formats
     * pass through unchanged.
     */
    private function pdfSafeImage(string $absolutePath): string
    {
        if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) !== 'svg' || ! extension_loaded('imagick')) {
            return $absolutePath;
        }

        try {
            $imagick = new \Imagick;
            $imagick->setBackgroundColor(new \ImagickPixel('transparent'));
            $imagick->readImage($absolutePath);
            $imagick->setImageFormat('png');

            $png = tempnam(sys_get_temp_dir(), 'offimg_').'.png';
            $imagick->writeImage($png);
            $imagick->clear();

            return $png;
        } catch (\Throwable) {
            return $absolutePath;
        }
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
