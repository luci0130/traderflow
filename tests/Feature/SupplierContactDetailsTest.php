<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Suppliers\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Modules\Suppliers\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Support\SupplierPhoneNumber;
use App\Support\Countries;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['name' => 'Supplier contact tests']);
        $user = User::factory()->create();
        $tenant->users()->attach($user);
        setPermissionsTeamId(null);
        $user->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'tenant_id' => null]));
        $this->actingAs($user);
        session(['tenant_id' => $tenant->id]);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
    }

    public function test_country_and_prefix_are_searchable_selects_with_flag_images(): void
    {
        $page = Livewire::test(CreateSupplier::class);

        foreach (['country', 'phone_country'] as $field) {
            $page->assertFormFieldExists($field, function (Select $select): bool {
                $this->assertTrue($select->isSearchable());
                $this->assertFalse($select->isNative());
                $this->assertTrue($select->isHtmlAllowed());
                $this->assertStringContainsString('https://flagcdn.com/ro.svg', $select->getOptions()['RO']);
                $this->assertStringContainsString('Romania (RO)', $select->getOptions()['RO']);

                return true;
            });
        }
    }

    public function test_supplier_can_be_created_without_country_or_phone(): void
    {
        Livewire::test(CreateSupplier::class)
            ->fillForm(['name' => 'Optional contacts'])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->where('name', 'Optional contacts')->firstOrFail();
        $this->assertNull($supplier->country);
        $this->assertNull($supplier->phone);
        $this->assertArrayNotHasKey('phone_country', $supplier->getAttributes());
    }

    public function test_supplier_is_created_with_country_and_normalized_phone(): void
    {
        Livewire::test(CreateSupplier::class)
            ->fillForm(['name' => 'Romanian supplier', 'country' => 'RO', 'phone_country' => 'RO', 'phone' => '0722 123 456'])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->where('name', 'Romanian supplier')->firstOrFail();
        $this->assertSame('RO', $supplier->country);
        $this->assertSame('+40722123456', $supplier->phone);
    }

    public function test_editing_splits_existing_international_number_and_normalizes_legacy_country(): void
    {
        $supplier = Supplier::create(['name' => 'Legacy supplier', 'country' => 'România', 'phone' => '+44 7400 123456']);

        Livewire::test(EditSupplier::class, ['record' => $supplier->id])
            ->assertFormSet(['country' => 'RO', 'phone_country' => 'GB', 'phone' => '7400123456'])
            ->fillForm(['city' => 'Bucharest'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('+447400123456', $supplier->fresh()->phone);
        $this->assertSame('RO', $supplier->fresh()->country);
    }

    public function test_pasting_an_international_number_updates_the_prefix_selector(): void
    {
        Livewire::test(CreateSupplier::class)
            ->fillForm(['name' => 'French phone', 'phone_country' => 'RO'])
            ->set('data.phone', '0033 6 12 34 56 78')
            ->assertFormSet(['phone_country' => 'FR', 'phone' => '612345678'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('+33612345678', Supplier::query()->where('name', 'French phone')->firstOrFail()->phone);
    }

    public function test_country_only_changes_default_phone_prefix_when_phone_is_empty(): void
    {
        $page = Livewire::test(CreateSupplier::class)
            ->set('data.country', 'IT')
            ->assertFormSet(['phone_country' => 'IT']);

        $page->fillForm(['phone_country' => 'GB', 'phone' => '7400123456'])
            ->set('data.country', 'RO')
            ->assertFormSet(['phone_country' => 'GB', 'phone' => '7400123456']);
    }

    public function test_unknown_legacy_country_is_preserved_and_escaped(): void
    {
        $country = '<img src=x onerror=alert(1)>';
        $supplier = Supplier::create(['name' => 'Unlisted country', 'country' => $country]);

        Livewire::test(EditSupplier::class, ['record' => $supplier->id])
            ->assertFormSet(['country' => $country])
            ->assertFormFieldExists('country', function (Select $select) use ($country): bool {
                $this->assertSame(e($country), $select->getOptions()[$country]);

                return true;
            })
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($country, $supplier->fresh()->country);
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_phones_are_rejected_without_changing_saved_number(string $phone, string $country): void
    {
        $supplier = Supplier::create(['name' => 'Valid supplier', 'country' => 'RO', 'phone' => '+40722123456']);

        Livewire::test(EditSupplier::class, ['record' => $supplier->id])
            ->fillForm(['phone_country' => $country, 'phone' => $phone])
            ->call('save')
            ->assertHasFormErrors(['phone']);

        $this->assertSame('+40722123456', $supplier->fresh()->phone);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidNumbers(): array
    {
        return [
            'too short' => ['0722', 'RO'],
            'Romanian local service number' => ['212345', 'RO'],
            'Canadian local service number' => ['3101234', 'CA'],
            'too long' => ['07221234567890', 'RO'],
            'letters' => ['+40abc722123456', 'RO'],
            'invalid numbering range' => ['122123456', 'RO'],
            'two numbers' => ['0722123456 / 0722123457', 'RO'],
            'unsupported prefix' => ['722123456', 'XX'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_phone_formats_are_normalized(string $phone, string $country, string $expected): void
    {
        $this->assertSame($expected, SupplierPhoneNumber::normalize($phone, $country));
    }

    /** @return array<string, array{string, string, string}> */
    public static function validNumbers(): array
    {
        return [
            'Romanian national trunk' => ['0722 123 456', 'RO', '+40722123456'],
            'Romanian national' => ['722123456', 'RO', '+40722123456'],
            'Romanian international' => ['+40 (722) 123-456', 'RO', '+40722123456'],
            'international zero prefix' => ['0040 722 123 456', 'RO', '+40722123456'],
            'Italian significant zero' => ['02 1234 5678', 'IT', '+390212345678'],
            'UK trunk' => ['07400 123456', 'GB', '+447400123456'],
            'Hungarian trunk' => ['06 20 123 4567', 'HU', '+36201234567'],
            'North American trunk' => ['1 (202) 555-0123', 'US', '+12025550123'],
        ];
    }

    public function test_every_selectable_country_has_a_phone_prefix(): void
    {
        foreach (array_keys(Countries::options()) as $country) {
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{0,2}$/', SupplierPhoneNumber::callingCode($country));
        }
    }

    public function test_an_international_number_must_match_the_supplied_prefix(): void
    {
        $this->assertNull(SupplierPhoneNumber::normalize('+447400123456', 'RO'));
        $this->assertSame('+447400123456', SupplierPhoneNumber::normalize('+447400123456', 'GB'));
    }

    public function test_phone_can_be_removed_from_an_existing_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'Remove phone', 'phone' => '+40722123456']);

        Livewire::test(EditSupplier::class, ['record' => $supplier->id])
            ->fillForm(['phone' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($supplier->fresh()->phone);
    }
}
