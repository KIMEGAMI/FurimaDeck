<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFuruproLegacyDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if((bool) config('furimadeck.cutover_enabled'), 404);

        return $next($request);
    }
}
