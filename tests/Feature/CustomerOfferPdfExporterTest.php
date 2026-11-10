<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Enums\CustomerOfferPdfTemplate;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Services\CustomerOfferPdfExporter;
use App\Modules\Customers\Models\Customer;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\Product;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\TenantSettings\Models\TenantSetting;
use App\Modules\Units\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerOfferPdfExporterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: CustomerOffer, 1: Tenant, 2: Customer}
     */
    private function makeOffer(): array
    {
        $tenant = Tenant::create([
            'name' => 'Freshmarket București',
            'legal_name' => 'Freshmarket Distribuție SRL',
            'vat_number' => 'RO38151507',
            'registration_number' => 'J22/2787/2017',
            'address' => 'Str. Anastasie Panu, Nr. 13/15',
            'city' => 'București',
            'email' => 'office@freshmarket.test',
            'phone' => '+40 748 894 514',
            'website' => 'https://freshmarket.test',
        ]);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Carrefour',
            'legal_name' => 'CARREFOUR ROMANIA SA',
            'vat_number' => 'RO11588780',
            'address' => 'Str. Gara Herăstrău 4C',
            'city' => 'București',
        ]);

        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'Ferma Verde SRL']);
        $unit = Unit::create(['tenant_id' => $tenant->id, 'name' => 'Kilogram', 'symbol' => 'KG']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Cartofi dulci']);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Cartofi dulci',
            'status' => 'active',
            'caliber' => '200-400 g',
            'unit_price' => 8,
        ]);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'offer_number' => 'OC-00065',
            'offer_date' => '2026-07-08',
            'currency' => 'RON',
            'status' => 'draft',
            'total' => 4125,
        ]);

        CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'supplier_product_id' => $supplierProduct->id,
            'unit_id' => $unit->id,
            'quantity' => 500,
            'sale_price' => 8.25,
            'line_total' => 4125,
        ]);

        return [$offer->refresh(), $tenant, $customer];
    }

    public function test_build_view_data_maps_offer_records(): void
    {
        [$offer] = $this->makeOffer();

        $data = app(CustomerOfferPdfExporter::class)->buildViewData($offer);

        // Merchant block comes from the tenant.
        $this->assertSame('Freshmarket Distribuție SRL', $data['supplier']['name']);
        $this->assertSame('RO38151507', $data['supplier']['cif']);
        $this->assertSame('J22/2787/2017', $data['supplier']['reg']);
        $this->assertSame('Str. Anastasie Panu, Nr. 13/15, București', $data['supplier']['address']);

        // Buyer block comes from the customer.
        $this->assertSame('CARREFOUR ROMANIA SA', $data['buyer']['name']);
        $this->assertSame('RO11588780', $data['buyer']['cif']);
        $this->assertSame('București', $data['buyer']['locality']);
        // Customer has no phone/email — rendered as a dash.
        $this->assertSame('-', $data['buyer']['phone']);
        $this->assertSame('-', $data['buyer']['email']);

        // Offer meta.
        $this->assertSame('OC-00065', $data['offerNumber']);
        $this->assertSame('08.07.2026', $data['offerDate']);
        $this->assertSame('RON', $data['currency']);
        $this->assertSame('4.125,00', $data['total']);

        // Line item, Romanian number formatting and derived description.
        $this->assertCount(1, $data['items']);
        $item = $data['items'][0];
        $this->assertSame('Cartofi dulci', $item['name']);
        $this->assertSame('KG', $item['um']);
        $this->assertSame('500,00', $item['quantity']);
        $this->assertSame('8,25', $item['unit_price']);
        $this->assertSame('RON', $item['currency']);
        $this->assertSame('0,00%', $item['discount']);
        $this->assertSame('4.125,00', $item['value']);
        $this->assertSame('calibru 200-400 g', $item['description']);
    }

    public function test_bank_accounts_are_read_from_a_tenant_setting(): void
    {
        [$offer, $tenant] = $this->makeOffer();

        TenantSetting::create([
            'tenant_id' => $tenant->id,
            'key' => CustomerOfferPdfExporter::BANK_ACCOUNTS_SETTING,
            'value' => json_encode([
                ['bank' => 'ING BANK', 'iban' => 'RO92INGB0000999907669336'],
                ['bank' => 'RAIFFEISEN BANK', 'iban' => 'RO63RZBR0000060019695788'],
            ]),
        ]);

        $data = app(CustomerOfferPdfExporter::class)->buildViewData($offer);

        $this->assertCount(2, $data['supplier']['banks']);
        $this->assertSame('ING BANK', $data['supplier']['banks'][0]['bank']);
        $this->assertSame('RO92INGB0000999907669336', $data['supplier']['banks'][0]['iban']);
    }

    public function test_a_drop_in_product_photo_is_matched_by_name_slug(): void
    {
        [$offer] = $this->makeOffer(); // product "Cartofi dulci"

        $dir = public_path('images/offer-pdf/products');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/cartofi-dulci.png';
        file_put_contents($file, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'
        ));

        try {
            $data = app(CustomerOfferPdfExporter::class)->buildViewData($offer);

            $this->assertSame($file, $data['items'][0]['image']);
        } finally {
            @unlink($file);
        }
    }

    public function test_banner_falls_back_to_the_bundled_illustration_and_signature_is_optional(): void
    {
        [$offer] = $this->makeOffer();

        $data = app(CustomerOfferPdfExporter::class)->buildViewData($offer);

        $this->assertSame(public_path('images/offer-pdf/banner.jpg'), $data['banner']);
        $this->assertFileExists($data['banner']);
        $this->assertNull($data['signature']);
    }

    /** @return array<string, array{CustomerOfferPdfTemplate}> */
    public static function templates(): array
    {
        return collect(CustomerOfferPdfTemplate::cases())->mapWithKeys(fn (CustomerOfferPdfTemplate $template): array => [$template->value => [$template]])->all();
    }

    #[DataProvider('templates')]
    public function test_export_produces_a_pdf_file(CustomerOfferPdfTemplate $template): void
    {
        [$offer] = $this->makeOffer();

        $path = app(CustomerOfferPdfExporter::class)->export($offer, $template);

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', file_get_contents($path));

        if ($artifactDirectory = getenv('OFFER_PDF_ARTIFACT_DIR')) {
            if (! is_dir($artifactDirectory)) {
                mkdir($artifactDirectory, 0775, true);
            }
            copy($path, $artifactDirectory.'/'.$template->value.'.pdf');
        }

        @unlink($path);
    }

    public function test_english_simple_template_uses_english_labels_and_number_format_regardless_of_interface_language(): void
    {
        [$offer] = $this->makeOffer();
        app()->setLocale('ro');
        $data = app(CustomerOfferPdfExporter::class)->buildViewData($offer, CustomerOfferPdfTemplate::SimpleEn);
        $html = view(CustomerOfferPdfTemplate::SimpleEn->view(), $data)->render();

        $this->assertSame('ro', app()->getLocale());
        $this->assertSame('en', $data['documentLocale']);
        $this->assertStringContainsString('Commercial Offer No. OC-00065 dated 08.07.2026', $html);
        $this->assertStringContainsString('BUYER', $html);
        $this->assertStringContainsString('Product name', $html);
        $this->assertStringContainsString('Unit price', $html);
        $this->assertStringContainsString('8.25 RON', $html);
        $this->assertStringContainsString('As ordered', $html);
        $this->assertStringContainsString('Note: Prices exclude VAT. Transport is included.', $html);
        $this->assertStringNotContainsString('Conform comenzii', $html);
    }

    public function test_romanian_simple_template_uses_romanian_labels_and_real_offer_data(): void
    {
        [$offer] = $this->makeOffer();
        app()->setLocale('en');
        $data = app(CustomerOfferPdfExporter::class)->buildViewData($offer, CustomerOfferPdfTemplate::SimpleRo);
        $html = view(CustomerOfferPdfTemplate::SimpleRo->view(), $data)->render();

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('ro', $data['documentLocale']);
        $this->assertStringContainsString('Ofertă comercială nr. OC-00065 din 08.07.2026', $html);
        $this->assertStringContainsString('FRESHMARKET DISTRIBUȚIE SRL', $html);
        $this->assertStringContainsString('CARREFOUR ROMANIA SA', $html);
        $this->assertStringContainsString('Nume produs', $html);
        $this->assertStringContainsString('Cartofi dulci', $html);
        $this->assertStringContainsString('Conform comenzii', $html);
        $this->assertStringContainsString('8,25 RON', $html);
        $this->assertStringContainsString('Notă: Prețurile nu includ TVA. Transportul este inclus.', $html);
        $this->assertStringNotContainsString('PAI AGROCOMPLEX', $html);
    }

    public function test_existing_complex_template_remains_the_default(): void
    {
        [$offer] = $this->makeOffer();
        $exporter = app(CustomerOfferPdfExporter::class);
        $this->assertSame($exporter->buildViewData($offer, CustomerOfferPdfTemplate::Complex), $exporter->buildViewData($offer));
        $this->assertSame('pdf.customer-offer', CustomerOfferPdfTemplate::Complex->view());
    }

    public function test_simple_template_escapes_dynamic_content_and_keeps_additional_offer_notes(): void
    {
        [$offer] = $this->makeOffer();
        $offer->update(['notes' => 'Payment within 30 days. <script>alert("unsafe")</script>']);
        $offer->items()->first()->product->update(['name' => '<b>Test & product</b>']);
        $html = view(CustomerOfferPdfTemplate::SimpleEn->view(), app(CustomerOfferPdfExporter::class)->buildViewData($offer, CustomerOfferPdfTemplate::SimpleEn))->render();
        $this->assertStringContainsString('Payment within 30 days.', $html);
        $this->assertStringContainsString('&lt;b&gt;Test &amp; product&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_simple_template_handles_an_offer_without_product_lines(): void
    {
        [$offer] = $this->makeOffer();
        $offer->items()->delete();
        $path = app(CustomerOfferPdfExporter::class)->export($offer->fresh(), CustomerOfferPdfTemplate::SimpleRo);

        try {
            $this->assertStringStartsWith('%PDF', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_simple_template_paginates_long_product_lists(): void
    {
        [$offer] = $this->makeOffer();
        $first = $offer->items()->first();

        for ($index = 0; $index < 70; $index++) {
            $first->replicate()->save();
        }

        $path = app(CustomerOfferPdfExporter::class)->export($offer->fresh(), CustomerOfferPdfTemplate::SimpleEn);

        try {
            $this->assertGreaterThan(1, preg_match_all('/\/Type\s*\/Page\b/', file_get_contents($path)));
        } finally {
            @unlink($path);
        }
    }
}
