<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TutorialProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_mark_a_tutorial_completed(): void
    {
        $this->post(route('tutorials.complete'), ['key' => 'dashboard_intro'])
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_marks_a_tutorial_completed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('tutorials.complete'), ['key' => 'dashboard_intro'])
            ->assertNoContent();

        $this->assertTrue($user->refresh()->hasCompletedTutorial('dashboard_intro'));
        $this->assertSame(['dashboard_intro'], $user->completedTutorials());
    }

    public function test_completing_the_same_tutorial_twice_does_not_duplicate(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tutorials.complete'), ['key' => 'dashboard_intro']);
        $this->actingAs($user)->post(route('tutorials.complete'), ['key' => 'dashboard_intro']);

        $this->assertSame(['dashboard_intro'], $user->refresh()->completedTutorials());
    }

    public function test_multiple_tutorials_are_tracked_independently(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tutorials.complete'), ['key' => 'dashboard_intro']);
        $this->actingAs($user)->post(route('tutorials.complete'), ['key' => 'admin_welcome']);

        $this->assertEqualsCanonicalizing(
            ['dashboard_intro', 'admin_welcome'],
            $user->refresh()->completedTutorials(),
        );
    }

    public function test_key_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('tutorials.complete'), [])
            ->assertSessionHasErrors('key');
    }

    public function test_inertia_request_receives_a_redirect_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->from('/')
            ->post(route('tutorials.complete'), ['key' => 'dashboard_intro'])
            ->assertRedirect('/');
    }
}
