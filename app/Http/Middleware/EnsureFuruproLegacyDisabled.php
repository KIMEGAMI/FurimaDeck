<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFuruproLegacyDisabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('furimadeck.cutover_enabled')) {
            $redirect = $this->cutoverRedirect($request);
            if ($redirect !== null) {
                return $redirect;
            }

            abort(404);
        }

        return $next($request);
    }

    private function cutoverRedirect(Request $request): ?RedirectResponse
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        // Legacy item IDs belong to a different table than FurimaDeck product IDs.
        // Send stale detail/edit links to the new list instead of showing the old form
        // or guessing that both IDs refer to the same product.
        if (preg_match('/\Aauction-items\/\d+(?:\/edit)?\z/', $request->path()) === 1) {
            return redirect()->route('products.index');
        }

        return match ($request->path()) {
            'dashboard' => redirect()->route('furimadeck-dashboard', $request->query()),
            'auction-items' => redirect()->route('products.index', $request->query()),
            'auction-items/create' => redirect()->route('products.create', $request->query()),
            'auction-items/csv-import' => redirect()->route('products.imports.create', $request->query()),
            'sales' => redirect()->route('furimadeck-sales.index', $request->query()),
            'category-sales' => redirect()->route('furimadeck-analytics.categories', $request->query()),
            'profile' => redirect()->route('furimadeck-account.edit', $request->query()),
            default => null,
        };
    }
}
