<?php

use App\Http\Middleware\EnsureFurimaDeckPremiumPlan;
use App\Http\Middleware\EnsureFurimaDeckProductManagementEnabled;
use App\Http\Middleware\EnsureFuruproLegacyDisabled;
use App\Http\Middleware\EnsurePremiumPlan;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\RedirectLegacyDashboardDuringFurimaDeckCutover;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            MaintenanceMode::class,
        ]);

        $middleware->web(append: [
            SecurityHeaders::class,
            RedirectLegacyDashboardDuringFurimaDeckCutover::class,
        ]);

        $middleware->alias([
            'premium' => EnsurePremiumPlan::class,
            'furimadeck.products' => EnsureFurimaDeckProductManagementEnabled::class,
            'furimadeck.premium' => EnsureFurimaDeckPremiumPlan::class,
            'furupro.legacy' => EnsureFuruproLegacyDisabled::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/webhook',
            'furimadeck/stripe/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
