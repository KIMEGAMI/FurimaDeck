<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FurimaDeckBillingCheckoutTest extends TestCase
{
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
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('furimadeck.billing.stripe_api_base', 'https://stripe.test/v1');
        config()->set('furimadeck.billing.stripe_secret', 'sk_test_example');
        config()->set('furimadeck.billing.stripe_price_id', 'price_furimadeck_980');
        config()->set('furimadeck.billing.trial_period_days', 7);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_checkout_uses_configured_price_and_initial_trial_only(): void
    {
        Http::fake([
            'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_furimadeck'], 200),
            'https://stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/session'], 200),
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect('https://checkout.stripe.com/session');

        Http::assertSent(fn ($request) => $request->url() === 'https://stripe.test/v1/checkout/sessions'
            && $request['line_items[0][price]'] === 'price_furimadeck_980'
            && $request['subscription_data[trial_period_days]'] === 7);
        $this->assertSame('cus_furimadeck', $user->fresh()->stripe_customer_id);
    }

    public function test_repeated_checkout_requests_reuse_the_same_idempotency_key(): void
    {
        $previousCacheStore = config('cache.default');
        $previousDatabaseCacheConnection = config('cache.stores.database.connection');
        config()->set('cache.default', 'database');
        config()->set('cache.stores.database.connection', 'furimadeck');
        Cache::purge('database');

        $checkoutKeys = [];
        $customerKeys = [];
        Http::fake(function ($request) use (&$checkoutKeys, &$customerKeys) {
            if ($request->url() === 'https://stripe.test/v1/customers') {
                $customerKeys[] = $request->header('Idempotency-Key')[0] ?? null;

                return Http::response(['id' => 'cus_idempotent'], 200);
            }

            if ($request->url() === 'https://stripe.test/v1/customers/cus_idempotent') {
                return Http::response(['id' => 'cus_idempotent', 'deleted' => false], 200);
            }

            if (str_starts_with($request->url(), 'https://stripe.test/v1/subscriptions')) {
                return Http::response(['data' => []], 200);
            }

            if ($request->url() === 'https://stripe.test/v1/checkout/sessions') {
                $checkoutKeys[] = $request->header('Idempotency-Key')[0] ?? null;

                return Http::response(['url' => 'https://checkout.stripe.com/idempotent-session'], 200);
            }

            return Http::response([], 404);
        });
        try {
            $user = User::factory()->create(['email_verified_at' => now()]);

            foreach ([1, 2] as $_attempt) {
                $this->actingAs($user)
                    ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
                    ->assertRedirect('https://checkout.stripe.com/idempotent-session');
            }

            $this->assertCount(1, $customerKeys);
            $this->assertCount(2, $checkoutKeys);
            $this->assertIsString($customerKeys[0]);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $customerKeys[0]);
            $this->assertSame($checkoutKeys[0], $checkoutKeys[1]);
            $this->assertIsString($checkoutKeys[0]);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $checkoutKeys[0]);
            $this->assertSame(0, DB::table('cache_locks')->count());
            $this->assertIsArray(Cache::store('database')->get('furimadeck:billing:idempotency:checkout:user:'.$user->id));
        } finally {
            Cache::purge('database');
            config()->set('cache.default', $previousCacheStore);
            config()->set('cache.stores.database.connection', $previousDatabaseCacheConnection);
            Cache::purge('database');
        }
    }

    public function test_checkout_fails_closed_if_the_pending_idempotency_payload_changes(): void
    {
        Http::fake([
            'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_pending'], 200),
            'https://stripe.test/v1/customers/cus_pending' => Http::response(['id' => 'cus_pending', 'deleted' => false], 200),
            'https://stripe.test/v1/subscriptions*' => Http::response(['data' => []], 200),
            'https://stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/pending-session'], 200),
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect('https://checkout.stripe.com/pending-session');

        config()->set('furimadeck.billing.stripe_price_id', 'price_changed_during_pending_checkout');

        $this->actingAs($user)
            ->from(route('furimadeck-billing.index'))
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error', '前回の申込処理がまだ有効です。重複決済を防ぐため、少し時間をおいてから再度お試しください。');

        Http::assertSentCount(4);
    }

    public function test_checkout_passes_configured_three_d_secure_mode(): void
    {
        config()->set('furimadeck.billing.stripe_3ds_request', 'challenge');
        Http::fake([
            'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_3ds'], 200),
            'https://stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/session'], 200),
        ]);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])->assertRedirect('https://checkout.stripe.com/session');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://stripe.test/v1/checkout/sessions' && $request['payment_method_options[card][request_three_d_secure]'] === 'challenge');
    }

    public function test_checkout_success_syncs_the_owned_stripe_subscription_without_waiting_for_webhook(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_checkout_test',
        ]);
        Http::fake([
            'https://stripe.test/v1/checkout/sessions/cs_test_complete*' => Http::response([
                'id' => 'cs_test_complete',
                'mode' => 'subscription',
                'status' => 'complete',
                'customer' => 'cus_checkout_test',
                'client_reference_id' => (string) $user->id,
                'metadata' => ['user_id' => (string) $user->id],
                'subscription' => [
                    'id' => 'sub_checkout_test',
                    'customer' => 'cus_checkout_test',
                    'status' => 'trialing',
                    'items' => ['data' => [['price' => ['id' => 'price_furimadeck_980']]]],
                    'current_period_end' => now()->addDays(7)->timestamp,
                    'trial_end' => now()->addDays(7)->timestamp,
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-billing.success', ['session_id' => 'cs_test_complete']))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('trialing', $user->subscription_status);
        $this->assertSame('sub_checkout_test', $user->stripe_subscription_id);
        $this->assertNotNull($user->trial_used_at);
    }

    public function test_billing_page_syncs_subscription_when_webhook_has_not_updated_the_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_webhook_delayed',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
        ]);
        Http::fake([
            'https://stripe.test/v1/subscriptions*' => Http::response(['data' => [[
                'id' => 'sub_webhook_delayed',
                'customer' => 'cus_webhook_delayed',
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_furimadeck_980']]]],
                'current_period_end' => now()->addMonth()->timestamp,
                'created' => now()->timestamp,
            ]]]),
            'https://stripe.test/v1/invoices*' => Http::response(['data' => []]),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-billing.index'))
            ->assertOk()
            ->assertSee('Stripeで契約を管理')
            ->assertDontSee('無料お試しを開始');

        $user->refresh();
        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('active', $user->subscription_status);
        $this->assertSame('sub_webhook_delayed', $user->stripe_subscription_id);
    }

    public function test_checkout_does_not_create_a_second_subscription_when_stripe_reports_an_active_one(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_already_active',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
        ]);
        Http::fake([
            'https://stripe.test/v1/subscriptions*' => Http::response(['data' => [[
                'id' => 'sub_already_active',
                'customer' => 'cus_already_active',
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_furimadeck_980']]]],
                'current_period_end' => now()->addMonth()->timestamp,
                'created' => now()->timestamp,
            ]]]),
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');

        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/checkout/sessions'));
    }

    public function test_checkout_checks_later_subscription_pages_before_creating_a_new_subscription(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_active_on_later_page',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
        ]);
        Http::fakeSequence('https://stripe.test/v1/subscriptions*')
            ->push([
                'data' => [[
                    'id' => 'sub_old_page',
                    'customer' => 'cus_active_on_later_page',
                    'status' => 'canceled',
                    'items' => ['data' => [['price' => ['id' => 'price_furimadeck_980']]]],
                    'created' => now()->subYear()->timestamp,
                ]],
                'has_more' => true,
            ])
            ->push([
                'data' => [[
                    'id' => 'sub_active_later_page',
                    'customer' => 'cus_active_on_later_page',
                    'status' => 'active',
                    'items' => ['data' => [['price' => ['id' => 'price_furimadeck_980']]]],
                    'current_period_end' => now()->addMonth()->timestamp,
                    'created' => now()->timestamp,
                ]],
                'has_more' => false,
            ]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v1/subscriptions'
            && data_get($request->data(), 'starting_after') === 'sub_old_page');
        Http::assertNotSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v1/checkout/sessions');
    }

    public function test_checkout_fails_closed_when_stripe_pagination_cursor_is_missing(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_missing_pagination_cursor',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
        ]);
        Http::fake([
            'https://stripe.test/v1/subscriptions*' => Http::response([
                'data' => [[
                    'customer' => 'cus_missing_pagination_cursor',
                    'status' => 'canceled',
                    'items' => ['data' => [['price' => ['id' => 'price_furimadeck_980']]]],
                ]],
                'has_more' => true,
            ]),
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error', 'Stripeの契約状態を確認できませんでした。重複決済を防ぐため、新しい申込を停止しました。時間をおいて再度お試しください。');

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/v1/checkout/sessions');
    }

    public function test_checkout_does_not_accept_a_subscription_for_another_price(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_wrong_price',
        ]);
        Http::fake([
            'https://stripe.test/v1/checkout/sessions/cs_wrong_price*' => Http::response([
                'id' => 'cs_wrong_price',
                'mode' => 'subscription',
                'status' => 'complete',
                'customer' => 'cus_wrong_price',
                'client_reference_id' => (string) $user->id,
                'subscription' => [
                    'id' => 'sub_wrong_price',
                    'customer' => 'cus_wrong_price',
                    'status' => 'active',
                    'items' => ['data' => [['price' => ['id' => 'price_another_product']]]],
                ],
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-billing.success', ['session_id' => 'cs_wrong_price']))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');

        $this->assertSame(User::SUBSCRIPTION_INACTIVE, $user->fresh()->subscription_plan);
        $this->assertNull($user->fresh()->stripe_subscription_id);
    }

    public function test_another_active_price_does_not_preserve_stale_premium_or_block_correct_checkout(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_wrong_existing_price',
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_subscription_id' => 'sub_wrong_existing_price',
            'premium_ends_at' => now()->addMonth(),
        ]);
        Http::fake([
            'https://stripe.test/v1/customers/cus_wrong_existing_price' => Http::response(['id' => 'cus_wrong_existing_price', 'deleted' => false]),
            'https://stripe.test/v1/subscriptions*' => Http::response(['data' => [[
                'id' => 'sub_wrong_existing_price',
                'customer' => 'cus_wrong_existing_price',
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_another_product']]]],
                'current_period_end' => now()->addMonth()->timestamp,
                'created' => now()->timestamp,
            ]]]),
            'https://stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://checkout.stripe.com/session'], 200),
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect('https://checkout.stripe.com/session');

        $this->assertSame(User::SUBSCRIPTION_INACTIVE, $user->fresh()->subscription_plan);
        $this->assertNull($user->fresh()->subscription_status);
    }

    public function test_checkout_rejects_a_non_stripe_redirect_url(): void
    {
        Http::fake([
            'https://stripe.test/v1/checkout/sessions' => Http::response(['url' => 'https://attacker.example/checkout'], 200),
        ]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');
    }

    public function test_portal_rejects_a_non_stripe_redirect_url(): void
    {
        Http::fake([
            'https://stripe.test/v1/billing_portal/sessions' => Http::response(['url' => 'https://attacker.example/portal'], 200),
        ]);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.portal'))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');
    }

    public function test_checkout_fails_closed_when_price_id_is_missing(): void
    {
        config()->set('furimadeck.billing.stripe_price_id', null);
        Http::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'), ['billing_terms_confirmed' => '1'])
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_admin_billing_is_complimentary_and_never_calls_stripe(): void
    {
        Http::fake();
        $user = User::factory()->create([
            'is_admin' => true,
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-billing.index'))
            ->assertOk()
            ->assertSee('管理者アカウント（決済不要）')
            ->assertDontSee('Stripeで契約を管理')
            ->assertDontSee('無料お試しを開始');

        $this->actingAs($user)
            ->post(route('furimadeck-billing.checkout'))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->post(route('furimadeck-billing.portal'))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_billing_page_hides_non_stripe_invoice_urls(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);
        Http::fake([
            'https://stripe.test/v1/invoices*' => Http::response(['data' => [[
                'customer' => 'cus_furimadeck',
                'number' => 'FURIMA-UNSAFE',
                'status' => 'paid',
                'amount_paid' => 980,
                'invoice_pdf' => 'https://attacker.example/invoice.pdf',
            ]]]),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-billing.index'))
            ->assertOk()
            ->assertSee('FURIMA-UNSAFE')
            ->assertDontSee('attacker.example');
    }

    public function test_billing_page_shows_only_invoices_for_the_authenticated_customer(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'stripe_customer_id' => 'cus_furimadeck',
        ]);
        Http::fake([
            'https://stripe.test/v1/invoices*' => Http::response(['data' => [
                [
                    'customer' => 'cus_furimadeck',
                    'number' => 'FURIMA-001',
                    'status' => 'paid',
                    'amount_paid' => 980,
                    'status_transitions' => ['paid_at' => now()->timestamp],
                    'invoice_pdf' => 'https://invoice.stripe.com/invoices/one.pdf',
                ],
                [
                    'customer' => 'cus_another_user',
                    'number' => 'OTHER-001',
                    'status' => 'paid',
                    'amount_paid' => 980,
                ],
            ]], 200),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-billing.index'))
            ->assertOk()
            ->assertSee('FURIMA-001')
            ->assertDontSee('OTHER-001');
    }
}
