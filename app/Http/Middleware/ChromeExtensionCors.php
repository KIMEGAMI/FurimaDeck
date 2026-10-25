<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChromeExtensionCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin', '');
        $extensionId = (string) config('chrome_extension.extension_id', '');
        $originMatchesExtension = preg_match('/^chrome-extension:\/\/([a-p]{32})$/', $origin, $matches) === 1;
        $expectedIdIsValid = preg_match('/^[a-p]{32}$/', $extensionId) === 1;
        $allowed = $originMatchesExtension
            && ($extensionId !== ''
                ? $expectedIdIsValid && hash_equals($extensionId, $matches[1])
                : config('app.env') !== 'production');

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if ($allowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
            $response->headers->set('Access-Control-Max-Age', (string) config('chrome_extension.cors_preflight_max_age_seconds'));
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }
}
