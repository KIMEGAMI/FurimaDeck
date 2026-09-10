<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        if (Auth::check()) {
            if ((bool) config('furimadeck.cutover_enabled')) {
                return redirect()->route('furimadeck-dashboard');
            }

            return redirect()->route('dashboard');
        }

        return view('welcome', [
            'seo' => config('seo'),
        ]);
    }
}
