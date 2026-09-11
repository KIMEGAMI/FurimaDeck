<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeSettingsCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_stripe_check_passes_with_live_ready_settings(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'app.url' => 'https://furupro.shinji.work',
            'services.stripe.secret' => 'sk_live_example',
            'services.stripe.subscription_price_id' => 'price_live_premium',
            'services.stripe.webhook_secret' => 'whsec_example',
            'services.stripe.trial_period_days' => 7,
        ]);

        Http::fake([
            'https://api.stripe.com/v1/prices/price_live_premium' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'unit_amount' => 980,
                'recurring' => ['interval' => 'month'],
            ]),
            'https://api.stripe.com/v1/account' => Http::response([
                'charges_enabled' => true,
                'details_submitted' => true,
            ]),
            'https://api.stripe.com/v1/billing_portal/configurations*' => Http::response([
                'data' => [
                    ['id' => 'bpc_live', 'active' => true],
                ],
            ]),
        ]);

        $this->artisan('stripe:check')
            ->assertSuccessful();
    }

    public function test_production_stripe_check_fails_when_price_amount_is_not_980(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'app.url' => 'https://furupro.shinji.work',
            'services.stripe.secret' => 'sk_live_example',
            'services.stripe.subscription_price_id' => 'price_live_wrong_amount',
            'services.stripe.webhook_secret' => 'whsec_example',
            'services.stripe.trial_period_days' => 7,
        ]);

        Http::fake([
            'https://api.stripe.com/v1/prices/price_live_wrong_amount' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'unit_amount' => 480,
                'recurring' => ['interval' => 'month'],
            ]),
            'https://api.stripe.com/v1/account' => Http::response([
                'charges_enabled' => true,
                'details_submitted' => true,
            ]),
            'https://api.stripe.com/v1/billing_portal/configurations*' => Http::response([
                'data' => [
                    ['id' => 'bpc_live', 'active' => true],
                ],
            ]),
        ]);

        $this->artisan('stripe:check')
            ->assertFailed();
    }
}
