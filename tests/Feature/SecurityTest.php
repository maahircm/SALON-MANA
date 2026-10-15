<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_is_enforced_on_state_changing_requests(): void
    {
        // Laravel skips CSRF verification in the "testing" environment; simulate a real one.
        $this->app->detectEnvironment(fn () => 'local');
        $user = User::factory()->create();
        $this->actingAs($user)->post('/logout')->assertStatus(419);
    }

    public function test_output_is_escaped(): void
    {
        $admin = User::factory()->superAdmin()->create(['name' => '<script>alert(1)</script>']);
        $this->actingAs($admin)->get('/users')->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_unknown_route_renders_404_page(): void
    {
        $this->get('/does-not-exist')->assertNotFound()->assertSee('Page not found');
    }

    public function test_unauthorized_page_is_rendered(): void
    {
        $this->actingAs(User::factory()->create())->get('/users')->assertForbidden()->assertSee('Unauthorized');
    }

    public function test_password_and_secrets_hidden_from_serialization(): void
    {
        $u = User::factory()->create();
        $this->assertArrayNotHasKey('password', $u->toArray());
        $this->assertArrayNotHasKey('two_factor_secret', $u->toArray());
    }
}
