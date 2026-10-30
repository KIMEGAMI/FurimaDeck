<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $request->attributes->set('csp_nonce', $nonce);
        Vite::useCspNonce($nonce);

        /** @var Response $response */
        $response = $next($request);

        return $this->apply($response, $request, $nonce);
    }

    public function apply(Response $response, Request $request, ?string $nonce = null): Response
    {
        $nonce ??= $request->attributes->get('csp_nonce');

        if (! is_string($nonce) || $nonce === '') {
            $nonce = base64_encode(random_bytes(18));
            $request->attributes->set('csp_nonce', $nonce);
        }

        Vite::useCspNonce($nonce);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');

        if (str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "script-src-attr 'none'",
                "style-src 'self' 'nonce-{$nonce}'",
                "style-src-attr 'none'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "manifest-src 'self'",
                "worker-src 'self' blob:",
            ]));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
