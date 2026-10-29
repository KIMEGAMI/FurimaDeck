<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeWebhookSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => null]);
    }

    public function test_subscription_updated_from_trialing_to_active_keeps_user_premium(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'trialing',
            'stripe_customer_id' => 'cus_trial',
            'stripe_subscription_id' => 'sub_trial',
            'premium_ends_at' => now()->addDay(),
            'trial_used_at' => now()->subDays(7),
        ]);

        $periodEnd = now()->addMonth()->timestamp;
        $payload = json_encode([
            'id' => 'evt_trial_finished',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_trial',
                    'customer' => 'cus_trial',
                    'status' => 'active',
                    'current_period_end' => $periodEnd,
                    'trial_start' => now()->subDays(7)->timestamp,
                    'trial_end' => now()->timestamp,
                    'metadata' => ['user_id' => (string) $user->id],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test'),
            ],
            $payload
        );

        $response->assertOk();

        $user->refresh();

        $this->assertTrue($user->hasActiveSubscription());
        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('active', $user->subscription_status);
        $this->assertSame('sub_trial', $user->stripe_subscription_id);
        $this->assertNotNull($user->trial_used_at);
    }

    public function test_invalid_stripe_webhook_signature_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $payload = json_encode([
            'id' => 'evt_invalid',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => []],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=invalid',
            ],
            $payload
        )->assertBadRequest();
    }

    public function test_missing_stripe_webhook_signature_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $payload = json_encode([
            'id' => 'evt_missing_signature',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => []],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertBadRequest();
    }

    public function test_expired_stripe_webhook_signature_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $payload = json_encode([
            'id' => 'evt_expired_signature',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => []],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test', time() - 301),
            ],
            $payload
        )->assertBadRequest();
    }

    public function test_stripe_webhook_signature_accepts_any_valid_v1_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $payload = json_encode([
            'id' => 'evt_multiple_v1',
            'type' => 'customer.subscription.trial_will_end',
            'data' => [
                'object' => [
                    'id' => 'sub_trial_end',
                    'customer' => 'cus_trial_end',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test', extraSignatures: ['invalid']),
            ],
            $payload
        )->assertOk();
    }

    public function test_subscription_created_syncs_user_subscription(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => 'cus_created',
            'stripe_subscription_id' => null,
            'trial_used_at' => null,
        ]);

        $trialEnd = now()->addDays(7)->timestamp;
        $payload = json_encode([
            'id' => 'evt_subscription_created',
            'type' => 'customer.subscription.created',
            'data' => [
                'object' => [
                    'id' => 'sub_created',
                    'customer' => 'cus_created',
                    'status' => 'trialing',
                    'trial_start' => now()->timestamp,
                    'trial_end' => $trialEnd,
                    'current_period_end' => $trialEnd,
                    'metadata' => ['user_id' => (string) $user->id],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test'),
            ],
            $payload
        )->assertOk();

        $user->refresh();

        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('trialing', $user->subscription_status);
        $this->assertSame('sub_created', $user->stripe_subscription_id);
        $this->assertNotNull($user->trial_used_at);
    }

    public function test_invoice_paid_syncs_current_subscription_from_stripe(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.secret' => 'sk_test_example',
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => 'past_due',
            'stripe_customer_id' => 'cus_invoice_paid',
            'stripe_subscription_id' => 'sub_invoice_paid',
        ]);

        $periodEnd = now()->addMonth()->timestamp;

        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_invoice_paid' => Http::response([
                'id' => 'sub_invoice_paid',
                'customer' => 'cus_invoice_paid',
                'status' => 'active',
                'current_period_end' => $periodEnd,
                'metadata' => ['user_id' => (string) $user->id],
            ]),
        ]);

        $payload = json_encode([
            'id' => 'evt_invoice_paid',
            'type' => 'invoice.paid',
            'data' => [
                'object' => [
                    'id' => 'in_paid',
                    'subscription' => 'sub_invoice_paid',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($payload, 'whsec_test'),
            ],
            $payload
        )->assertOk();

        $user->refresh();

        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('active', $user->subscription_status);
        $this->assertSame(
            Carbon::createFromTimestamp($periodEnd)->toDateTimeString(),
            $user->premium_ends_at?->toDateTimeString()
        );
    }

    public function test_late_old_subscription_event_does_not_overwrite_current_stripe_state(): void
    {
        config([
            'services.stripe.webhook_secret' => 'whsec_test',
            'services.stripe.secret' => 'sk_test_example',
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => 'cus_ordering',
            'stripe_subscription_id' => 'sub_ordering',
        ]);

        $currentPeriodEnd = now()->addMonth()->timestamp;

        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_ordering' => Http::sequence()
                ->push([
                    'id' => 'sub_ordering',
                    'customer' => 'cus_ordering',
                    'status' => 'active',
                    'current_period_end' => $currentPeriodEnd,
                    'metadata' => ['user_id' => (string) $user->id],
                ])
                ->push([
                    'id' => 'sub_ordering',
                    'customer' => 'cus_ordering',
                    'status' => 'active',
                    'current_period_end' => $currentPeriodEnd,
                    'metadata' => ['user_id' => (string) $user->id],
                ]),
        ]);

        $activePayload = json_encode([
            'id' => 'evt_current_active',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_ordering',
                    'customer' => 'cus_ordering',
                    'status' => 'active',
                    'current_period_end' => $currentPeriodEnd,
                    'metadata' => ['user_id' => (string) $user->id],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $oldCanceledPayload = json_encode([
            'id' => 'evt_old_canceled',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_ordering',
                    'customer' => 'cus_ordering',
                    'status' => 'canceled',
                    'current_period_end' => now()->subDay()->timestamp,
                    'metadata' => ['user_id' => (string) $user->id],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($activePayload, 'whsec_test'),
            ],
            $activePayload
        )->assertOk();

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($oldCanceledPayload, 'whsec_test'),
            ],
            $oldCanceledPayload
        )->assertOk();

        $user->refresh();

        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('active', $user->subscription_status);
        $this->assertSame(
            Carbon::createFromTimestamp($currentPeriodEnd)->toDateTimeString(),
            $user->premium_ends_at?->toDateTimeString()
        );
    }

    public function test_duplicate_stripe_event_id_is_ignored(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => 'cus_idempotent',
            'stripe_subscription_id' => null,
            'premium_started_at' => null,
            'premium_ends_at' => null,
            'trial_used_at' => null,
        ]);

        $firstPeriodEnd = now()->addMonth()->timestamp;
        $secondPeriodEnd = now()->addYear()->timestamp;

        $firstPayload = json_encode([
            'id' => 'evt_idempotent_subscription_update',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_first',
                    'customer' => 'cus_idempotent',
                    'status' => 'active',
                    'current_period_end' => $firstPeriodEnd,
                    'metadata' => ['user_id' => (string) $user->id],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $secondPayload = json_encode([
            'id' => 'evt_idempotent_subscription_update',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_second_should_not_apply',
                    'customer' => 'cus_idempotent',
                    'status' => 'canceled',
                    'current_period_end' => $secondPeriodEnd,
                    'metadata' => ['user_id' => (string) $user->id],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($firstPayload, 'whsec_test'),
            ],
            $firstPayload
        )->assertOk();

        $this->call(
            'POST',
            route('stripe.webhook'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $this->stripeSignature($secondPayload, 'whsec_test'),
            ],
            $secondPayload
        )->assertOk();

        $user->refresh();

        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('active', $user->subscription_status);
        $this->assertSame('sub_first', $user->stripe_subscription_id);
        $this->assertSame(
            Carbon::createFromTimestamp($firstPeriodEnd)->toDateTimeString(),
            $user->premium_ends_at?->toDateTimeString()
        );
        $this->assertDatabaseCount('stripe_webhook_events', 1);
        $this->assertDatabaseHas('stripe_webhook_events', [
            'stripe_event_id' => 'evt_idempotent_subscription_update',
            'event_type' => 'customer.subscription.updated',
        ]);
        $this->assertNotNull(
            DB::table('stripe_webhook_events')
                ->where('stripe_event_id', 'evt_idempotent_subscription_update')
                ->value('processed_at')
        );
    }

    /**
     * @param  array<int, string>  $extraSignatures
     */
    private function stripeSignature(string $payload, string $secret, ?int $timestamp = null, array $extraSignatures = []): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $signatures = collect([...$extraSignatures, $signature])
            ->map(fn (string $value): string => 'v1='.$value)
            ->implode(',');

        return 't='.$timestamp.','.$signatures;
    }
}
