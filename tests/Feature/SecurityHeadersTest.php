<?php

namespace Tests\Feature;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_login_page_cannot_be_framed(): void
    {
        $response = $this->get(route('login'));

        $response
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');

        preg_match("/script-src 'self' 'nonce-([^']+)' 'strict-dynamic'/", $response->headers->get('Content-Security-Policy'), $nonceMatch);
        $this->assertNotEmpty($nonceMatch[1] ?? null);
        $this->assertStringContainsString('nonce="'.$nonceMatch[1].'"', $response->getContent());
        $this->assertStringNotContainsString("'unsafe-eval'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString("'unsafe-inline'", explode('style-src', $response->headers->get('Content-Security-Policy'))[0]);

        $anotherResponse = $this->get(route('login'));
        preg_match("/script-src 'self' 'nonce-([^']+)' 'strict-dynamic'/", $anotherResponse->headers->get('Content-Security-Policy'), $anotherNonceMatch);
        $this->assertNotSame($nonceMatch[1], $anotherNonceMatch[1] ?? null);

        $xsrfCookie = collect($response->baseResponse->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

        $this->assertNotNull($xsrfCookie);
        $this->assertTrue($xsrfCookie->isHttpOnly());
    }

    public function test_redirect_responses_keep_the_location_header_without_repeating_it_in_the_body(): void
    {
        $response = $this->get(route('auction-items.index'));

        $this->assertInstanceOf(RedirectResponse::class, $response->baseResponse);
        $response->assertRedirect(route('login'));
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Content-Security-Policy');
        $this->assertSame('', $response->getContent());
    }
}
