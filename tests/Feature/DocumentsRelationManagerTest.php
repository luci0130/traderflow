<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Customers\Filament\Resources\Customers\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Documents\Filament\RelationManagers\DocumentsRelationManager;
use App\Modules\Documents\Models\Document;
use App\Modules\SalesOrders\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SupplierOffers\Filament\Resources\SupplierOffers\SupplierOfferResource;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\Suppliers\Filament\Resources\Suppliers\SupplierResource;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\Pages\EditCustomerOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_resource_registers_the_documents_relation_manager(): void
    {
        $resources = [
            SupplierResource::class,
            CustomerResource::class,
            SupplierOfferResource::class,
            CustomerOfferResource::class,
            SalesOrderResource::class,
        ];

        foreach ($resources as $resource) {
            $this->assertContains(
                DocumentsRelationManager::class,
                $resource::getRelations(),
                "[{$resource}] does not register DocumentsRelationManager.",
            );
        }
    }

    public function test_documents_are_isolated_per_tenant_through_the_morph_relation(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A']);
        $tenantB = Tenant::create(['name' => 'Tenant B']);

        $supplierA = Supplier::create(['tenant_id' => $tenantA->id, 'name' => 'A']);
        $supplierB = Supplier::create(['tenant_id' => $tenantB->id, 'name' => 'B']);

        $supplierA->documents()->create([
            'tenant_id' => $tenantA->id,
            'type' => 'invoice',
            'file_path' => 'documents/'.$tenantA->id.'/a.pdf',
            'original_name' => 'a.pdf',
        ]);
        $supplierB->documents()->create([
            'tenant_id' => $tenantB->id,
            'type' => 'contract',
            'file_path' => 'documents/'.$tenantB->id.'/b.pdf',
            'original_name' => 'b.pdf',
        ]);

        session(['tenant_id' => $tenantA->id]);

        $this->assertSame(1, Document::query()->count());
        $this->assertSame('a.pdf', Document::query()->first()->original_name);
    }

    public function test_deleting_a_document_removes_the_underlying_file(): void
    {
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'S']);

        $path = 'documents/'.$tenant->id.'/test.pdf';
        Storage::disk('local')->put($path, 'PDF-1.4 fake');

        $document = $supplier->documents()->create([
            'tenant_id' => $tenant->id,
            'type' => 'invoice',
            'file_path' => $path,
            'original_name' => 'test.pdf',
        ]);

        Storage::disk('local')->assertExists($path);

        $document->delete();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_uploading_a_document_persists_it_with_the_owners_tenant(): void
    {
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'Tenant A']);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer A']);
        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'status' => 'draft', 'currency' => 'RON',
        ]);

        // A globally-scoped user with no active tenant (the scenario that previously
        // wrote a null tenant_id and silently failed the insert).
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $offer,
            'pageClass' => EditCustomerOffer::class,
        ])
            ->mountTableAction('create')
            ->setTableActionData([
                'type' => 'invoice',
                'name' => 'Factura',
                'file_path' => UploadedFile::fake()->create('factura.pdf', 12, 'application/pdf'),
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('documents', [
            'documentable_type' => CustomerOffer::class,
            'documentable_id' => $offer->id,
            'tenant_id' => $tenant->id,
            'type' => 'invoice',
            'uploaded_by' => $user->id,
        ]);

        $document = Document::query()->withoutGlobalScopes()->first();
        $this->assertNotNull($document);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_documents_table_exposes_a_download_action(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'Customer A']);
        $offer = CustomerOffer::create([
            'tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'status' => 'draft', 'currency' => 'RON',
        ]);
        $this->actingAs(User::factory()->create());

        Storage::fake('local');
        $path = 'documents/'.$tenant->id.'/factura.pdf';
        Storage::disk('local')->put($path, 'PDF-1.4 fake');

        $document = $offer->documents()->create([
            'tenant_id' => $tenant->id,
            'type' => 'invoice',
            'file_path' => $path,
            'original_name' => 'factura.pdf',
        ]);

        Livewire::test(DocumentsRelationManager::class, [
            'ownerRecord' => $offer,
            'pageClass' => EditCustomerOffer::class,
        ])
            ->assertTableActionExists('download')
            ->callTableAction('download', $document)
            ->assertFileDownloaded('factura.pdf');
    }

    public function test_documents_attach_to_each_supported_parent_type(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A']);
        session(['tenant_id' => $tenant->id]);
        $user = User::factory()->create();
        $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => 'C']);
        $supplier = Supplier::create(['tenant_id' => $tenant->id, 'name' => 'S']);
        $supplierOffer = SupplierOffer::create(['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'status' => 'received']);
        $customerOffer = CustomerOffer::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'status' => 'draft', 'currency' => 'EUR']);
        $salesOrder = SalesOrder::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'status' => 'draft', 'currency' => 'EUR']);

        foreach ([$supplier, $customer, $supplierOffer, $customerOffer, $salesOrder] as $parent) {
            $parent->documents()->create([
                'tenant_id' => $tenant->id,
                'type' => 'other',
                'file_path' => 'documents/'.$tenant->id.'/'.class_basename($parent).'.pdf',
                'original_name' => class_basename($parent).'.pdf',
                'uploaded_by' => $user->id,
            ]);

            $this->assertCount(1, $parent->fresh()->documents);
        }

        $this->assertSame(5, Document::query()->count());
    }
}
