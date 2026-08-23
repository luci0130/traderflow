<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Models\Product;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component as LivewireComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Turiac\FilamentImageGallery\Forms\Components\ImageGallery;
use Turiac\FilamentImageGallery\Services\GalleryPersister;
use Turiac\FilamentImageGallery\Services\ImageProcessor;
use Turiac\FilamentImageGallery\Services\RemoteImageDownloader;

use function setPermissionsTeamId;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_plugin_tables_have_polymorphic_gallery_metadata_and_role_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('image_galleries', [
            'owner_type',
            'owner_id',
            'collection',
        ]));
        $this->assertTrue(Schema::hasColumns('image_gallery_images', [
            'gallery_id',
            'uploader_type',
            'uploader_id',
            'disk',
            'path',
            'title',
            'alt_text',
            'mime_type',
            'size',
            'original_size',
            'width',
            'height',
            'focal_x',
            'focal_y',
            'sort_order',
        ]));
        $this->assertTrue(Schema::hasColumns('image_gallery_roles', ['gallery_id', 'image_id', 'role']));
        $this->assertFalse(Schema::hasTable('product_images'));
    }

    public function test_role_assignment_transfers_to_the_last_selected_image_and_main_is_restored(): void
    {
        $field = ImageGallery::make('images')->roles([
            'main' => 'Main',
            'thumbnail' => 'Thumbnail',
        ]);

        $state = $field->normalizeRoles([
            'first' => ['roles' => ['main', 'thumbnail']],
            'second' => ['roles' => ['main']],
        ]);

        $this->assertSame(['thumbnail'], $state['first']['roles']);
        $this->assertSame(['main'], $state['second']['roles']);

        $withoutMain = $field->normalizeRoles([
            'first' => ['roles' => ['thumbnail']],
            'second' => ['roles' => []],
        ]);

        $this->assertSame(['thumbnail', 'main'], $withoutMain['first']['roles']);

        $limitedField = ImageGallery::make('limited_images')->maxItems(1);
        $fullGallery = $limitedField->withUploaderItem([
            'only-image' => ['path' => 'products/front.jpg', 'roles' => ['main']],
        ]);

        $this->assertCount(1, $fullGallery);
        $this->assertFalse((bool) ($fullGallery['only-image']['_is_uploader'] ?? false));
        $this->assertSame(4, ImageGallery::make('parallel_images')->getMaximumParallelUploads());
        $this->assertNotContains('focal-point', ImageGallery::make('default_tools')->getTools());
        $this->assertContains(
            'focal-point',
            ImageGallery::make('custom_tools')->tools(['information', 'focal-point'])->getTools(),
        );
    }

    public function test_disabled_uploader_promotion_is_a_no_op(): void
    {
        $field = ImageGallery::make('images')->addable(false);
        $field->container(FilamentSchema::make(new class extends LivewireComponent implements HasSchemas
        {
            use InteractsWithSchemas;
        }));
        $state = ['uploader' => [
            '_is_uploader' => true,
            'path' => ['browser-controlled-value'],
            'roles' => [],
        ]];
        $field->rawState($state);

        $field->promotePendingUploaderFiles();

        $this->assertSame($state, $field->getRawState());
    }

    public function test_default_roles_must_exist_and_main_must_be_configured(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ImageGallery::make('images')
            ->roles(['thumbnail' => 'Thumbnail'])
            ->defaultRoles(['main'])
            ->getDefaultRoles();
    }

    public function test_gallery_persister_stores_metadata_roles_order_and_uploader(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user);
        $product = Product::create(['name' => 'Tomato']);

        $this->putFakeImage('products/front.jpg');
        $this->putFakeImage('products/back.jpg');

        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'products',
            state: [
                'front' => $this->stateFor('products/front.jpg', ['main', 'base']),
                'back' => $this->stateFor('products/back.jpg', ['thumbnail']),
            ],
            configuredRoles: ['main' => 'Main', 'base' => 'Base', 'thumbnail' => 'Thumbnail'],
            disk: 'public',
            visibility: 'public',
        );

        $gallery = $product->imageGallery('products');
        $this->assertNotNull($gallery);
        $this->assertSame(['products/front.jpg', 'products/back.jpg'], $gallery->images->pluck('path')->all());
        $this->assertEqualsCanonicalizing(['main', 'base'], $gallery->images->first()->roles->pluck('role')->all());
        $this->assertTrue($gallery->images->first()->uploader->is($user));
        $this->assertSame('products/front.jpg', $product->fresh()->display_image_path);
    }

    public function test_saving_unchanged_gallery_state_does_not_rewrite_image_metadata(): void
    {
        Storage::fake('public');
        $product = Product::create(['name' => 'Tomato']);
        $this->putFakeImage('products/front.jpg');
        $persister = app(GalleryPersister::class);

        $persister->persist(
            owner: $product,
            collection: 'products',
            state: ['front' => $this->stateFor('products/front.jpg', ['main'])],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        $originalUpdatedAt = $product->imageGallery('products')->images->first()->updated_at;
        $state = $persister->stateFor($product, 'products');
        $this->travel(1)->second();

        $persister->persist(
            owner: $product,
            collection: 'products',
            state: $state,
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        $this->assertTrue(
            $product->imageGallery('products')->images()->firstOrFail()->updated_at->equalTo($originalUpdatedAt),
        );
    }

    public function test_unsigned_browser_path_is_rejected_without_deleting_the_file(): void
    {
        Storage::fake('public');
        $product = Product::create(['name' => 'Tomato']);
        $this->putFakeImage('image-gallery/victim.jpg');

        try {
            app(GalleryPersister::class)->persist(
                owner: $product,
                collection: 'products',
                state: ['hostile' => $this->stateFor('image-gallery/victim.jpg', ['main'])],
                configuredRoles: ['main' => 'Main'],
                disk: 'public',
                visibility: 'public',
                directory: 'image-gallery',
                statePath: 'data.images',
            );

            $this->fail('The unsigned path should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.images', $exception->errors());
        }

        Storage::disk('public')->assertExists('image-gallery/victim.jpg');
        $this->assertDatabaseMissing('image_gallery_images', ['path' => 'image-gallery/victim.jpg']);
    }

    public function test_file_claimed_by_another_gallery_cannot_be_adopted_even_as_a_trusted_import(): void
    {
        Storage::fake('public');
        $owner = Product::create(['name' => 'Owner']);
        $attacker = Product::create(['name' => 'Attacker']);
        $path = 'legacy/shared.jpg';
        $this->putFakeImage($path);
        $persister = app(GalleryPersister::class);

        $persister->persist(
            owner: $owner,
            collection: 'products',
            state: ['owned' => $this->stateFor($path, ['main'])],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
            directory: 'products',
            trustedPaths: [$path],
        );

        try {
            $persister->persist(
                owner: $attacker,
                collection: 'products',
                state: ['stolen' => $this->stateFor($path, ['main'])],
                configuredRoles: ['main' => 'Main'],
                disk: 'public',
                visibility: 'public',
                directory: 'products',
                trustedPaths: [$path],
            );

            $this->fail('A path may only be owned by one gallery.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('images', $exception->errors());
        }

        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseCount('image_gallery_images', 1);
    }

    public function test_prune_command_is_a_dry_run_unless_delete_is_requested(): void
    {
        Storage::fake('public');
        $referencedPath = 'image-gallery/11111111-1111-4111-8111-111111111111.jpg';
        $orphanPath = 'image-gallery/22222222-2222-4222-8222-222222222222.jpg';
        $this->putFakeImage($referencedPath);
        $this->putFakeImage($orphanPath);
        $product = Product::create(['name' => 'Product']);

        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'products',
            state: ['image' => $this->stateFor($referencedPath, ['main'])],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
            directory: 'image-gallery',
            trustedPaths: [$referencedPath],
        );

        $this->artisan('image-gallery:prune')->assertSuccessful();
        Storage::disk('public')->assertExists($orphanPath);

        $this->artisan('image-gallery:prune', ['--delete' => true])->assertSuccessful();
        Storage::disk('public')->assertMissing($orphanPath);
        Storage::disk('public')->assertExists($referencedPath);
    }

    public function test_gallery_cannot_be_saved_without_main_or_with_duplicate_roles(): void
    {
        Storage::fake('public');
        $product = Product::create(['name' => 'Tomato']);
        $this->putFakeImage('products/front.jpg');

        $this->expectException(ValidationException::class);

        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'products',
            state: ['front' => $this->stateFor('products/front.jpg', ['base'])],
            configuredRoles: ['main' => 'Main', 'base' => 'Base'],
            disk: 'public',
            visibility: 'public',
        );
    }

    public function test_deleting_an_image_on_parent_save_removes_its_file_and_assigns_main_to_the_first_remaining_image(): void
    {
        Storage::fake('public');
        $product = Product::create(['name' => 'Tomato']);
        $this->putFakeImage('products/front.jpg');
        $this->putFakeImage('products/back.jpg');
        $persister = app(GalleryPersister::class);

        $persister->persist(
            owner: $product,
            collection: 'products',
            state: [
                'front' => $this->stateFor('products/front.jpg', ['main']),
                'back' => $this->stateFor('products/back.jpg'),
            ],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        $storedState = $persister->stateFor($product, 'products');
        $remainingKey = array_key_last($storedState);
        $remaining = ImageGallery::make('images')->normalizeRoles([
            $remainingKey => $storedState[$remainingKey],
        ]);

        $persister->persist(
            owner: $product,
            collection: 'products',
            state: $remaining,
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        Storage::disk('public')->assertMissing('products/front.jpg');
        Storage::disk('public')->assertExists('products/back.jpg');
        $this->assertSame('products/back.jpg', $product->fresh()->imageForRole('main', 'products')->path);
    }

    public function test_image_processor_converts_uploads_to_webp(): void
    {
        Storage::fake('public');
        $upload = UploadedFile::fake()->image('transparent.png', 640, 480);

        $processed = app(ImageProcessor::class)->process(
            file: $upload,
            disk: 'public',
            directory: 'products',
            visibility: 'public',
            optimize: true,
            quality: 82,
            maxSizeKilobytes: 10240,
            maxWidth: 5000,
            maxHeight: 5000,
            targetWidth: 320,
        );

        $this->assertSame('image/webp', $processed->mimeType);
        $this->assertSame(320, $processed->width);
        $this->assertSame(240, $processed->height);
        $this->assertStringEndsWith('.webp', $processed->path);
        Storage::disk('public')->assertExists($processed->path);
    }

    public function test_image_processor_converts_palette_png_images_to_webp(): void
    {
        Storage::fake('public');
        $upload = UploadedFile::fake()->createWithContent(
            'palette.png',
            file_get_contents(public_path('apple-touch-icon.png')),
        );

        $processed = app(ImageProcessor::class)->process(
            file: $upload,
            disk: 'public',
            directory: 'products',
            visibility: 'public',
            optimize: true,
            quality: 82,
            maxSizeKilobytes: 10240,
            maxWidth: 5000,
            maxHeight: 5000,
        );

        $this->assertSame('image/webp', $processed->mimeType);
        $this->assertSame(166, $processed->width);
        $this->assertSame(166, $processed->height);
        Storage::disk('public')->assertExists($processed->path);
    }

    public function test_image_processor_resizes_without_forcing_webp_when_optimization_is_disabled(): void
    {
        Storage::fake('public');
        $upload = UploadedFile::fake()->image('transparent.png', 400, 200);

        $processed = app(ImageProcessor::class)->process(
            file: $upload,
            disk: 'public',
            directory: 'products',
            visibility: 'public',
            optimize: false,
            quality: 82,
            maxSizeKilobytes: 10240,
            maxWidth: 5000,
            maxHeight: 5000,
            targetHeight: 100,
        );

        $this->assertSame('image/png', $processed->mimeType);
        $this->assertSame(200, $processed->width);
        $this->assertSame(100, $processed->height);
        $this->assertStringEndsWith('.png', $processed->path);
        Storage::disk('public')->assertExists($processed->path);
    }

    public function test_filament_product_form_saves_multiple_optimized_images_with_default_roles(): void
    {
        Storage::fake('public');
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();

        Filament::setCurrentPanel('admin');
        setPermissionsTeamId($tenant->getKey());
        $role = Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->getKey(),
        ]);
        $tenant->users()->attach($user, ['role' => 'super_admin']);
        $user->assignRole($role);
        session(['tenant_id' => $tenant->getKey()]);
        $this->actingAs($user);

        $component = Livewire::test(CreateProduct::class);
        $uploaderKey = collect($component->get('data.images'))
            ->search(fn (array $item): bool => (bool) ($item['_is_uploader'] ?? false));

        $this->assertIsString($uploaderKey);

        $component
            ->set("data.images.{$uploaderKey}.path", [
                UploadedFile::fake()->image('front.jpg', 600, 400),
                UploadedFile::fake()->image('back.png', 600, 400),
            ]);

        $this->assertCount(
            0,
            collect($component->get('data.images'))
                ->reject(fn (array $item): bool => (bool) ($item['_is_uploader'] ?? false)),
        );

        $component->call('callSchemaComponentMethod', 'form.images', 'promotePendingUploaderFiles');

        $imageState = collect($component->get('data.images'))
            ->reject(fn (array $item): bool => (bool) ($item['_is_uploader'] ?? false));
        $frontKey = $imageState->keys()->first();
        $backKey = $imageState->keys()->last();

        $component
            ->mountAction(
                TestAction::make('editImage')
                    ->schemaComponent('images')
                    ->arguments(['item' => $frontKey]),
            )
            ->assertActionDataSet(function (array $data): array {
                $this->assertStringStartsWith('http', array_values($data['path'])[0]);
                $this->assertSame(600, $data['width']);
                $this->assertSame(400, $data['height']);
                $this->assertSame('image/jpeg', $data['mime_type']);

                return [];
            })
            ->callMountedAction();

        $this->assertInstanceOf(
            TemporaryUploadedFile::class,
            array_values($component->get("data.images.{$frontKey}.path"))[0],
        );

        $component
            ->set("data.images.{$backKey}.alt_text", 'Back of product')
            ->fillForm([
                'name' => 'Tomato',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Tomato')->firstOrFail();
        $images = $product->imageGallery('products')->images;

        $this->assertCount(2, $images);
        $this->assertEqualsCanonicalizing(
            ['main', 'base', 'small', 'thumbnail'],
            $images->first()->roles->pluck('role')->all(),
        );
        $this->assertSame('front', $images->first()->alt_text);
        $this->assertSame('Back of product', $images->last()->alt_text);
        $this->assertTrue($images->every(fn ($image): bool => $image->mime_type === 'image/webp'));
        $this->assertTrue($images->every(fn ($image): bool => str_ends_with($image->path, '.webp')));
    }

    public function test_remote_downloader_rejects_private_addresses(): void
    {
        $this->expectException(RuntimeException::class);

        app(RemoteImageDownloader::class)->download('https://127.0.0.1/image.jpg', 1024);
    }

    public function test_existing_image_opens_in_the_editor_modal_with_position_and_metadata(): void
    {
        Storage::fake('public');
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        Filament::setCurrentPanel('admin');
        setPermissionsTeamId($tenant->getKey());
        $role = Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->getKey(),
        ]);
        $tenant->users()->attach($user, ['role' => 'super_admin']);
        $user->assignRole($role);
        session(['tenant_id' => $tenant->getKey()]);
        $this->actingAs($user);

        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Tomato']);
        $this->putFakeImage('products/front.jpg');
        $this->putFakeImage('products/back.jpg');
        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'products',
            state: [
                'front' => $this->stateFor('products/front.jpg', ['main']),
                'back' => $this->stateFor('products/back.jpg'),
            ],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );
        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $imageKeys = collect($component->get('data.images'))
            ->filter(fn (mixed $item): bool => is_array($item) && ! ($item['_is_uploader'] ?? false))
            ->keys()
            ->values();
        $itemKey = $imageKeys->first();
        $nextItemKey = $imageKeys->last();

        $this->assertSame(1, substr_count($component->html(), 'fileUploadFormComponent({'));

        $component
            ->assertSeeHtml('fi-fo-image-gallery-uploader')
            ->mountAction(
                TestAction::make('editImage')
                    ->schemaComponent('images')
                    ->arguments(['item' => $itemKey]),
            )
            ->assertMountedActionModalSee('Image details · 1 of 2')
            ->assertMountedActionModalSeeHtml('data-image-gallery-editor-preview')
            ->assertMountedActionModalSeeHtml('data-image-gallery-editor-file-details')
            ->assertMountedActionModalSeeHtml('data-image-gallery-editor-storage-details')
            ->assertMountedActionModalSeeHtml('data-image-gallery-editor-thumbnails')
            ->assertMountedActionModalSeeHtml('data-image-gallery-resize')
            ->assertMountedActionModalSeeHtml('data-image-gallery-resize-state="width"')
            ->assertMountedActionModalSeeHtml('data-image-gallery-resize-state="height"')
            ->assertMountedActionModalSee('Add replacement image')
            ->assertMountedActionModalSee('Add from URL')
            ->assertMountedActionModalDontSee('Replace this image while preserving its title, alt text, roles, and position.')
            ->assertMountedActionModalSee('Aspect ratio locked')
            ->assertMountedActionModalDontSeeHtml([
                'data-image-gallery-focal-axis',
                'data-image-gallery-focal-marker',
                'image-gallery-focal-point',
            ])
            ->assertMountedActionModalSee('front.jpg')
            ->assertMountedActionModalSee('image/jpeg')
            ->callMountedAction(['navigate' => 'next'])
            ->assertMountedActionModalSee('Image details · 2 of 2')
            ->assertMountedActionModalSee('back.jpg')
            ->setActionData([
                'roles' => ['main'],
                'replacement_upload' => UploadedFile::fake()->image('replacement.png', 320, 240),
            ])
            ->callMountedAction();

        $state = $component->get('data.images');
        $this->assertNotContains('main', $state[$itemKey]['roles']);
        $this->assertContains('main', $state[$nextItemKey]['roles']);
        $this->assertInstanceOf(
            TemporaryUploadedFile::class,
            array_values($state[$nextItemKey]['path'])[0],
        );
        $this->assertSame('replacement.png', $state[$nextItemKey]['original_filename']);
        $this->assertSame('image/png', $state[$nextItemKey]['mime_type']);
        $this->assertSame(320, $state[$nextItemKey]['width']);
        $this->assertSame(240, $state[$nextItemKey]['height']);
        $this->assertSame('back', $state[$nextItemKey]['title']);
        $this->assertSame('back', $state[$nextItemKey]['alt_text']);
        $this->assertMatchesRegularExpression(
            '/data-gallery-item-frame="'.preg_quote($nextItemKey, '/').'"[^>]*data-main="true"/',
            $component->html(),
        );

        $component
            ->mountAction(
                TestAction::make('editImage')
                    ->schemaComponent('images')
                    ->arguments(['item' => $nextItemKey]),
            )
            ->setActionData(['replacement_url' => 'http://example.com/image.jpg'])
            ->callMountedAction()
            ->assertHasActionErrors(['replacement_url']);

        $component
            ->mountAction(
                TestAction::make('editImage')
                    ->schemaComponent('images')
                    ->arguments(['item' => 'missing-image']),
            )
            ->callMountedAction();
    }

    public function test_parent_save_resizes_an_existing_image_and_preserves_its_repeater_key(): void
    {
        Storage::fake('public');
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        Filament::setCurrentPanel('admin');
        setPermissionsTeamId($tenant->getKey());
        $role = Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->getKey(),
        ]);
        $tenant->users()->attach($user, ['role' => 'super_admin']);
        $user->assignRole($role);
        session(['tenant_id' => $tenant->getKey()]);
        $this->actingAs($user);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tomato',
            'status' => 'active',
        ]);
        $upload = UploadedFile::fake()->image('front.jpg', 400, 200);
        Storage::disk('public')->put('products/front.jpg', $upload->getContent());
        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'products',
            state: ['front' => $this->stateFor('products/front.jpg', ['main'])],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $itemKey = collect($component->get('data.images'))
            ->filter(fn (mixed $item): bool => is_array($item) && ! ($item['_is_uploader'] ?? false))
            ->keys()
            ->first();

        config(['filesystems.disks.malicious' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/malicious'),
            'url' => 'https://malicious.test/files',
            'throw' => false,
        ]]);
        Storage::fake('malicious');
        Storage::disk('malicious')->put('products/front.jpg', 'not-an-image');

        $component
            ->set("data.images.{$itemKey}.disk", 'malicious')
            ->mountAction(
                TestAction::make('editImage')
                    ->schemaComponent('images')
                    ->arguments(['item' => $itemKey]),
            )
            ->assertMountedActionModalSeeHtml('src="/storage/products/front.jpg"')
            ->callMountedAction()
            ->set("data.images.{$itemKey}.resize_width", 200)
            ->call('save')
            ->assertHasNoFormErrors();

        $savedState = $component->get('data.images');
        $savedImage = $product->fresh()->imageGallery('products')->images()->firstOrFail();

        $this->assertSame(
            $itemKey,
            collect($savedState)
                ->filter(fn (mixed $item): bool => is_array($item) && ! ($item['_is_uploader'] ?? false))
                ->keys()
                ->first(),
        );
        $this->assertSame(200, $savedImage->width);
        $this->assertSame(100, $savedImage->height);
        $this->assertSame('front.jpg', $savedImage->original_filename);
        $this->assertSame('front', $savedImage->title);
        $this->assertSame('front', $savedImage->alt_text);
        $this->assertSame(['main'], $savedImage->roles->pluck('role')->all());
        $this->assertNotSame('products/front.jpg', $savedImage->path);
        Storage::disk('public')->assertMissing('products/front.jpg');
        Storage::disk('public')->assertExists($savedImage->path);

        $component
            ->mountAction(
                TestAction::make('editImage')
                    ->schemaComponent('images')
                    ->arguments(['item' => $itemKey]),
            )
            ->assertMountedActionModalSee('200 × 100')
            ->callMountedAction()
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('image_gallery_images', 1);
    }

    public function test_reordering_images_ignores_unknown_sortable_items_and_keeps_the_uploader_state_valid(): void
    {
        Storage::fake('public');
        $tenant = Tenant::create(['name' => 'Tenant A']);
        $user = User::factory()->create();
        Filament::setCurrentPanel('admin');
        setPermissionsTeamId($tenant->getKey());
        $role = Role::create([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'tenant_id' => $tenant->getKey(),
        ]);
        $tenant->users()->attach($user, ['role' => 'super_admin']);
        $user->assignRole($role);
        session(['tenant_id' => $tenant->getKey()]);
        $this->actingAs($user);

        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Tomato']);
        $this->putFakeImage('products/front.jpg');
        $this->putFakeImage('products/back.jpg');
        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'products',
            state: [
                'front' => $this->stateFor('products/front.jpg', ['main']),
                'back' => $this->stateFor('products/back.jpg'),
            ],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $initialState = $component->get('data.images');
        $imageKeys = collect($initialState)
            ->filter(fn (mixed $item): bool => is_array($item) && ! ($item['_is_uploader'] ?? false))
            ->keys()
            ->values();
        $uploaderKey = collect($initialState)
            ->search(fn (mixed $item): bool => is_array($item) && ($item['_is_uploader'] ?? false));

        $component
            ->callAction(
                TestAction::make('reorder')->schemaComponent('images'),
                arguments: ['items' => [$imageKeys[1], 0, $imageKeys[0]]],
            )
            ->assertHasNoErrors();

        $reorderedState = $component->get('data.images');
        $reorderedImageKeys = collect($reorderedState)
            ->filter(fn (mixed $item): bool => is_array($item) && ! ($item['_is_uploader'] ?? false))
            ->keys()
            ->values()
            ->all();

        $this->assertSame([$imageKeys[1], $imageKeys[0]], $reorderedImageKeys);
        $this->assertSame($uploaderKey, array_key_last($reorderedState));
        $this->assertContainsOnly('array', $reorderedState);
    }

    public function test_deleting_a_product_removes_gallery_and_legacy_files(): void
    {
        Storage::fake('public');
        $product = Product::create(['name' => 'Tomato', 'image_path' => 'products/legacy.jpg']);
        $this->putFakeImage('products/legacy.jpg');
        $this->putFakeImage('products/front.jpg');

        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'products',
            state: ['front' => $this->stateFor('products/front.jpg', ['main'])],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        $product->delete();

        $this->assertDatabaseCount('image_galleries', 0);
        Storage::disk('public')->assertMissing('products/front.jpg');
        Storage::disk('public')->assertMissing('products/legacy.jpg');
    }

    public function test_soft_delete_preserves_gallery_files_until_force_delete(): void
    {
        Storage::fake('public');
        Schema::table('products', fn (Blueprint $table) => $table->softDeletes());
        $product = SoftDeletingProduct::create(['name' => 'Tomato']);
        $this->putFakeImage('products/front.jpg');

        app(GalleryPersister::class)->persist(
            owner: $product,
            collection: 'products',
            state: ['front' => $this->stateFor('products/front.jpg', ['main'])],
            configuredRoles: ['main' => 'Main'],
            disk: 'public',
            visibility: 'public',
        );

        $product->delete();

        $this->assertDatabaseCount('image_galleries', 1);
        Storage::disk('public')->assertExists('products/front.jpg');

        $product->forceDelete();

        $this->assertDatabaseCount('image_galleries', 0);
        Storage::disk('public')->assertMissing('products/front.jpg');
    }

    /**
     * @param  array<string>  $roles
     * @return array<string, mixed>
     */
    private function stateFor(string $path, array $roles = []): array
    {
        return [
            'path' => $path,
            'original_filename' => basename($path),
            'title' => pathinfo($path, PATHINFO_FILENAME),
            'alt_text' => null,
            'roles' => $roles,
            'focal_x' => null,
            'focal_y' => null,
        ];
    }

    private function putFakeImage(string $path): void
    {
        $upload = UploadedFile::fake()->image(basename($path), 100, 100);
        Storage::disk('public')->put($path, $upload->getContent());
    }
}

class SoftDeletingProduct extends Product
{
    use SoftDeletes;

    protected $table = 'products';
}
