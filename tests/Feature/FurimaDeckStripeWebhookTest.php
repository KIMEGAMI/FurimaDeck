<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FurimaDeckStripeWebhookTest extends TestCase
{
    private const EXPIRED_SIGNATURE_AGE_MINUTES = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.billing.stripe_webhook_secret', 'whsec_test_example');
        config()->set('furimadeck.billing.stripe_secret', 'sk_test_furimadeck_webhook');
        config()->set('furimadeck.billing.stripe_api_base', 'https://stripe.test/v1');
        config()->set('furimadeck.billing.stripe_price_id', 'price_furimadeck_980');
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_signed_subscription_event_updates_access_once(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);
        $payload = $this->subscriptionEvent('evt_subscription_created', time(), 'trialing', time() + 604800);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $user->refresh();
        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('trialing', $user->subscription_status);
        $this->assertSame('sub_furimadeck', $user->stripe_subscription_id);
        $this->assertNotNull($user->trial_used_at);
        $this->assertSame(1, DB::table('furimadeck_stripe_webhook_events')->count());
    }

    public function test_older_subscription_event_does_not_overwrite_newer_status(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);
        $newEventTime = time();
        $currentSubscription = $this->subscriptionEvent('evt_current', $newEventTime, 'active', $newEventTime + 604800)['data']['object'];
        $this->postWebhook($this->subscriptionEvent('evt_newer', $newEventTime, 'active', $newEventTime + 604800), null, [$currentSubscription])->assertOk();
        $this->postWebhook($this->subscriptionEvent('evt_older', $newEventTime, 'canceled', $newEventTime - 1), null, [$currentSubscription])->assertOk();

        $this->assertSame('active', $user->fresh()->subscription_status);
    }

    public function test_current_stripe_state_overrides_an_event_snapshot_with_the_same_created_timestamp(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);
        $sameTimestamp = time();
        $currentCanceledSubscription = $this->subscriptionEvent('evt_current', $sameTimestamp, 'canceled', $sameTimestamp - 1)['data']['object'];

        $this->postWebhook(
            $this->subscriptionEvent('evt_delayed_active_snapshot', $sameTimestamp, 'active', $sameTimestamp + 604800),
            null,
            [$currentCanceledSubscription],
        )->assertOk();

        $this->assertSame(User::SUBSCRIPTION_INACTIVE, $user->fresh()->subscription_plan);
        $this->assertSame('canceled', $user->fresh()->subscription_status);
    }

    public function test_subscription_lookup_uses_later_pages_to_find_the_current_plan(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);
        $createdAt = time();
        $olderSubscription = $this->subscriptionEvent('evt_old', $createdAt - 1000, 'canceled', $createdAt - 10)['data']['object'];
        $olderSubscription['id'] = 'sub_old';
        $currentSubscription = $this->subscriptionEvent('evt_current', $createdAt, 'active', $createdAt + 604800)['data']['object'];
        $currentSubscription['id'] = 'sub_current';

        $this->postWebhook(
            $this->subscriptionEvent('evt_subscription_second_page', $createdAt, 'active', $createdAt + 604800),
            null,
            null,
            200,
            [
                ['data' => [$olderSubscription], 'has_more' => true],
                ['data' => [$currentSubscription], 'has_more' => false],
            ],
        )->assertOk();

        $this->assertSame('active', $user->fresh()->subscription_status);
        $this->assertSame('sub_current', $user->fresh()->stripe_subscription_id);
        Http::assertSentCount(2);
    }

    public function test_stripe_state_lookup_failure_does_not_grant_access_and_allows_retry(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $event = $this->subscriptionEvent('evt_stripe_unavailable', time(), 'active', time() + 604800);
        $event['data']['object']['metadata']['user_id'] = (string) $user->id;

        $this->postWebhook(
            $event,
            null,
            [],
            503,
        )->assertStatus(500);

        $this->assertSame(User::SUBSCRIPTION_INACTIVE, $user->fresh()->subscription_plan);
        $this->assertNull($user->fresh()->stripe_customer_id);
        $this->assertSame(0, DB::table('furimadeck_stripe_webhook_events')->count());
    }

    public function test_subscription_for_another_price_cannot_grant_premium(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);

        $this->postWebhook($this->subscriptionEvent('evt_wrong_price', time(), 'active', time() + 604800, 'price_another_product'))->assertOk();

        $this->assertSame(User::SUBSCRIPTION_INACTIVE, $user->fresh()->subscription_plan);
        $this->assertNull($user->fresh()->stripe_subscription_id);
    }

    public function test_subscription_event_for_another_price_revokes_stale_access_for_the_tracked_subscription(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
            'stripe_subscription_id' => 'sub_furimadeck',
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'premium_ends_at' => now()->addMonth(),
        ]);

        $this->postWebhook($this->subscriptionEvent('evt_changed_to_wrong_price', time(), 'active', time() + 604800, 'price_another_product'))->assertOk();

        $this->assertSame(User::SUBSCRIPTION_INACTIVE, $user->fresh()->subscription_plan);
        $this->assertFalse($user->fresh()->hasActiveSubscription());
    }

    public function test_invalid_signature_is_rejected_without_recording_an_event(): void
    {
        $payload = json_encode($this->subscriptionEvent('evt_invalid', time(), 'active', time() + 604800), JSON_THROW_ON_ERROR);

        $this->call('POST', route('furimadeck.stripe.webhook'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=invalid',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)
            ->assertStatus(400);

        $this->assertSame(0, DB::table('furimadeck_stripe_webhook_events')->count());
    }

    public function test_expired_valid_signature_is_rejected_without_recording_an_event(): void
    {
        $event = $this->subscriptionEvent('evt_expired', time(), 'active', time() + 604800);
        $expiredTimestamp = now()->subMinutes(self::EXPIRED_SIGNATURE_AGE_MINUTES)->timestamp;

        $this->postWebhook($event, $expiredTimestamp)->assertStatus(400);

        $this->assertSame(0, DB::table('furimadeck_stripe_webhook_events')->count());
    }

    public function test_legacy_webhook_is_hidden_during_furimadeck_cutover(): void
    {
        $this->post('/stripe/webhook')->assertNotFound();
    }

    public function test_furimadeck_webhook_is_hidden_before_cutover(): void
    {
        config()->set('furimadeck.cutover_enabled', false);

        $this->post(route('furimadeck.stripe.webhook'))->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function subscriptionEvent(string $eventId, int $createdAt, string $status, int $periodEnd, string $priceId = 'price_furimadeck_980'): array
    {
        return [
            'id' => $eventId,
            'type' => 'customer.subscription.updated',
            'created' => $createdAt,
            'data' => ['object' => [
                'id' => 'sub_furimadeck',
                'customer' => 'cus_furimadeck',
                'status' => $status,
                'items' => ['data' => [['price' => ['id' => $priceId]]]],
                'current_period_end' => $periodEnd,
                'trial_start' => $status === 'trialing' ? $createdAt : null,
                'trial_end' => $status === 'trialing' ? $periodEnd : null,
            ]],
        ];
    }

    /** @param array<string, mixed> $event */
    private function postWebhook(
        array $event,
        ?int $timestamp = null,
        ?array $currentSubscriptions = null,
        int $stripeStatus = 200,
        array $stripePages = [],
    ): TestResponse {
        if (str_starts_with((string) data_get($event, 'type'), 'customer.subscription.')) {
            if ($stripePages !== []) {
                $sequence = Http::sequence();
                foreach ($stripePages as $stripePage) {
                    $sequence->push($stripePage, $stripeStatus);
                }
                Http::fake(['https://stripe.test/v1/subscriptions*' => $sequence]);
            } else {
                $currentSubscriptions ??= [data_get($event, 'data.object')];
                Http::fake([
                    'https://stripe.test/v1/subscriptions*' => Http::response([
                        'data' => $currentSubscriptions,
                        'has_more' => false,
                    ], $stripeStatus),
                ]);
            }
        }

        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($timestamp ?? time());
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_example');

        return $this->call('POST', route('furimadeck.stripe.webhook'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
