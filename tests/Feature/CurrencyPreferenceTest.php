<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_default_to_ron(): void
    {
        $user = User::factory()->create();

        $this->assertSame('RON', $user->fresh()->preferred_currency);
        $this->actingAs($user);
        $this->assertSame('RON', Currency::preferred());
    }

    public function test_preference_is_persisted_only_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->from('/market-comparison')
            ->post(route('currency.switch', ['currency' => 'EUR']), ['user_id' => $other->id])
            ->assertRedirect('/market-comparison');

        $this->assertSame('EUR', $user->fresh()->preferred_currency);
        $this->assertSame('RON', $other->fresh()->preferred_currency);
        $this->actingAs($user->fresh());
        $this->assertSame('EUR', Currency::preferred());

        $this->post(route('currency.switch', ['currency' => 'RON']))->assertRedirect();
        $this->assertSame('RON', $user->fresh()->preferred_currency);
    }

    public function test_unsupported_currency_does_not_change_the_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('currency.switch', ['currency' => 'USD']))
            ->assertSessionHasErrors('currency');

        $this->assertSame('RON', $user->fresh()->preferred_currency);
    }

    public function test_guests_cannot_save_a_preference(): void
    {
        $this->postJson(route('currency.switch', ['currency' => 'EUR']))->assertUnauthorized();
    }

    public function test_preference_cannot_be_changed_with_a_get_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('currency.switch', ['currency' => 'EUR']))->assertMethodNotAllowed();
        $this->assertSame('RON', $user->fresh()->preferred_currency);
    }

    public function test_topbar_menus_show_languages_currencies_and_selected_options(): void
    {
        $this->actingAs(User::factory()->create());
        app()->setLocale('en');

        $this->view('filament.preferences-menu')
            ->assertSee('Language: English')
            ->assertSee('Display currency: RON')
            ->assertSee('EUR (€)')
            ->assertSee('RON (lei)')
            ->assertSee('English')
            ->assertSee('Romana')
            ->assertSee('name="_token"', false);
    }
}
