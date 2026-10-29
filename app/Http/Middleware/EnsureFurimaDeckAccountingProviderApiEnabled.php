<?php

namespace App\Http\Middleware;

use App\Services\FurimaDeckAccountingApiReleaseGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFurimaDeckAccountingProviderApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(FurimaDeckAccountingApiReleaseGate::class)->enabled(), 404);

        return $next($request);
    }
}
