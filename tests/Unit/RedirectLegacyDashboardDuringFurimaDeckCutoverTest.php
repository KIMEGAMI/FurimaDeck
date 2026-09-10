<?php

namespace Tests\Unit;

use App\Http\Middleware\RedirectLegacyDashboardDuringFurimaDeckCutover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class RedirectLegacyDashboardDuringFurimaDeckCutoverTest extends TestCase
{
    public function test_legacy_dashboard_redirect_is_rewritten_during_cutover(): void
    {
        config()->set('furimadeck.cutover_enabled', true);
        $response = app(RedirectLegacyDashboardDuringFurimaDeckCutover::class)->handle(
            Request::create('/login'),
            fn () => new RedirectResponse(route('dashboard').'?verified=1'),
        );

        $this->assertSame(route('furimadeck-dashboard').'?verified=1', $response->getTargetUrl());
    }

    public function test_other_redirects_are_not_changed(): void
    {
        config()->set('furimadeck.cutover_enabled', true);
        $response = app(RedirectLegacyDashboardDuringFurimaDeckCutover::class)->handle(
            Request::create('/login'),
            fn () => new RedirectResponse(route('login')),
        );

        $this->assertSame(route('login'), $response->getTargetUrl());
    }
}
