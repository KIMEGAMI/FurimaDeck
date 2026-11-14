<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckSalesImprovementService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FurimaDeckSalesImprovementController extends Controller
{
    public function index(Request $request, FurimaDeckSalesImprovementService $improvement): View
    {
        $validated = $request->validate([
            'stagnant_days' => [
                'nullable',
                'integer',
                'min:'.config('furimadeck.improvement.stagnant_search_min_days'),
                'max:'.config('furimadeck.improvement.stagnant_search_max_days'),
            ],
        ]);
        $stagnantDays = (int) ($validated['stagnant_days'] ?? config('furimadeck.improvement.stagnant_days.0'));

        return view('furimadeck_improvement.index', [
            'actions' => $improvement->actions($request->user(), stagnantDays: $stagnantDays),
            'stagnantDays' => $stagnantDays,
        ]);
    }
}
