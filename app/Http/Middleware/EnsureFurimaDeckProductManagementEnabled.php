<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureFurimaDeckProductManagementEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            (bool) config('furimadeck.cutover_enabled')
                && (bool) config('furimadeck.product_management_enabled')
                && DB::getDefaultConnection() === 'furimadeck',
            404,
        );

        return $next($request);
    }
}
