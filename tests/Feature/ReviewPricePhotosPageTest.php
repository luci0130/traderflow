<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Supermarkets\Filament\Pages\ReviewPricePhotos;
use App\Modules\Supermarkets\Models\SupermarketPrice;
use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use App\Modules\Supermarkets\Models\SupermarketProduct;
use Database\Factories\SupermarketFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class ReviewPricePhotosPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Tenant A']);
        $this->user = User::factory()->create();
        $this->tenant->users()->attach($this->user);

        setPermissionsTeamId($this->tenant->getKey());
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'ViewAny:SupermarketPrice', 'guard_name' => 'web']),
        );

        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    public function test_page_is_registered_as_a_filament_route(): void
    {
        $this->assertTrue(Route::has('filament.admin.pages.supermarket-price-photos.review'));
    }

    public function test_mount_loads_the_next_pending_photo_and_marks_it_in_review(): void
    {
        $photo = SupermarketPricePhoto::factory()->create([
            'status' => SupermarketPricePhoto::STATUS_PENDING,
        ]);

        $component = Livewire::test(ReviewPricePhotos::class)
            ->assertSuccessful()
            ->assertSet('photoId', $photo->id);

        $entries = array_values($component->get('data.entries'));

        $this->assertSame(SupermarketProduct::DEFAULT_VAT_RATE, $entries[0]['vat_rate']);

        $this->assertSame(SupermarketPricePhoto::STATUS_IN_REVIEW, $photo->fresh()->status);
    }

    public function test_saving_creates_prices_marks_photo_done_and_advances(): void
    {
        $supermarket = SupermarketFactory::new()->create();
        $product = SupermarketProduct::factory()->create();
        $photo = SupermarketPricePhoto::factory()->create([
            'supermarket_id' => $supermarket->id,
            'taken_at' => '2026-02-01',
            'status' => SupermarketPricePhoto::STATUS_PENDING,
        ]);

        Livewire::test(ReviewPricePhotos::class)
            ->assertSet('photoId', $photo->id)
            ->set('data.entries', [
                [
                    'supermarket_product_id' => $product->id,
                    'price' => 7.49,
                    'is_promo' => true,
                    'promo_price' => 5.99,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('photoId', null);

        $this->assertSame(SupermarketPricePhoto::STATUS_DONE, $photo->fresh()->status);
        $this->assertDatabaseHas('supermarket_prices', [
            'supermarket_id' => $supermarket->id,
            'supermarket_product_id' => $product->id,
            'supermarket_price_photo_id' => $photo->id,
            'price' => 7.49,
            'is_promo' => true,
            'promo_price' => 5.99,
            'observed_at' => '2026-02-01 00:00:00',
            'source' => SupermarketPrice::SOURCE_PHOTO,
            'recorded_by' => $this->user->id,
        ]);
    }

    public function test_saving_re_renders_the_content_with_the_next_photo(): void
    {
        $supermarket = SupermarketFactory::new()->create();
        $product = SupermarketProduct::factory()->create();
        $first = SupermarketPricePhoto::factory()->create([
            'supermarket_id' => $supermarket->id,
            'status' => SupermarketPricePhoto::STATUS_PENDING,
            'path' => 'photos/first-shelf.jpg',
        ]);
        $second = SupermarketPricePhoto::factory()->create([
            'supermarket_id' => $supermarket->id,
            'status' => SupermarketPricePhoto::STATUS_PENDING,
            'path' => 'photos/second-shelf.jpg',
        ]);

        // The "Save & next" button submits the form, so the content schema is
        // built for the current photo while the submit is handled and would keep
        // being rendered unless it is rebuilt once the next photo loads. Assert
        // the rendered markup actually advances to the second photo's image.
        Livewire::test(ReviewPricePhotos::class)
            ->assertSet('photoId', $first->id)
            ->assertSee('first-shelf.jpg')
            ->set('data.entries', [
                [
                    'supermarket_product_id' => $product->id,
                    'price' => 4.20,
                    'is_promo' => false,
                ],
            ])
            ->call('save')
            ->assertSet('photoId', $second->id)
            ->assertSee('second-shelf.jpg')
            ->assertDontSee('first-shelf.jpg');
    }

    public function test_saving_creates_a_new_product_inline_when_no_existing_product_chosen(): void
    {
        $packagingMethod = PackagingMethod::query()->where('name', 'Cutie')->firstOrFail();
        $photo = SupermarketPricePhoto::factory()->create([
            'status' => SupermarketPricePhoto::STATUS_PENDING,
        ]);

        Livewire::test(ReviewPricePhotos::class)
            ->assertSet('photoId', $photo->id)
            ->set('data.entries', [
                [
                    'supermarket_product_id' => null,
                    'name' => 'Lapte Zuzu 3.5%',
                    'brand' => 'Zuzu',
                    'category' => 'Lactate',
                    'origin' => 'Romania',
                    'caliber' => 'Class I',
                    'quality' => 'Premium',
                    'packaging_method_id' => $packagingMethod->id,
                    'package_size' => 1,
                    'package_unit' => 'l',
                    'vat_rate' => 19,
                    'price' => 8.99,
                    'is_promo' => false,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('supermarket_products', [
            'name' => 'Lapte Zuzu 3.5%',
            'brand' => 'Zuzu',
            'category' => 'Lactate',
            'origin' => 'RO',
            'caliber' => 'Class I',
            'quality' => 'Premium',
            'packaging_method_id' => $packagingMethod->id,
            'package_unit' => 'l',
            'vat_rate' => 19,
        ]);

        $product = SupermarketProduct::query()->where('name', 'Lapte Zuzu 3.5%')->firstOrFail();

        $this->assertDatabaseHas('supermarket_prices', [
            'supermarket_product_id' => $product->id,
            'supermarket_price_photo_id' => $photo->id,
            'price' => 8.99,
            'source' => SupermarketPrice::SOURCE_PHOTO,
        ]);
    }

    public function test_non_promo_entry_does_not_store_a_promo_price(): void
    {
        $product = SupermarketProduct::factory()->create();
        $photo = SupermarketPricePhoto::factory()->create([
            'status' => SupermarketPricePhoto::STATUS_PENDING,
        ]);

        Livewire::test(ReviewPricePhotos::class)
            ->set('data.entries', [
                [
                    'supermarket_product_id' => $product->id,
                    'price' => 3.20,
                    'is_promo' => false,
                    'promo_price' => 1.00,
                ],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('supermarket_prices', [
            'supermarket_price_photo_id' => $photo->id,
            'is_promo' => false,
            'promo_price' => null,
        ]);
    }

    public function test_skip_reverts_photo_to_pending_and_loads_another(): void
    {
        $first = SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);
        $second = SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);

        Livewire::test(ReviewPricePhotos::class)
            ->assertSet('photoId', $first->id)
            ->call('skip')
            ->assertSet('photoId', $second->id);

        $this->assertSame(SupermarketPricePhoto::STATUS_PENDING, $first->fresh()->status);
    }

    public function test_delete_photo_removes_the_record(): void
    {
        $photo = SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);

        Livewire::test(ReviewPricePhotos::class)
            ->assertSet('photoId', $photo->id)
            ->call('deletePhoto')
            ->assertSet('photoId', null);

        $this->assertDatabaseMissing('supermarket_price_photos', ['id' => $photo->id]);
    }

    public function test_a_supplier_lead_can_be_added_from_the_review_page(): void
    {
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'Create:SupplierLead', 'guard_name' => 'web']),
        );

        $product = SupermarketProduct::factory()->create();
        SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);

        Livewire::test(ReviewPricePhotos::class)
            ->callAction('addSupplierLead', data: [
                'name' => 'Ferma Bio Ardeal',
                'country' => 'România',
                'website' => 'https://ferma-ardeal.test',
                'email' => 'contact@ferma-ardeal.test',
                'phone' => '+40 745 123 456',
                'notes' => 'Met at the local market, sells organic tomatoes.',
                'supermarket_product_id' => $product->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('supplier_leads', [
            'name' => 'Ferma Bio Ardeal',
            'country' => 'România',
            'website' => 'https://ferma-ardeal.test',
            'email' => 'contact@ferma-ardeal.test',
            'phone' => '+40 745 123 456',
            'supermarket_product_id' => $product->id,
            'created_by' => $this->user->id,
            'converted_supplier_id' => null,
        ]);
    }

    public function test_the_supplier_lead_modal_pre_fills_the_product_being_reviewed(): void
    {
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'Create:SupplierLead', 'guard_name' => 'web']),
        );

        $product = SupermarketProduct::factory()->create();
        SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);

        Livewire::test(ReviewPricePhotos::class)
            ->set('data.entries', [
                ['supermarket_product_id' => $product->id, 'price' => 3.50, 'is_promo' => false],
            ])
            ->mountAction('addSupplierLead')
            ->assertActionDataSet(['supermarket_product_id' => $product->id]);
    }

    public function test_a_supplier_lead_can_be_added_from_under_the_add_product_button(): void
    {
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'Create:SupplierLead', 'guard_name' => 'web']),
        );

        SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);

        Livewire::test(ReviewPricePhotos::class)
            ->callAction(
                TestAction::make('addSupplierLeadInline')->schemaComponent('supplier-lead-actions'),
                data: [
                    'name' => 'Gradina Verde SRL',
                    'email' => 'office@gradina-verde.test',
                ],
            )
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('supplier_leads', [
            'name' => 'Gradina Verde SRL',
            'email' => 'office@gradina-verde.test',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_reopening_the_supplier_lead_modal_restores_the_retained_draft(): void
    {
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'Create:SupplierLead', 'guard_name' => 'web']),
        );

        SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);

        Livewire::test(ReviewPricePhotos::class)
            ->set('supplierLeadDraft', [
                'name' => 'Half Filled SRL',
                'country' => 'România',
                'website' => null,
                'email' => 'partial@lead.test',
                'phone' => null,
                'notes' => null,
            ])
            ->mountAction('addSupplierLead')
            ->assertActionDataSet([
                'name' => 'Half Filled SRL',
                'country' => 'România',
                'email' => 'partial@lead.test',
            ]);
    }

    public function test_saving_a_supplier_lead_clears_the_retained_draft(): void
    {
        $this->user->givePermissionTo(
            Permission::firstOrCreate(['name' => 'Create:SupplierLead', 'guard_name' => 'web']),
        );

        SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);

        Livewire::test(ReviewPricePhotos::class)
            ->set('supplierLeadDraft', ['name' => 'Leftover SRL'])
            ->mountAction('addSupplierLead')
            ->setActionData(['name' => 'Final Name SRL'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertSet('supplierLeadDraft', []);

        $this->assertDatabaseHas('supplier_leads', ['name' => 'Final Name SRL']);
    }

    public function test_navigation_badge_counts_photos_awaiting_review(): void
    {
        SupermarketPricePhoto::factory()->count(2)->create(['status' => SupermarketPricePhoto::STATUS_PENDING]);
        SupermarketPricePhoto::factory()->create(['status' => SupermarketPricePhoto::STATUS_IN_REVIEW]);
        SupermarketPricePhoto::factory()->done()->create();

        $this->assertSame('3', ReviewPricePhotos::getNavigationBadge());
    }
}
