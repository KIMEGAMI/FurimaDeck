<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFurimaDeckPremiumPlan
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->hasActiveSubscription()) {
            return $next($request);
        }

        $message = 'この機能はPremiumプランで利用できます。';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        return redirect()->route('furimadeck-billing.index')->with('error', $message);
    }
}
