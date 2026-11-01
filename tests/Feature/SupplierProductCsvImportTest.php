<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Filament\Pages\SupplierProductsV2;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\Pages\ListSupplierProducts;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Services\SupplierProductCsvImport;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

use function setPermissionsTeamId;

class SupplierProductCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->tenant = Tenant::create(['name' => 'CSV tenant', 'is_active' => true]);
        $this->user = User::factory()->create();
        $this->tenant->users()->attach($this->user, ['role' => 'super_admin']);
        setPermissionsTeamId($this->tenant->id);
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id]);
        $this->user->assignRole($role);
        session(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->supplier = Supplier::create(['name' => 'Fruit Active', 'tenant_id' => $this->tenant->id]);
    }

    public function test_validation_accepts_bom_semicolons_quoted_values_and_decimal_commas_without_writes(): void
    {
        $file = $this->csv(
            "\xEF\xBB\xBFname;unit_price;min_quantity_value;min_quantity_unit;currency;description;valid_until;is_bio;packaging_method\n".
            '"Roșii; cherry";"4,50";10;kg;eur;"Roșii proaspete, bio";2026-12-31;1;Vrac'."\n\n",
        );

        $rows = app(SupplierProductCsvImport::class)->validateFile($file);

        $this->assertCount(1, $rows);
        $this->assertSame('Roșii; cherry', $rows[0]['name']);
        $this->assertSame('4.50', $rows[0]['unit_price']);
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertSame('active', $rows[0]['status']);
        $this->assertNotNull($rows[0]['packaging_method_id']);
        $this->assertDatabaseCount('supplier_products', 0);
    }

    #[DataProvider('invalidCsvFiles')]
    public function test_invalid_csv_is_rejected_without_creating_products(string $contents): void
    {
        try {
            app(SupplierProductCsvImport::class)->validateFile($this->csv($contents));
            $this->fail('Invalid CSV should fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
            $this->assertDatabaseCount('supplier_products', 0);
        }
    }

    public static function invalidCsvFiles(): array
    {
        $header = "name,unit_price,min_quantity_value,min_quantity_unit,currency\n";

        return [
            'empty' => [''],
            'no rows' => [$header],
            'missing headers' => ["name,unit_price\nMere,2\n"],
            'duplicate headers' => ["name,unit_price,min_quantity_value,min_quantity_unit,currency,name\nMere,2,10,kg,EUR,Mere\n"],
            'unknown headers' => ["name,unit_price,min_quantity_value,min_quantity_unit,currency,producer_id\nMere,2,10,kg,EUR,999\n"],
            'wrong width' => [$header."Mere,2,10,kg\n"],
            'missing name' => [$header.",2,10,kg,EUR\n"],
            'negative price' => [$header."Mere,-2,10,kg,EUR\n"],
            'non numeric price' => [$header."Mere,abc,10,kg,EUR\n"],
            'too much precision' => [$header."Mere,2.12345,10,kg,EUR\n"],
            'decimal overflow' => [$header."Mere,100000000000,10,kg,EUR\n"],
            'unknown currency' => [$header."Mere,2,10,kg,XXX\n"],
            'unknown unit' => [$header."Mere,2,10,invalid-unit,EUR\n"],
            'missing unit with unknown MOQ' => ["name,unit_price,min_quantity_value,min_quantity_unit,currency\nMere,2,,,EUR\n"],
            'absent unit with unknown MOQ' => ["name,unit_price,currency\nMere,2,EUR\n"],
            'negative MOQ' => [$header."Mere,2,-1,kg,EUR\n"],
            'non numeric MOQ' => [$header."Mere,2,unknown,kg,EUR\n"],
            'MOQ precision' => [$header."Mere,2,1.12345,kg,EUR\n"],
            'MOQ overflow' => [$header."Mere,2,100000000000,kg,EUR\n"],
            'invalid date' => ["name,unit_price,min_quantity_value,min_quantity_unit,currency,valid_until\nMere,2,10,kg,EUR,2026-02-30\n"],
            'invalid boolean' => ["name,unit_price,min_quantity_value,min_quantity_unit,currency,is_bio\nMere,2,10,kg,EUR,yes\n"],
            'unknown packaging' => ["name,unit_price,min_quantity_value,min_quantity_unit,currency,packaging_method\nMere,2,10,kg,EUR,Unknown\n"],
            'invalid encoding' => [$header."Mere\xFF,2,10,kg,EUR\n"],
            'row limit' => [$header.str_repeat("Mere,2,10,kg,EUR\n", SupplierProductCsvImport::MAX_ROWS + 1)],
        ];
    }

    #[DataProvider('csvFilesWithOptionalMoq')]
    public function test_validation_and_import_preserve_optional_moq(string $contents, ?string $expectedMoq): void
    {
        $file = $this->csv($contents);
        $importer = app(SupplierProductCsvImport::class);
        $rows = $importer->validateFile($file);

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('min_quantity_value', $rows[0]);
        if ($expectedMoq === null) {
            $this->assertNull($rows[0]['min_quantity_value']);
        }
        $this->assertDatabaseCount('supplier_products', 0);

        $this->assertSame(1, $importer->import($file, $this->supplier->id, $importer->validationToken($file, $this->supplier->id)));

        $product = $this->supplier->supplierProducts()->sole();
        $this->assertSame($expectedMoq, $product->min_quantity_value);
        $this->assertSame('kg', $product->min_quantity_unit);
        $this->assertSame('2.5000', $product->unit_price);
        $this->assertDatabaseHas('supplier_products', [
            'id' => $product->id,
            'min_quantity_value' => $expectedMoq,
        ]);
    }

    public static function csvFilesWithOptionalMoq(): array
    {
        $header = "name,unit_price,min_quantity_value,min_quantity_unit,currency\n";

        return [
            'blank MOQ' => [$header."Mere,2.50,,kg,EUR\n", null],
            'full template with blank MOQ and known package size' => [implode(';', SupplierProductCsvImport::COLUMNS)."\nMere;2.50;;kg;EUR;;;;;;;;;0;active;;10\n", null],
            'whitespace MOQ' => [$header."Mere,2.50,   ,kg,EUR\n", null],
            'absent MOQ column' => ["name,unit_price,min_quantity_unit,currency\nMere,2.50,kg,EUR\n", null],
            'zero MOQ' => [$header."Mere,2.50,0,kg,EUR\n", '0.0000'],
            'valid integer MOQ' => [$header."Mere,2.50,10,kg,EUR\n", '10.0000'],
            'valid decimal MOQ' => [$header."Mere,2.50,1.2345,kg,EUR\n", '1.2345'],
            'valid decimal comma MOQ' => ["name;unit_price;min_quantity_value;min_quantity_unit;currency\nMere;2.50;1,25;kg;EUR\n", '1.2500'],
        ];
    }

    public function test_validation_preserves_the_maximum_moq_without_rounding(): void
    {
        $rows = app(SupplierProductCsvImport::class)->validateFile($this->csv(
            "name;unit_price;min_quantity_value;min_quantity_unit;currency\nMere;2.50;99999999999.9999;kg;EUR\n",
        ));

        $this->assertSame('99999999999.9999', $rows[0]['min_quantity_value']);
        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_modal_validates_a_csv_without_moq_without_saving_products(): void
    {
        Livewire::test(ListSupplierProducts::class)
            ->mountAction('importSupplierProducts')
            ->setActionData([
                'supplier_id' => $this->supplier->id,
                'file' => $this->csv("name;unit_price;min_quantity_unit;currency\nMere;2.50;kg;EUR\n"),
            ])
            ->callMountedAction(['validateOnly' => true])
            ->assertHasNoActionErrors()
            ->assertSet('mountedActions.0.data.validation_report', 'CSV valid: 1 products ready to import. No products have been saved yet.');

        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_validation_reports_row_numbers_and_rejects_an_entire_mixed_file(): void
    {
        $file = $this->csv("name,unit_price,min_quantity_value,min_quantity_unit,currency\nMere,2,10,kg,EUR\nPere,-1,10,kg,EUR\n");

        try {
            app(SupplierProductCsvImport::class)->validateFile($file);
            $this->fail('The invalid row should reject the file.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('CSV row 3:', $exception->errors()['file'][0]);
            $this->assertDatabaseCount('supplier_products', 0);
        }
    }

    public function test_blank_lines_do_not_count_toward_the_product_row_limit(): void
    {
        $file = $this->csv("name,unit_price,min_quantity_value,min_quantity_unit,currency\n\n".str_repeat("Mere,2,10,kg,EUR\n", SupplierProductCsvImport::MAX_ROWS));

        $this->assertCount(SupplierProductCsvImport::MAX_ROWS, app(SupplierProductCsvImport::class)->validateFile($file));
        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_non_csv_and_oversized_files_are_rejected(): void
    {
        foreach ([
            UploadedFile::fake()->createWithContent('products.txt', "name,unit_price\nMere,2\n"),
            UploadedFile::fake()->create('products.csv', SupplierProductCsvImport::MAX_SIZE_KB + 1, 'text/csv'),
        ] as $file) {
            try {
                app(SupplierProductCsvImport::class)->validateFile($file);
                $this->fail('The file should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
    }

    public function test_import_creates_validated_products_for_the_selected_supplier(): void
    {
        $file = $this->validCsv();
        $importer = app(SupplierProductCsvImport::class);
        $importer->validateFile($file);
        $count = $importer->import($file, $this->supplier->id, $importer->validationToken($file, $this->supplier->id));

        $this->assertSame(2, $count);
        $this->assertDatabaseCount('supplier_products', 2);
        $this->assertDatabaseHas('supplier_products', ['producer_id' => $this->supplier->id, 'name' => 'Mere', 'unit_price' => 2.5, 'status' => 'active']);
    }

    public function test_import_rejects_unvalidated_or_changed_files_and_suppliers(): void
    {
        $file = $this->validCsv();
        $importer = app(SupplierProductCsvImport::class);
        $otherSupplier = Supplier::create(['name' => 'Go Fruit', 'tenant_id' => $this->tenant->id]);
        $token = $importer->validationToken($file, $this->supplier->id);

        foreach ([
            [$file, $this->supplier->id, ''],
            [$file, $this->supplier->id, 'forged-token'],
            [$file, $otherSupplier->id, $token],
            [$this->csv("name,unit_price,min_quantity_value,min_quantity_unit,currency\nChanged,3,1,kg,EUR\n"), $this->supplier->id, $token],
        ] as [$upload, $supplierId, $receipt]) {
            try {
                $importer->import($upload, $supplierId, $receipt);
                $this->fail('Import must require validation of this file and supplier.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
                $this->assertDatabaseCount('supplier_products', 0);
            }
        }
    }

    public function test_import_rolls_back_every_row_if_persistence_fails(): void
    {
        $file = $this->validCsv();
        $importer = app(SupplierProductCsvImport::class);
        $event = 'eloquent.creating: '.SupplierProduct::class;
        Event::listen($event, function (SupplierProduct $product): void {
            if ($product->name === 'Pere') {
                throw new RuntimeException('Simulated persistence failure.');
            }
        });

        try {
            $importer->import($file, $this->supplier->id, $importer->validationToken($file, $this->supplier->id));
            $this->fail('The second row should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated persistence failure.', $exception->getMessage());
            $this->assertDatabaseCount('supplier_products', 0);
        } finally {
            Event::forget($event);
        }
    }

    #[DataProvider('supplierProductPages')]
    public function test_modal_validates_without_saving_then_imports_on_a_separate_submission(string $pageClass): void
    {
        $page = Livewire::test($pageClass)
            ->mountAction('importSupplierProducts')
            ->assertActionMounted('importSupplierProducts')
            ->setActionData(['supplier_id' => $this->supplier->id, 'file' => $this->validCsv()])
            ->callMountedAction(['validateOnly' => true])
            ->assertHasNoActionErrors()
            ->assertSet('mountedActions.0.data.validation_report', 'CSV valid: 2 products ready to import. No products have been saved yet.');

        $this->assertDatabaseCount('supplier_products', 0);
        $this->assertNotEmpty($page->get('mountedActions.0.data.validation_token'));

        $page->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('supplier_products', 2);
    }

    public static function supplierProductPages(): array
    {
        return [[ListSupplierProducts::class], [SupplierProductsV2::class]];
    }

    public function test_changing_the_supplier_clears_validation_and_prevents_import(): void
    {
        $otherSupplier = Supplier::create(['name' => 'Go Fruit', 'tenant_id' => $this->tenant->id]);

        Livewire::test(ListSupplierProducts::class)
            ->mountAction('importSupplierProducts')
            ->setActionData(['supplier_id' => $this->supplier->id, 'file' => $this->validCsv()])
            ->callMountedAction(['validateOnly' => true])
            ->assertHasNoActionErrors()
            ->set('mountedActions.0.data.supplier_id', $otherSupplier->id)
            ->assertSet('mountedActions.0.data.validation_token', null)
            ->assertSet('mountedActions.0.data.validation_report', null)
            ->callMountedAction();

        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_modal_shows_validation_errors_and_preserves_the_file_without_saving(): void
    {
        $page = Livewire::test(ListSupplierProducts::class)
            ->mountAction('importSupplierProducts')
            ->setActionData(['supplier_id' => $this->supplier->id, 'file' => $this->csv("name,unit_price,min_quantity_value,min_quantity_unit,currency\nMere,-2,10,kg,EUR\n")])
            ->callMountedAction(['validateOnly' => true])
            ->assertHasNoActionErrors()
            ->assertSet('mountedActions.0.data.validation_token', null);

        $this->assertStringContainsString('CSV row 2:', $page->get('mountedActions.0.data.validation_report'));
        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_both_pages_offer_the_modal_and_template_download_does_not_require_form_data(): void
    {
        $page = Livewire::test(SupplierProductsV2::class)
            ->assertActionExists('importSupplierProducts')
            ->mountAction('importSupplierProducts')
            ->assertActionMounted('importSupplierProducts');

        $this->assertArrayHasKey('validateCsv', $page->instance()->getMountedAction()->getExtraModalFooterActions());

        Livewire::test(SupplierProductsV2::class)
            ->callAction(['importSupplierProducts', 'downloadCsvTemplate'])
            ->assertFileDownloaded('supplier-products-template.csv');

        $this->assertDatabaseCount('supplier_products', 0);
    }

    public function test_downloaded_template_passes_validation(): void
    {
        $importer = app(SupplierProductCsvImport::class);
        ob_start();
        $importer->downloadTemplate()->sendContent();
        $contents = ob_get_clean();

        $this->assertCount(1, $importer->validateFile($this->csv($contents)));

        $header = str_getcsv(strtok(ltrim($contents, "\xEF\xBB\xBF"), "\n"), ';', '"', '');
        $this->assertSame([
            'name', 'unit_price', 'min_quantity_value', 'min_quantity_unit', 'currency', 'category',
            'country_of_origin', 'variety', 'caliber', 'quality', 'description', 'quantity_available',
            'valid_until', 'is_bio', 'status', 'packaging_method', 'package_size',
        ], $header);
    }

    public function test_users_without_create_permission_cannot_use_the_import(): void
    {
        $this->user->removeRole('super_admin');
        $this->user->givePermissionTo(Permission::create(['name' => 'ViewAny:SupplierProduct', 'guard_name' => 'web']));

        Livewire::test(ListSupplierProducts::class)->assertActionHidden('importSupplierProducts');

        $this->expectException(AuthorizationException::class);
        app(SupplierProductCsvImport::class)->import($this->validCsv(), $this->supplier->id, 'anything');
    }

    public function test_import_does_not_allow_a_supplier_outside_the_users_tenant(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other tenant']);
        $otherSupplier = Supplier::create(['name' => 'Private supplier', 'tenant_id' => $otherTenant->id]);
        $this->user->removeRole('super_admin');
        $this->user->givePermissionTo(Permission::create(['name' => 'Create:SupplierProduct', 'guard_name' => 'web']));
        $file = $this->validCsv();
        $importer = app(SupplierProductCsvImport::class);

        $this->expectException(ModelNotFoundException::class);
        $importer->import($file, $otherSupplier->id, $importer->validationToken($file, $otherSupplier->id));
    }

    private function validCsv(): UploadedFile
    {
        return $this->csv("name,unit_price,min_quantity_value,min_quantity_unit,currency\nMere,2.50,10,kg,EUR\nPere,3,20,kg,EUR\n");
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('supplier-products.csv', $contents);
    }
}
