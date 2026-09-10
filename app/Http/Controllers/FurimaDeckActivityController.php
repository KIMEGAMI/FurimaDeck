<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class FurimaDeckActivityController extends Controller
{
    public function index(Request $request): View
    {
        return view('furimadeck_activity.index', [
            'activities' => $request->user()->auditLogs()->latest('created_at')->paginate(50),
        ]);
    }
}
