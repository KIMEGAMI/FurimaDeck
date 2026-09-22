<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckAdvancedAnalyticsService;
use App\Services\FurimaDeckSalesAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FurimaDeckSalesAnalysisController extends Controller
{
    public function index(Request $request, FurimaDeckSalesAnalysisService $analytics): View
    {
        $month = $this->month($request);

        return view('furimadeck_analytics.index', [
            'month' => $month,
            'report' => $analytics->report($request->user(), $month),
        ]);
    }

    public function advanced(Request $request, FurimaDeckAdvancedAnalyticsService $analytics): View
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'] ?? now()->startOfMonth()->format('Y-m-d'));
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'] ?? now()->addDay()->format('Y-m-d'))->addDay();

        if ($to->lessThanOrEqualTo($from)) {
            abort(422, '分析期間の終了日は開始日より後にしてください。');
        }

        return view('furimadeck_analytics.advanced', [
            'from' => $from,
            'to' => $to->subDay(),
            'report' => $analytics->report($request->user(), $from, $to),
        ]);
    }

    public function marketplaceSuitability(Request $request, FurimaDeckSalesAnalysisService $analytics): View
    {
        $month = $this->month($request);

        return view('furimadeck_analytics.suitability', [
            'month' => $month,
            'report' => $analytics->marketplaceSuitabilityReport($request->user(), $month),
        ]);
    }

    public function categories(Request $request, FurimaDeckSalesAnalysisService $analytics): View
    {
        $month = $this->month($request);

        return view('furimadeck_analytics.categories', [
            'month' => $month,
            'report' => $analytics->categoryReport($request->user(), $month),
        ]);
    }

    public function cross(Request $request, FurimaDeckSalesAnalysisService $analytics): View
    {
        $month = $this->month($request);

        return view('furimadeck_analytics.cross', [
            'month' => $month,
            'report' => $analytics->crossReport($request->user(), $month),
        ]);
    }

    private function month(Request $request): CarbonImmutable
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return CarbonImmutable::createFromFormat('!Y-m', $validated['month'] ?? now()->format('Y-m'));
    }
}
