<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Models\CustomerLocation;
use App\Modules\Supermarkets\Filament\Pages\UploadPricePhotos;
use App\Modules\Supermarkets\Models\SupermarketPricePhoto;
use Database\Factories\SupermarketFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

use function setPermissionsTeamId;

class UploadPricePhotosPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

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

    public function test_uploading_photos_creates_one_pending_record_per_file(): void
    {
        $supermarket = SupermarketFactory::new()->create();
        $location = CustomerLocation::factory()->create([
            'customer_id' => $supermarket->id,
            'name' => 'Carrefour Vivo',
            'city' => 'Cluj-Napoca',
            'address' => 'Strada Avram Iancu 492',
        ]);

        Livewire::test(UploadPricePhotos::class)
            ->assertSuccessful()
            ->set('data.supermarket_id', $supermarket->id)
            ->set('data.customer_location_id', $location->id)
            ->set('data.taken_at', '2026-03-01')
            ->set('data.photos', [
                UploadedFile::fake()->image('shelf-1.jpg'),
                UploadedFile::fake()->image('shelf-2.jpg'),
            ])
            ->call('storePhotos')
            ->assertHasNoErrors();

        $this->assertSame(2, SupermarketPricePhoto::query()->count());

        $photo = SupermarketPricePhoto::query()->first();
        $this->assertSame($supermarket->id, $photo->supermarket_id);
        $this->assertSame($location->id, $photo->customer_location_id);
        $this->assertSame($this->user->id, $photo->uploaded_by);
        $this->assertSame('Carrefour Vivo - Cluj-Napoca - Strada Avram Iancu 492', $photo->store_label);
        $this->assertSame(SupermarketPricePhoto::STATUS_PENDING, $photo->status);
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_supermarket_is_required(): void
    {
        Livewire::test(UploadPricePhotos::class)
            ->set('data.photos', [UploadedFile::fake()->image('shelf.jpg')])
            ->call('storePhotos')
            ->assertHasErrors(['data.supermarket_id']);

        $this->assertSame(0, SupermarketPricePhoto::query()->count());
    }

    public function test_a_store_location_can_be_created_inline_for_the_selected_supermarket(): void
    {
        $supermarket = SupermarketFactory::new()->create();

        Livewire::test(UploadPricePhotos::class)
            ->set('data.supermarket_id', $supermarket->id)
            ->callAction(
                TestAction::make('createOption')->schemaComponent('customer_location_id'),
                data: [
                    'name' => 'Carrefour Iulius',
                    'type' => 'supermarket',
                    'county' => 'Cluj',
                    'city' => 'Cluj-Napoca',
                    'address' => 'Strada Alexandru Vaida Voevod 53B',
                ],
            )
            ->assertHasNoErrors();

        $location = CustomerLocation::query()->where('customer_id', $supermarket->id)->first();

        $this->assertNotNull($location);
        $this->assertSame('Carrefour Iulius', $location->name);
        $this->assertSame($supermarket->tenant_id, $location->tenant_id);
        $this->assertSame('Cluj-Napoca', $location->city);
    }

    public function test_location_must_belong_to_the_selected_supermarket(): void
    {
        $supermarket = SupermarketFactory::new()->create();
        $otherSupermarket = SupermarketFactory::new()->create();
        $otherLocation = CustomerLocation::factory()->create([
            'customer_id' => $otherSupermarket->id,
        ]);

        Livewire::test(UploadPricePhotos::class)
            ->set('data.supermarket_id', $supermarket->id)
            ->set('data.customer_location_id', $otherLocation->id)
            ->set('data.photos', [UploadedFile::fake()->image('shelf.jpg')])
            ->call('storePhotos')
            ->assertHasErrors(['data.customer_location_id']);

        $this->assertSame(0, SupermarketPricePhoto::query()->count());
    }
}
