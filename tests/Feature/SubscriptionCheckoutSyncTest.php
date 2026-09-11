<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionCheckoutSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_active_stripe_subscription_by_email_blocks_new_checkout(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        Http::fake([
            'https://api.stripe.com/v1/customers*' => Http::response([
                'data' => [
                    ['id' => 'cus_existing'],
                ],
            ]),
            'https://api.stripe.com/v1/subscriptions*' => Http::response([
                'data' => [
                    [
                        'id' => 'sub_existing',
                        'customer' => 'cus_existing',
                        'status' => 'active',
                        'current_period_end' => now()->addMonth()->timestamp,
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect(route('subscriptions.index'))
            ->assertSessionHas('status', 'すでにPremium契約が有効です。契約・解約画面から契約状態を確認できます。');

        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_existing',
            'stripe_subscription_id' => 'sub_existing',
        ]);
    }

    public function test_unverified_email_does_not_claim_existing_stripe_subscription_by_email(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.subscription_price_id' => 'price_test',
        ]);

        Http::fake([
            'https://api.stripe.com/v1/customers?email=claim%40example.com*' => Http::response([
                'data' => [
                    ['id' => 'cus_existing'],
                ],
            ]),
            'https://api.stripe.com/v1/subscriptions?customer=cus_existing*' => Http::response([
                'data' => [
                    [
                        'id' => 'sub_existing',
                        'customer' => 'cus_existing',
                        'status' => 'active',
                        'current_period_end' => now()->addMonth()->timestamp,
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/customers' => Http::response([
                'id' => 'cus_new',
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->unverified()->create([
            'email' => 'claim@example.com',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect(route('verification.notice', absolute: false));

        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://api.stripe.com/v1/customers?email='));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://api.stripe.com/v1/subscriptions?customer=cus_existing'));
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/customers');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);
    }

    public function test_existing_active_subscription_on_another_customer_blocks_new_checkout(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        Http::fake([
            'https://api.stripe.com/v1/subscriptions?customer=cus_old*' => Http::response([
                'data' => [
                    [
                        'id' => 'sub_canceled',
                        'customer' => 'cus_old',
                        'status' => 'canceled',
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/customers*' => Http::response([
                'data' => [
                    ['id' => 'cus_old'],
                    ['id' => 'cus_active'],
                ],
            ]),
            'https://api.stripe.com/v1/subscriptions?customer=cus_active*' => Http::response([
                'data' => [
                    [
                        'id' => 'sub_active',
                        'customer' => 'cus_active',
                        'status' => 'active',
                        'current_period_end' => now()->addMonth()->timestamp,
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => 'canceled',
            'stripe_customer_id' => 'cus_old',
            'stripe_subscription_id' => 'sub_canceled',
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect(route('subscriptions.index'));

        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_active',
            'stripe_subscription_id' => 'sub_active',
        ]);
    }

    public function test_new_checkout_session_includes_subscription_metadata(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.subscription_price_id' => 'price_test',
            'services.stripe.trial_period_days' => 7,
        ]);

        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response([
                'id' => 'cus_new',
            ]),
            'https://api.stripe.com/v1/customers*' => Http::response([
                'data' => [],
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'stripe_customer_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect('https://checkout.stripe.test/session');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['customer'] === 'cus_new'
            && $request['metadata[user_id]'] === (string) $user->id
            && $request['subscription_data[metadata][user_id]'] === (string) $user->id
            && $request['subscription_data[trial_period_days]'] === 7);
    }

    public function test_checkout_retries_with_inline_price_data_when_configured_price_id_is_missing(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.subscription_price_id' => 'price_missing',
            'services.stripe.subscription_amount' => 980,
            'services.stripe.subscription_currency' => 'jpy',
        ]);

        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response([
                'id' => 'cus_new',
            ]),
            'https://api.stripe.com/v1/customers*' => Http::response([
                'data' => [],
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::sequence()
                ->push([
                    'error' => [
                        'type' => 'invalid_request_error',
                        'code' => 'resource_missing',
                        'param' => 'line_items[0][price]',
                    ],
                ], 400)
                ->push([
                    'url' => 'https://checkout.stripe.test/session',
                ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'stripe_customer_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect('https://checkout.stripe.test/session');

        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && ($request['line_items'][0]['price'] ?? null) === 'price_missing');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && ($request['line_items'][0]['price_data']['unit_amount'] ?? null) === 980
            && ($request['line_items'][0]['price_data']['currency'] ?? null) === 'jpy'
            && ! isset($request['line_items'][0]['price']));
    }

    public function test_production_checkout_stops_when_price_id_is_missing(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);

        config([
            'services.stripe.secret' => 'sk_live_example',
            'services.stripe.subscription_price_id' => null,
        ]);

        Http::fake([
            'https://api.stripe.com/v1/customers*' => Http::response([
                'data' => [],
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'stripe_customer_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect(route('subscriptions.index'))
            ->assertSessionHas('error', 'Stripe決済設定が未完了のため、現在Premium申込を停止しています。管理者へお問い合わせください。');

        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions');
    }

    public function test_billing_page_checkout_buttons_include_confirmed_terms(): void
    {
        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertSee('name="billing_terms_confirmed"', false)
            ->assertSee('Stripe決済画面へ進む', false)
            ->assertSee('同意してStripe決済画面へ進む', false);
    }

    public function test_demo_user_cannot_start_stripe_checkout(): void
    {
        config([
            'demo.user_email' => 'demo@example.com',
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.subscription_price_id' => 'price_test',
        ]);

        Http::fake();

        $user = User::factory()->create([
            'email' => 'demo@example.com',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect(route('subscriptions.index'))
            ->assertSessionHas('error', 'デモユーザーではStripe決済を利用できません。実際にPremiumを申し込む場合は、新しいアカウントを作成してください。');

        Http::assertNothingSent();
    }

    public function test_admin_user_cannot_start_stripe_checkout(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.subscription_price_id' => 'price_test',
        ]);

        Http::fake();

        $user = User::factory()->create([
            'is_admin' => true,
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect(route('subscriptions.index'))
            ->assertSessionHas('error', '管理者アカウントは契約なしでPremium機能を利用できます。Stripe決済は不要です。');

        Http::assertNothingSent();
    }

    public function test_billing_page_hides_checkout_buttons_for_demo_user(): void
    {
        config([
            'demo.user_email' => 'demo@example.com',
            'services.stripe.secret' => 'sk_test_example',
        ]);

        Http::fake();

        $user = User::factory()->create([
            'email' => 'demo@example.com',
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertSee('デモユーザーではStripe決済を利用できません。', false)
            ->assertDontSee('Stripe決済画面へ進む', false)
            ->assertDontSee('同意してStripe決済画面へ進む', false);

        Http::assertNothingSent();
    }

    public function test_billing_page_shows_trial_end_date_during_free_trial(): void
    {
        $trialEndsAt = now()->addDays(6)->startOfDay();

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'trialing',
            'stripe_customer_id' => 'cus_trial',
            'stripe_subscription_id' => 'sub_trial',
            'premium_ends_at' => $trialEndsAt,
        ]);

        $this
            ->actingAs($user)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertSee('無料トライアルは '.$trialEndsAt->timezone(config('app.timezone'))->format('Y/m/d H:i').' に終了します');
    }

    public function test_billing_page_shows_recent_stripe_invoices_for_active_stripe_user(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        $createdAt = Carbon::create(2026, 8, 20, 12, 0, 0, 'UTC')->timestamp;

        Http::fake([
            'https://api.stripe.com/v1/subscriptions?customer=cus_invoice*' => Http::response([
                'data' => [
                    [
                        'id' => 'sub_invoice',
                        'customer' => 'cus_invoice',
                        'status' => 'active',
                        'current_period_end' => now()->addMonth()->timestamp,
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/invoices?customer=cus_invoice*' => Http::response([
                'data' => [
                    [
                        'id' => 'in_recent',
                        'number' => 'FURUPRO-2026-0001',
                        'status' => 'paid',
                        'total' => 980,
                        'currency' => 'jpy',
                        'created' => $createdAt,
                        'hosted_invoice_url' => 'https://invoice.stripe.test/in_recent',
                        'invoice_pdf' => 'https://invoice.stripe.test/in_recent.pdf',
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_invoice',
            'stripe_subscription_id' => 'sub_invoice',
        ]);

        $this
            ->actingAs($user)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertSee('請求書', false)
            ->assertSee('FURUPRO-2026-0001', false)
            ->assertSee('¥980', false)
            ->assertSee('支払い済み', false)
            ->assertSee('https://invoice.stripe.test/in_recent', false)
            ->assertSee('https://invoice.stripe.test/in_recent.pdf', false);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.stripe.com/v1/invoices')
            && $request['customer'] === 'cus_invoice'
            && $request['limit'] === 5);
    }

    public function test_billing_page_stays_available_when_stripe_invoice_lookup_fails(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        Http::fake([
            'https://api.stripe.com/v1/subscriptions?customer=cus_invoice_failure*' => Http::response([
                'data' => [
                    [
                        'id' => 'sub_invoice_failure',
                        'customer' => 'cus_invoice_failure',
                        'status' => 'active',
                        'current_period_end' => now()->addMonth()->timestamp,
                    ],
                ],
            ]),
            'https://api.stripe.com/v1/invoices?customer=cus_invoice_failure*' => Http::response([
                'error' => [
                    'type' => 'api_error',
                    'code' => 'temporary_failure',
                ],
            ], 500),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_invoice_failure',
            'stripe_subscription_id' => 'sub_invoice_failure',
        ]);

        $this
            ->actingAs($user)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertSee('請求書', false)
            ->assertSee('現在、請求書を取得できませんでした。', false);
    }

    public function test_billing_page_hides_trial_end_date_after_trial_status(): void
    {
        $trialEndsAt = now()->addDays(20)->startOfDay();

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_active',
            'stripe_subscription_id' => 'sub_active',
            'premium_ends_at' => $trialEndsAt,
        ]);

        $this
            ->actingAs($user)
            ->get(route('subscriptions.index'))
            ->assertOk()
            ->assertDontSee('無料トライアルは '.$trialEndsAt->timezone(config('app.timezone'))->format('Y/m/d H:i').' に終了します');
    }

    public function test_trial_is_not_added_after_user_has_used_trial(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.subscription_price_id' => 'price_test',
            'services.stripe.trial_period_days' => 7,
        ]);

        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response([
                'id' => 'cus_new',
            ]),
            'https://api.stripe.com/v1/customers*' => Http::response([
                'data' => [],
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'stripe_customer_id' => null,
            'trial_used_at' => now()->subMonth(),
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect('https://checkout.stripe.test/session');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['customer'] === 'cus_new'
            && ! isset($request['subscription_data[trial_period_days]']));
    }

    public function test_local_premium_without_stripe_subscription_can_start_checkout(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_example',
            'services.stripe.subscription_price_id' => 'price_test',
        ]);

        Http::fake([
            'https://api.stripe.com/v1/customers' => Http::response([
                'id' => 'cus_new',
            ]),
            'https://api.stripe.com/v1/customers*' => Http::response([
                'data' => [],
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect('https://checkout.stripe.test/session');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['customer'] === 'cus_new');
    }

    public function test_stale_stripe_ids_from_previous_key_can_start_new_checkout(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_new_key',
            'services.stripe.subscription_price_id' => 'price_test',
        ]);

        Http::fake([
            'https://api.stripe.com/v1/subscriptions?customer=cus_old*' => Http::response([], 404),
            'https://api.stripe.com/v1/customers?email=stale%40example.com*' => Http::response([
                'data' => [],
            ]),
            'https://api.stripe.com/v1/customers/cus_old' => Http::response([], 404),
            'https://api.stripe.com/v1/customers' => Http::response([
                'id' => 'cus_new',
            ]),
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'url' => 'https://checkout.stripe.test/session',
            ]),
        ]);

        $user = User::factory()->create([
            'email' => 'stale@example.com',
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
            'stripe_customer_id' => 'cus_old',
            'stripe_subscription_id' => 'sub_old',
        ]);

        $this
            ->actingAs($user)
            ->post(route('subscriptions.checkout'), [
                'billing_terms_confirmed' => '1',
            ])
            ->assertRedirect('https://checkout.stripe.test/session');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['customer'] === 'cus_new');
    }

    public function test_trial_subscription_sync_marks_trial_as_used(): void
    {
        config(['services.stripe.secret' => 'sk_test_example']);

        $trialEnd = now()->addDays(7)->timestamp;

        $user = User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => null,
            'stripe_customer_id' => null,
            'stripe_subscription_id' => null,
            'trial_used_at' => null,
        ]);

        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions/cs_test_trial*' => Http::response([
                'id' => 'cs_test_trial',
                'client_reference_id' => (string) $user->id,
                'metadata' => ['user_id' => (string) $user->id],
                'customer' => 'cus_trial',
                'subscription' => [
                    'id' => 'sub_trial',
                    'customer' => 'cus_trial',
                    'status' => 'trialing',
                    'trial_start' => now()->timestamp,
                    'trial_end' => $trialEnd,
                    'current_period_end' => $trialEnd,
                ],
            ]),
        ]);

        $this
            ->actingAs($user)
            ->get(route('subscriptions.success', ['session_id' => 'cs_test_trial']))
            ->assertRedirect(route('subscriptions.index'));

        $user->refresh();

        $this->assertSame(User::SUBSCRIPTION_ACTIVE, $user->subscription_plan);
        $this->assertSame('trialing', $user->subscription_status);
        $this->assertNotNull($user->trial_used_at);
        $this->assertSame(
            Carbon::createFromTimestamp($trialEnd)->toDateTimeString(),
            $user->premium_ends_at?->toDateTimeString()
        );
    }
}
