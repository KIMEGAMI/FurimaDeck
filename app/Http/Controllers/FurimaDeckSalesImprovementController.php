<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckSalesImprovementService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FurimaDeckSalesImprovementController extends Controller
{
    public function index(Request $request, FurimaDeckSalesImprovementService $improvement): View
    {
        return view('furimadeck_improvement.index', [
            'actions' => $improvement->actions($request->user()),
        ]);
    }
}
