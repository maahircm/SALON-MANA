<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_can_login_and_login_is_recorded(): void
    {
        $user = User::factory()->withPermissions('dashboard.view')->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_activities', ['user_id' => $user->id, 'event' => 'login']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_invalid_password_fails_and_is_recorded(): void
    {
        $user = User::factory()->create();
        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('login_activities', ['user_id' => $user->id, 'event' => 'failed']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_user_with_live_session_is_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'bad']);
        }
        // Correct credentials are still refused while locked out.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('login_activities', ['event' => 'locked']);
    }

    public function test_logout_records_activity_and_ends_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('login_activities', ['user_id' => $user->id, 'event' => 'logout']);
    }

    public function test_password_reset_flow_and_neutral_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('success');
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
            $this->post('/reset-password', ['token' => $n->token, 'email' => $user->email, 'password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123'])->assertRedirect('/login');

            return true;
        });
        $this->post('/login', ['email' => $user->email, 'password' => 'NewSecret123'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_must_change_password_blocks_other_pages(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/profile/password');
        $this->actingAs($user)->get('/profile/password')->assertOk();
    }

    public function test_password_change_requires_current_password_and_audits(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put('/profile/password', ['current_password' => 'nope', 'password' => 'Another123x', 'password_confirmation' => 'Another123x'])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put('/profile/password', ['current_password' => 'password', 'password' => 'Another123x', 'password_confirmation' => 'Another123x'])->assertRedirect('/profile');
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed', 'user_id' => $user->id]);
    }

    public function test_weak_passwords_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put('/profile/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    }

    public function test_two_factor_setup_login_and_recovery_code(): void
    {
        $svc = app(TwoFactorService::class);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/profile/two-factor');
        $secret = $user->fresh()->two_factor_secret;
        $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $resp = $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => $svc->code($secret)]);
        $codes = session('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        auth()->logout();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/two-factor-challenge', ['code' => $svc->code($secret)])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect('/dashboard');
        auth()->logout();
        // Recovery codes are single use
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_totp_matches_rfc6238_vector(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 of "12345678901234567890"
        $this->assertSame('287082', app(TwoFactorService::class)->code($secret, 59));
    }

    public function test_pages_render_for_guests(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
        $this->get('/account-recovery')->assertOk();
    }
}
