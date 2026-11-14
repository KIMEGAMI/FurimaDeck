<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        if ($response instanceof RedirectResponse) {
            // The Location header is enough for user agents to follow a redirect.
            // Symfony's fallback HTML repeats the target URL in the response body,
            // which can disclose transient OAuth state and inflate redirect responses.
            $response->setContent('');
        }

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN' && ! $cookie->isHttpOnly()) {
                $response->headers->setCookie($cookie->withHttpOnly());
            }
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https:",
            "connect-src 'self'",
            "script-src-attr 'none'",
            "frame-src 'self' https://js.stripe.com https://hooks.stripe.com",
            "form-action 'self' https:",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ]));

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
