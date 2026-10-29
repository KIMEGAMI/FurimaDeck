<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_html_responses_include_baseline_security_headers(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
    }

    public function test_https_responses_include_hsts_and_http_responses_do_not(): void
    {
        $httpResponse = $this->get(route('login'));
        $httpResponse->assertOk();
        $this->assertNull($httpResponse->headers->get('Strict-Transport-Security'));

        $httpsResponse = $this->withServerVariables([
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ])->get(route('login'));

        $httpsResponse->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_session_cookie_has_http_only_and_configured_transport_attributes(): void
    {
        $response = $this->get(route('login'));
        $sessionCookieName = (string) config('session.cookie');
        $sessionCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === $sessionCookieName);

        $this->assertNotNull($sessionCookie, 'The response must set the configured session cookie.');
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame((bool) config('session.secure'), $sessionCookie->isSecure());
        $this->assertSame(
            strtolower((string) config('session.same_site')),
            strtolower((string) $sessionCookie->getSameSite())
        );
    }

    public function test_html_responses_have_an_enforcing_csp_without_unsafe_script_sources(): void
    {
        $response = $this->get(route('login'));
        $response->assertOk();

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($policy, 'An enforcing Content-Security-Policy header is required.');

        $scriptSource = collect(explode(';', (string) $policy))
            ->map(fn (string $directive) => trim($directive))
            ->first(fn (string $directive) => str_starts_with(strtolower($directive), 'script-src '));

        $this->assertNotEmpty($scriptSource, 'The CSP must define script-src explicitly.');
        $this->assertStringNotContainsString("'unsafe-inline'", strtolower((string) $scriptSource));
        $this->assertStringNotContainsString("'unsafe-eval'", strtolower((string) $scriptSource));
    }
}
