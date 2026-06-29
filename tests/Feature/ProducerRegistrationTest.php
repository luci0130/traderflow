<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Producers\Filament\Pages\RegisterProducer;
use App\Modules\Producers\Models\Producer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProducerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_producer_assigns_role_and_links_user(): void
    {
        Filament::setCurrentPanel('producer');

        Livewire::test(RegisterProducer::class)
            ->fillForm([
                'producer_name' => 'Acme Producer',
                'name' => 'Jane Doe',
                'email' => 'jane@acme.test',
                'password' => 'secret-pass-123',
                'passwordConfirmation' => 'secret-pass-123',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $producer = Producer::where('name', 'Acme Producer')->firstOrFail();
        $this->assertSame('active', $producer->status);

        $user = User::where('email', 'jane@acme.test')->firstOrFail();
        $this->assertSame($producer->id, $user->producer_id, 'User must be linked to the new producer.');
        $this->assertTrue($user->hasGlobalRole('producer'), 'User must have the global producer role.');

        $panel = Filament::getPanel('producer');
        $this->assertTrue($user->canAccessPanel($panel), 'Newly registered producer must access the producer panel.');
    }
}
