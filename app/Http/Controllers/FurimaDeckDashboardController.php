<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckAnalyticsService;
use App\Services\FurimaDeckSalesImprovementService;
use App\Services\TodayWorkService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FurimaDeckDashboardController extends Controller
{
    public function __invoke(Request $request, FurimaDeckAnalyticsService $analytics, TodayWorkService $todayWork, FurimaDeckSalesImprovementService $improvement): View
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'));

        return view('furimadeck_dashboard.index', [
            'month' => $month,
            'dashboard' => $analytics->dashboard($request->user(), $month),
            'todayWork' => $todayWork->summary($request->user()),
            'improvement' => $improvement->actions($request->user()),
            'recentProducts' => $request->user()->products()->latest('updated_at')->take(6)->get(),
        ]);
    }
}
