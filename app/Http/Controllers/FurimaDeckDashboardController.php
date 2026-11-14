<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\FurimaDeckAnalyticsService;
use App\Services\FurimaDeckSalesImprovementService;
use App\Services\FurimaDeckTaxReadinessService;
use App\Services\TodayWorkService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FurimaDeckDashboardController extends Controller
{
    public function __invoke(Request $request, FurimaDeckAnalyticsService $analytics, TodayWorkService $todayWork, FurimaDeckSalesImprovementService $improvement, FurimaDeckTaxReadinessService $taxReadiness): View
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'));
        $dashboard = $analytics->dashboard($request->user(), $month);
        $recentSales = $request->user()->sales()
            ->whereIn('status', Sale::VALID_SOLD_STATUSES)
            ->whereNotNull('sold_at')
            ->with([
                'marketplace',
                'product' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
        $taxReadinessReport = $dashboard['can_use_premium_features']
            ? $taxReadiness->report($request->user(), CarbonImmutable::now(config('app.timezone'))->year)
            : null;

        return view('furimadeck_dashboard.index', [
            'month' => $month,
            'dashboard' => $dashboard,
            'recentSales' => $recentSales,
            'taxReadiness' => $taxReadinessReport,
            'todayWork' => $todayWork->summary($request->user()),
            'improvement' => $improvement->actions($request->user()),
            'recentProducts' => $request->user()->products()->latest('updated_at')->take(6)->get(),
        ]);
    }
}
