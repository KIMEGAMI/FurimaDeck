<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $this->postWebhook($this->subscriptionEvent('evt_newer', $newEventTime, 'active', $newEventTime + 604800))->assertOk();
        $this->postWebhook($this->subscriptionEvent('evt_older', $newEventTime - 60, 'canceled', $newEventTime - 1))->assertOk();

        $this->assertSame('active', $user->fresh()->subscription_status);
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
    private function subscriptionEvent(string $eventId, int $createdAt, string $status, int $periodEnd): array
    {
        return [
            'id' => $eventId,
            'type' => 'customer.subscription.updated',
            'created' => $createdAt,
            'data' => ['object' => [
                'id' => 'sub_furimadeck',
                'customer' => 'cus_furimadeck',
                'status' => $status,
                'current_period_end' => $periodEnd,
                'trial_start' => $status === 'trialing' ? $createdAt : null,
                'trial_end' => $status === 'trialing' ? $periodEnd : null,
            ]],
        ];
    }

    /** @param array<string, mixed> $event */
    private function postWebhook(array $event, ?int $timestamp = null): TestResponse
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($timestamp ?? time());
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_example');

        return $this->call('POST', route('furimadeck.stripe.webhook'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
