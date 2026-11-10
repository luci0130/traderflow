<?php

namespace Tests\Feature;

use App\Modules\Producers\Filament\Pages\RegisterProducer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MalcaraRegistrationPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('producer');
    }

    /** @return array<string, array{string, string, string}> */
    public static function languages(): array
    {
        return [
            'English' => ['en', 'Create your Malcara account', 'Your partner in international trade.'],
            'Romanian' => ['ro', 'Creează contul tău Malcara', 'Partenerul tău în comerțul internațional.'],
        ];
    }

    #[DataProvider('languages')]
    public function test_registration_renders_malcara_brand_and_company_details_in_both_languages(string $locale, string $heading, string $marketing): void
    {
        $this->withSession(['locale' => $locale])->get(route('filament.producer.auth.register'))
            ->assertOk()->assertSee($heading)->assertSee($marketing)
            ->assertSee('images/malcara-logo.svg')->assertSee('contact@malcara.com')
            ->assertSee('+40 748 894 514')->assertSee('Târnăveni')->assertSee('www.malcara.com')
            ->assertDontSee('TradeFlow')->assertSee('English')->assertSee('Română');
    }

    public function test_language_switch_updates_form_and_marketing_without_clearing_fields(): void
    {
        app()->setLocale('en');
        $page = Livewire::test(RegisterProducer::class)
            ->fillForm([
                'producer_name' => 'Our supplier', 'name' => 'Ana Pop', 'email' => 'ana@example.test',
                'phone' => '+40740000000', 'role_in_company' => 'Manager',
                'password' => 'sample-password-123', 'passwordConfirmation' => 'sample-password-123',
            ])
            ->call('switchLanguage', 'ro')
            ->assertSee('Creează contul tău Malcara')->assertSee('Denumire companie')
            ->assertSee('Partenerul tău în comerțul internațional.')
            ->assertSee('Creează contul Malcara')
            ->assertSet('data.producer_name', 'Our supplier')->assertSet('data.name', 'Ana Pop')
            ->assertSet('data.email', 'ana@example.test')->assertSet('data.phone', '+40740000000')
            ->assertSet('data.role_in_company', 'Manager')
            ->assertSet('data.password', 'sample-password-123')->assertSet('data.passwordConfirmation', 'sample-password-123')
            ->assertDispatched('registration-language-changed', locale: 'ro');
        $this->assertSame('ro', session('locale'));

        $page->call('switchLanguage', 'en')->assertSee('Create your Malcara account')
            ->assertSee('Company name')->assertSee('Your partner in international trade.')
            ->assertSet('data.email', 'ana@example.test')->assertSet('data.password', 'sample-password-123');
        $this->assertSame('en', session('locale'));
    }

    public function test_selected_language_survives_a_subsequent_request_and_refresh(): void
    {
        Livewire::test(RegisterProducer::class)->call('switchLanguage', 'ro');
        $this->get(route('filament.producer.auth.register'))->assertOk()->assertSee('Creează contul tău Malcara');
    }

    public function test_other_application_languages_use_english_on_registration(): void
    {
        $this->withSession(['locale' => 'fr'])->get(route('filament.producer.auth.register'))
            ->assertOk()->assertSee('Create your Malcara account')->assertSee('Company name');
    }

    public function test_unexpected_locale_is_rejected(): void
    {
        session(['locale' => 'en']);
        Livewire::test(RegisterProducer::class)->call('switchLanguage', 'fr')->assertStatus(422);
        $this->assertSame('en', session('locale'));
    }

    public function test_registration_still_succeeds_after_switching_language(): void
    {
        Livewire::test(RegisterProducer::class)->fillForm([
            'producer_name' => 'New Malcara partner', 'name' => 'Ana Pop', 'email' => 'ana@example.test',
            'password' => 'sample-password-123', 'passwordConfirmation' => 'sample-password-123',
        ])->call('switchLanguage', 'ro')->call('register')->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', ['name' => 'Ana Pop', 'email' => 'ana@example.test']);
        $this->assertDatabaseHas('suppliers', ['name' => 'New Malcara partner']);
    }

    public function test_brand_assets_are_local_and_available_for_light_and_dark_mode(): void
    {
        foreach (['logo', 'logo_white'] as $key) {
            $this->assertFileExists(public_path(config('malcara.'.$key)));
            $svg = file_get_contents(public_path(config('malcara.'.$key)));
            $this->assertStringContainsString('<svg', $svg);
            $this->assertStringNotContainsString('<script', $svg);
        }
        $this->assertSame('Malcara', Filament::getPanel('producer')->getBrandName());
    }

    public function test_romanian_registration_validation_is_translated(): void
    {
        Livewire::test(RegisterProducer::class)->call('switchLanguage', 'ro')->call('register')
            ->assertHasFormErrors(['producer_name' => 'required', 'name' => 'required'])
            ->assertSee('Acest câmp este obligatoriu.');
    }

    public function test_invalid_email_and_short_password_are_explained_in_romanian(): void
    {
        Livewire::test(RegisterProducer::class)->fillForm([
            'producer_name' => 'Partner', 'name' => 'Ana', 'email' => 'invalid-email',
            'password' => 'short', 'passwordConfirmation' => 'short',
        ])->call('switchLanguage', 'ro')->call('register')
            ->assertHasFormErrors(['email' => 'email', 'password'])
            ->assertSee('Introdu o adresă de email validă.')
            ->assertSee('Folosește cel puțin 8 caractere.');
    }
}
