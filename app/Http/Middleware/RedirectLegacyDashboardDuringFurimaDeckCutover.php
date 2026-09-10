<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyDashboardDuringFurimaDeckCutover
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! (bool) config('furimadeck.cutover_enabled') || ! $response instanceof RedirectResponse) {
            return $response;
        }

        $location = $response->getTargetUrl();
        $legacyDashboard = route('dashboard', [], true);
        if (! str_starts_with($location, $legacyDashboard)) {
            return $response;
        }

        $suffix = substr($location, strlen($legacyDashboard));
        $response->setTargetUrl(route('furimadeck-dashboard', [], true).$suffix);

        return $response;
    }
}
