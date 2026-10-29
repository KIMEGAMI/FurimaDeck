<?php

namespace Tests\\Feature;

use App\\Http\\Middleware\\SecurityHeaders;
use Illuminate\\Http\\Request;
use Symfony\\Component\\HttpFoundation\\Response;
use Tests\\TestCase;

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

    public function test_hsts_is_added_only_for_requests_that_are_actually_secure(): void
    {
        $middleware = new SecurityHeaders;
        $next = static fn (Request $request): Response => new Response;

        $httpResponse = $middleware->handle(Request::create('http://furimadeck.test/login'), $next);
        $this->assertNull($httpResponse->headers->get('Strict-Transport-Security'));

        $httpsResponse = $middleware->handle(Request::create('https://furimadeck.test/login'), $next);
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $httpsResponse->headers->get('Strict-Transport-Security')
        );
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

    public function test_html_responses_have_a_restrictive_enforcing_csp(): void
    {
        $response = $this->get(route('login'));
        $response->assertOk();

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotEmpty($policy, 'An enforcing Content-Security-Policy header is required.');

        $segments = collect(explode(';', (string) $policy))
            ->map(fn (string $directive): array => preg_split('/\\s+/', trim($directive)) ?: [])
            ->filter(fn (array $parts): bool => $parts !== [])
            ->values();

        $names = $segments->map(fn (array $parts): string => strtolower($parts[0]));
        $this->assertCount(
            $names->unique()->count(),
            $names,
            'CSP directives must not be duplicated because browsers ignore later duplicates.'
        );

        $directives = $segments->mapWithKeys(
            fn (array $parts): array => [strtolower(array_shift($parts)) => array_map('strtolower', $parts)]
        );

        $this->assertContains("'self'", $directives->get('default-src', []), 'default-src must be restricted to this origin.');
        $this->assertContains("'self'", $directives->get('script-src', []), 'script-src must allow the application origin.');
        $this->assertContains("'none'", $directives->get('object-src', []), 'object-src must disable plugin content.');
        $this->assertTrue(
            in_array("'self'", $directives->get('base-uri', []), true)
                || in_array("'none'", $directives->get('base-uri', []), true),
            'base-uri must prevent untrusted base URL injection.'
        );
        $this->assertTrue(
            in_array("'self'", $directives->get('frame-ancestors', []), true)
                || in_array("'none'", $directives->get('frame-ancestors', []), true),
            'frame-ancestors must restrict who can embed the application.'
        );
        $this->assertContains("'self'", $directives->get('form-action', []), 'form-action must be restricted to this origin.');

        $scriptSources = $directives->get('script-src', []);
        foreach (["'unsafe-inline'", "'unsafe-eval'", '*', 'http:', 'https:', 'data:'] as $unsafeSource) {
            $this->assertNotContains(
                $unsafeSource,
                $scriptSources,
                "script-src must not contain the broad or unsafe source {$unsafeSource}."
            );
        }
    }
}
