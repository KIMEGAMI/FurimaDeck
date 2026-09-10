<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckAnalyticsService;
use App\Services\TodayWorkService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FurimaDeckDashboardController extends Controller
{
    public function __invoke(Request $request, FurimaDeckAnalyticsService $analytics, TodayWorkService $todayWork): View
    {
        return view('furimadeck_dashboard.index', [
            'summary' => $analytics->summary($request->user()),
            'todayWork' => $todayWork->summary($request->user()),
        ]);
    }
}
