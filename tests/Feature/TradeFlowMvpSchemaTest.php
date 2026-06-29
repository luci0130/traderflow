<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\CustomerOffers\Models\CustomerOfferItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Documents\Models\Document;
use App\Modules\Emails\Models\Email;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Modules\Products\Models\Product;
use App\Modules\SalesOrders\Models\SalesOrder;
use App\Modules\SalesOrders\Models\SalesOrderItem;
use App\Modules\SupplierOffers\Models\SupplierOffer;
use App\Modules\SupplierOffers\Models\SupplierOfferItem;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\TenantSettings\Models\CustomField;
use App\Modules\TenantSettings\Models\CustomFieldValue;
use App\Modules\TenantSettings\Models\TenantSetting;
use App\Modules\Units\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TradeFlowMvpSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_mvp_tables_have_the_required_columns(): void
    {
        $tables = [
            'tenants' => ['id', 'name', 'legal_name', 'vat_number', 'registration_number', 'email', 'phone', 'website', 'address', 'city', 'country', 'currency', 'logo', 'is_active', 'created_at', 'updated_at'],
            'tenant_user' => ['tenant_id', 'user_id', 'role', 'created_at', 'updated_at'],
            'product_categories' => ['id', 'tenant_id', 'parent_id', 'name', 'status', 'created_at', 'updated_at'],
            'units' => ['id', 'tenant_id', 'name', 'symbol', 'created_at', 'updated_at'],
            'products' => ['id', 'tenant_id', 'product_category_id', 'unit_id', 'sku', 'name', 'description', 'status', 'created_by', 'created_at', 'updated_at'],
            'users' => ['id', 'name', 'email', 'phone', 'password', 'producer_id', 'created_at', 'updated_at'],
            'suppliers' => ['id', 'tenant_id', 'management_mode', 'is_producer', 'merged_producer_id', 'name', 'legal_name', 'vat_number', 'registration_number', 'email', 'phone', 'country', 'city', 'postal_code', 'address', 'contact_person', 'payment_terms', 'iban', 'bank_name', 'bank_swift', 'default_currency', 'invoice_prefix', 'invoice_starting_number', 'invoice_notes', 'logo_path', 'status', 'notes', 'created_at', 'updated_at'],
            'supplier_contacts' => ['id', 'supplier_id', 'user_id', 'name', 'role_in_company', 'email', 'phone', 'is_primary', 'can_access_portal', 'created_at', 'updated_at'],
            'customers' => ['id', 'tenant_id', 'name', 'slug', 'logo', 'is_active', 'legal_name', 'vat_number', 'email', 'phone', 'country', 'city', 'address', 'contact_person', 'payment_terms', 'status', 'notes', 'created_at', 'updated_at'],
            'customer_contacts' => ['id', 'tenant_id', 'customer_id', 'name', 'role', 'email', 'phone', 'is_primary', 'notes', 'created_at', 'updated_at'],
            'supplier_offers' => ['id', 'tenant_id', 'supplier_id', 'offer_number', 'received_at', 'valid_until', 'currency', 'status', 'source_type', 'notes', 'created_by', 'created_at', 'updated_at'],
            'supplier_offer_items' => ['id', 'tenant_id', 'supplier_offer_id', 'product_id', 'unit_id', 'quantity', 'purchase_price', 'currency', 'availability_date', 'notes', 'created_at', 'updated_at'],
            'customer_offers' => ['id', 'tenant_id', 'customer_id', 'offer_number', 'offer_date', 'valid_until', 'currency', 'status', 'subtotal', 'tax_total', 'total', 'notes', 'email_subject', 'email_body', 'created_by', 'sent_at', 'created_at', 'updated_at'],
            'customer_offer_items' => ['id', 'tenant_id', 'customer_offer_id', 'product_id', 'supplier_id', 'supplier_offer_item_id', 'unit_id', 'quantity', 'purchase_price', 'sale_price', 'margin_value', 'margin_percent', 'tax_rate', 'line_total', 'notes', 'created_at', 'updated_at'],
            'sales_orders' => ['id', 'tenant_id', 'customer_offer_id', 'customer_id', 'order_number', 'order_date', 'delivery_date', 'currency', 'status', 'subtotal', 'tax_total', 'total', 'notes', 'created_by', 'created_at', 'updated_at'],
            'sales_order_items' => ['id', 'tenant_id', 'sales_order_id', 'product_id', 'supplier_id', 'unit_id', 'quantity', 'purchase_price', 'sale_price', 'margin_value', 'margin_percent', 'line_total', 'notes', 'created_at', 'updated_at'],
            'documents' => ['id', 'tenant_id', 'documentable_type', 'documentable_id', 'type', 'name', 'file_path', 'original_name', 'mime_type', 'size', 'uploaded_by', 'created_at', 'updated_at'],
            'emails' => ['id', 'tenant_id', 'related_type', 'related_id', 'to', 'cc', 'bcc', 'subject', 'body', 'status', 'sent_by', 'sent_at', 'error_message', 'created_at', 'updated_at'],
            'tenant_settings' => ['id', 'tenant_id', 'key', 'value', 'created_at', 'updated_at'],
            'custom_fields' => ['id', 'tenant_id', 'entity_type', 'name', 'key', 'type', 'options', 'is_required', 'sort_order', 'created_at', 'updated_at'],
            'custom_field_values' => ['id', 'tenant_id', 'custom_field_id', 'entity_type', 'entity_id', 'value', 'created_at', 'updated_at'],
        ];

        foreach ($tables as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Table [{$table}] does not exist.");
            $this->assertTrue(Schema::hasColumns($table, $columns), "Table [{$table}] is missing columns.");
        }
    }

    public function test_mvp_models_expose_core_relationships(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Trading']);
        $user = User::factory()->create();

        $tenant->users()->attach($user, ['role' => 'tenant_admin']);

        $category = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Legume',
        ]);
        $unit = Unit::create([
            'tenant_id' => $tenant->id,
            'name' => 'Kilogram',
            'symbol' => 'kg',
        ]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'product_category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'ROSII',
            'name' => 'Rosii',
            'created_by' => $user->id,
        ]);
        $supplier = Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Supplier A',
        ]);
        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer A',
        ]);

        $supplierOffer = SupplierOffer::create([
            'tenant_id' => $tenant->id,
            'supplier_id' => $supplier->id,
            'offer_number' => 'SO-1',
            'created_by' => $user->id,
        ]);
        $supplierOfferItem = SupplierOfferItem::create([
            'tenant_id' => $tenant->id,
            'supplier_offer_id' => $supplierOffer->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity' => 10,
            'purchase_price' => 4.2,
        ]);

        $customerOffer = CustomerOffer::create([
            'tenant_id' => $tenant->id,
            'customer_id' => $customer->id,
            'offer_number' => 'CO-1',
            'created_by' => $user->id,
        ]);
        $customerOfferItem = CustomerOfferItem::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $customerOffer->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'supplier_offer_item_id' => $supplierOfferItem->id,
            'unit_id' => $unit->id,
            'quantity' => 5,
            'purchase_price' => 4.2,
            'sale_price' => 5,
        ]);

        $salesOrder = SalesOrder::create([
            'tenant_id' => $tenant->id,
            'customer_offer_id' => $customerOffer->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-1',
            'created_by' => $user->id,
        ]);
        SalesOrderItem::create([
            'tenant_id' => $tenant->id,
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
            'unit_id' => $unit->id,
            'quantity' => 5,
            'purchase_price' => 4.2,
            'sale_price' => 5,
        ]);

        Document::create([
            'tenant_id' => $tenant->id,
            'documentable_type' => SupplierOffer::class,
            'documentable_id' => $supplierOffer->id,
            'type' => 'supplier_offer',
            'file_path' => 'documents/offer.pdf',
            'uploaded_by' => $user->id,
        ]);
        Email::create([
            'tenant_id' => $tenant->id,
            'related_type' => CustomerOffer::class,
            'related_id' => $customerOffer->id,
            'to' => 'buyer@example.com',
            'subject' => 'Oferta',
            'sent_by' => $user->id,
        ]);
        TenantSetting::create([
            'tenant_id' => $tenant->id,
            'key' => 'default_currency',
            'value' => 'EUR',
        ]);
        $customField = CustomField::create([
            'tenant_id' => $tenant->id,
            'entity_type' => Product::class,
            'name' => 'Calibru',
            'key' => 'calibru',
            'type' => 'text',
        ]);
        CustomFieldValue::create([
            'tenant_id' => $tenant->id,
            'custom_field_id' => $customField->id,
            'entity_type' => Product::class,
            'entity_id' => $product->id,
            'value' => 'M',
        ]);

        $this->assertTrue($user->tenants->contains($tenant));
        $this->assertTrue($tenant->products->contains($product));
        $this->assertSame($category->id, $product->category->id);
        $this->assertSame($unit->id, $product->unit->id);
        $this->assertSame($supplier->id, $supplierOffer->supplier->id);
        $this->assertTrue($supplierOffer->items->contains($supplierOfferItem));
        $this->assertTrue($customerOffer->items->contains($customerOfferItem));
        $this->assertSame($supplierOfferItem->id, $customerOfferItem->supplierOfferItem->id);
        $this->assertSame($salesOrder->id, $customerOffer->salesOrder->id);
        $this->assertCount(1, $salesOrder->items);
        $this->assertCount(1, $supplierOffer->documents);
        $this->assertCount(1, $tenant->emails);
        $this->assertCount(1, $tenant->settings);
        $this->assertCount(1, $customField->values);
        $this->assertSame($product->id, CustomFieldValue::firstOrFail()->entity->id);
    }
}
