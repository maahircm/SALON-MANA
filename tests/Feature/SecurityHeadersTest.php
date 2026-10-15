<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_the_browser_hardening_headers(): void
    {
        foreach (['/login', '/account-recovery'] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $csp = (string) $response->headers->get('Content-Security-Policy');
            foreach (["default-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "form-action 'self'", "base-uri 'self'"] as $directive) {
                $this->assertStringContainsString($directive, $csp);
            }
            $this->assertFalse($response->headers->has('Strict-Transport-Security'), 'HSTS only on HTTPS');
        }
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_hsts_is_sent_over_https_and_errors_carry_the_headers_too(): void
    {
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->get('/no-such-page')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_content_security_policy_does_not_allow_arbitrary_remote_scripts(): void
    {
        $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('script-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net', $csp);
        $this->assertStringNotContainsString('script-src *', $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }
}
