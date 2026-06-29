<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\CustomerOffers\Services\CustomerOfferExcelExporter;
use App\Modules\Customers\Models\Customer;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\Product;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class CustomerOfferExcelExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_builds_company_header_and_product_table_from_items(): void
    {
        $tenant = Tenant::create([
            'name' => 'SkyVest',
            'legal_name' => 'SkyVest Trading SRL',
            'vat_number' => 'RO12345678',
            'registration_number' => 'J40/123/2020',
            'email' => 'office@skyvest.test',
            'phone' => '+40 21 000 000',
            'address' => 'Str. Exemplu 1',
            'city' => 'București',
            'country' => 'RO',
            'currency' => 'RON',
        ]);
        session(['tenant_id' => $tenant->id]);

        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Mega Image']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Ceapă galbenă']);

        $supplier = Supplier::create(['name' => 'Agricola Dâmbovița SA']);
        $supplierProduct = SupplierProduct::create([
            'producer_id' => $supplier->id,
            'name' => 'Ceapă galbenă',
            'status' => 'active',
            'category' => 'Legume',
            'country_of_origin' => 'RO',
            'default_packaging' => 'Sac 25kg',
            'quality' => 'Extra',
            'caliber' => '40-60mm',
            'unit_price' => 1.9,
            'currency' => 'RON',
            'quantity_available' => 500,
        ]);

        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'offer_number' => 'OC-1412',
            'offer_date' => today(),
            'currency' => 'RON',
            'status' => 'draft',
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
        ]);

        CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $offer->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_product_id' => $supplierProduct->id,
            'quantity' => 300,
            'purchase_price' => 1.9,
            'sale_price' => 2.5,
            'tax_rate' => 0,
            'line_total' => 750,
            'notes' => 'Livrare săptămânală',
        ]);

        $path = app(CustomerOfferExcelExporter::class)->export($offer->refresh());

        $this->assertFileExists($path);

        $cells = $this->readCells($path);
        $merges = $this->readMergeRefs($path);
        @unlink($path);

        // Title and footer span the full width A:I; merchant data sits in A:E and
        // the offer dates in F:I (1 item => footer on row 11).
        $this->assertContains('A1:I1', $merges);
        $this->assertContains('A2:E2', $merges);
        $this->assertContains('F2:I2', $merges);
        $this->assertContains('A11:I11', $merges);

        // Header: title (uppercase), merchant block and offer dates.
        $this->assertContains('OFERTĂ COMERCIALĂ - LEGUME ȘI FRUCTE PROASPETE', $cells);
        $this->assertContains('SkyVest Trading SRL', $cells);
        $this->assertContains('Date comerciant', $cells);
        $this->assertTrue($this->cellsContainSubstring($cells, 'Data ofertare:'));
        $this->assertTrue($this->cellsContainSubstring($cells, 'Valabilă până la:'));
        $this->assertTrue($this->cellsContainSubstring($cells, 'office@skyvest.test'));

        // Internal details must NOT leak into the document.
        $this->assertFalse($this->cellsContainSubstring($cells, 'OC-1412'));
        $this->assertFalse($this->cellsContainSubstring($cells, 'Mega Image'));

        // Table header (currency is appended to the price column).
        $this->assertContains('Categorie', $cells);
        $this->assertContains('Preț fără TVA (RON)', $cells);
        $this->assertContains('Cantitate disponibilă', $cells);
        $this->assertContains('Observații', $cells);

        // Footer notice.
        $this->assertTrue($this->cellsContainSubstring($cells, 'Prețurile sunt exprimate fără TVA și includ livrarea la client'));

        // Product row sourced from the item + supplier product.
        $this->assertContains('Legume', $cells);
        $this->assertContains('Ceapă galbenă', $cells);
        $this->assertContains('Sac 25kg', $cells);
        $this->assertContains('Extra', $cells);
        $this->assertContains('40-60mm', $cells);
        $this->assertContains('Livrare săptămânală', $cells);
        // Price and quantity are written as numbers (int/float depending on value).
        $this->assertTrue($this->cellsContainNumber($cells, 2.5));
        $this->assertTrue($this->cellsContainNumber($cells, 300));
        // Origin rendered as a country label, not the raw ISO code.
        $this->assertContains('Romania', $cells);
    }

    /**
     * Flatten every cell value from the workbook into a single list.
     *
     * @return list<mixed>
     */
    private function readCells(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $cells = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->toArray() as $value) {
                    $cells[] = $value;
                }
            }
        }

        $reader->close();

        return $cells;
    }

    /**
     * Read the <mergeCell ref="..."/> ranges from the first worksheet.
     *
     * @return list<string>
     */
    private function readMergeRefs(string $path): array
    {
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        preg_match_all('/<mergeCell ref="([^"]+)"/', (string) $xml, $matches);

        return $matches[1];
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function cellsContainSubstring(array $cells, string $needle): bool
    {
        foreach ($cells as $value) {
            if (is_string($value) && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $cells
     */
    private function cellsContainNumber(array $cells, float $needle): bool
    {
        foreach ($cells as $value) {
            if (is_numeric($value) && abs((float) $value - $needle) < 0.0001) {
                return true;
            }
        }

        return false;
    }
}
