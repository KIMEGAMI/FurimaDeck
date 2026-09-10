<?php

namespace App\Providers;

use App\Models\AuditLog;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AuditLog::saving(function (AuditLog $auditLog): void {
            $auditLog->ip_address = null;
        });

        if ((bool) config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}
