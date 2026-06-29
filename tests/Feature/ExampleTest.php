<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        $response = $this->get(route('home'));

        $response->assertRedirect('/');
    }

    public function test_filament_is_mounted_at_the_root_path()
    {
        $response = $this->get('/');

        $response->assertRedirect(route('filament.admin.auth.login'));
    }
}
