<?php

namespace App\Providers;

use App\Models\AuditLog;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('extension-pairing-start', fn (Request $request) => Limit::perMinute((int) config('chrome_extension.rate_limits.pairing_start_per_minute'))->by($request->ip() ?? 'unknown'));
        RateLimiter::for('extension-pairing-complete', fn (Request $request) => Limit::perMinute((int) config('chrome_extension.rate_limits.pairing_complete_per_minute'))->by($request->ip() ?? 'unknown'));
        RateLimiter::for('extension-device-read', fn (Request $request) => Limit::perMinute((int) config('chrome_extension.rate_limits.device_reads_per_minute'))->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('extension-image-read', fn (Request $request) => Limit::perMinute((int) config('chrome_extension.rate_limits.image_reads_per_minute'))->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('extension-device-revoke', fn (Request $request) => Limit::perMinute((int) config('chrome_extension.rate_limits.device_revokes_per_minute'))->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('extension-listing-write', fn (Request $request) => Limit::perMinute((int) config('chrome_extension.rate_limits.listing_writes_per_minute'))->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('extension-template-write', fn (Request $request) => Limit::perMinute((int) config('chrome_extension.rate_limits.template_writes_per_minute'))->by(hash('sha256', (string) $request->bearerToken())));
        RateLimiter::for('extension-export', fn (Request $request) => Limit::perMinute((int) config('chrome_extension.rate_limits.exports_per_minute'))->by(hash('sha256', (string) $request->bearerToken())));

        AuditLog::saving(function (AuditLog $auditLog): void {
            $auditLog->ip_address = null;
        });

        if ((bool) config('app.force_https')) {
            URL::forceScheme('https');
        }
    }
}
