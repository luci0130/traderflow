<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', SetLocale::class])->get('/locale-probe', fn (): string => app()->getLocale());
    }

    public function test_switching_to_romanian_persists_the_choice_in_session(): void
    {
        $this->get('/locale/ro')->assertRedirect();

        $this->assertSame('ro', session('locale'));
    }

    public function test_switching_to_any_supported_locale_persists_the_choice_in_session(): void
    {
        $this->get('/locale/fr')->assertRedirect();

        $this->assertSame('fr', session('locale'));
    }

    public function test_switching_to_an_unsupported_locale_is_a_noop(): void
    {
        session(['locale' => 'en']);

        $this->get('/locale/it')->assertRedirect();

        $this->assertSame('en', session('locale'));
    }

    public function test_translations_resolve_to_romanian_when_the_locale_is_ro(): void
    {
        app()->setLocale('ro');

        $this->assertSame('Vânzări', __('Sales'));
        $this->assertSame('Client', __('Customer'));
        $this->assertSame('Trimite oferta pe email', __('Send Offer Email'));
        $this->assertSame('Convertește în comandă', __('Convert to Sales Order'));
    }

    public function test_translations_fall_back_to_english_when_locale_is_en(): void
    {
        app()->setLocale('en');

        $this->assertSame('Sales', __('Sales'));
        $this->assertSame('Customer', __('Customer'));
    }

    public function test_locale_is_detected_from_country_header_when_no_manual_locale_exists(): void
    {
        $this->withHeader('CF-IPCountry', 'RO')
            ->get('/locale-probe')
            ->assertOk()
            ->assertSeeText('ro', false);

        $this->withHeader('CF-IPCountry', 'FR')
            ->get('/locale-probe')
            ->assertOk()
            ->assertSeeText('fr', false);

        $this->withHeader('CF-IPCountry', 'DE')
            ->get('/locale-probe')
            ->assertOk()
            ->assertSeeText('de', false);

        $this->withHeader('CF-IPCountry', 'ES')
            ->get('/locale-probe')
            ->assertOk()
            ->assertSeeText('es', false);
    }

    public function test_manual_locale_takes_priority_over_detected_country(): void
    {
        session(['locale' => 'en']);

        $this->withHeader('CF-IPCountry', 'RO')
            ->get('/locale-probe')
            ->assertOk()
            ->assertSeeText('en', false);
    }

    public function test_unknown_country_falls_back_to_default_locale(): void
    {
        config(['app.locale' => 'en']);

        $this->withHeader('CF-IPCountry', 'US')
            ->get('/locale-probe')
            ->assertOk()
            ->assertSeeText('en', false);
    }
}
