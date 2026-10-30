<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Services\FurimaDeckInvoiceTransitionCapService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(FurimaDeckInvoiceTransitionCapService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-contact', fn (Request $request) => Limit::perMinute((int) config('furimadeck.public_rate_limits.contact_per_minute'))->by('contact:'.($request->ip() ?? 'unknown')));
        RateLimiter::for('public-registration', fn (Request $request) => Limit::perMinute((int) config('furimadeck.public_rate_limits.registration_per_minute'))->by('registration:'.($request->ip() ?? 'unknown')));
        RateLimiter::for('public-password-reset', fn (Request $request) => Limit::perMinute((int) config('furimadeck.public_rate_limits.password_reset_per_minute'))->by('password-reset:'.($request->ip() ?? 'unknown')));
        RateLimiter::for('public-demo-login', fn (Request $request) => Limit::perMinute((int) config('furimadeck.public_rate_limits.demo_login_per_minute'))->by('demo-login:'.($request->ip() ?? 'unknown')));

        AuditLog::saving(function (AuditLog $auditLog): void {
            $auditLog->ip_address = null;
        });

        if ((bool) config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}
