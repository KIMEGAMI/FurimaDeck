<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscriptionCheckoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'billing_terms_confirmed' => ['accepted'],
        ], [
            'billing_terms_confirmed.accepted' => 'Premiumプランの料金、自動更新、解約条件を確認してチェックを入れてください。',
        ]);

        $user = $request->user();
        $secret = config('services.stripe.secret');

        if ($user->isDemoUser()) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'デモユーザーではStripe決済を利用できません。実際にPremiumを申し込む場合は、新しいアカウントを作成してください。');
        }

        if ($user->isAdmin()) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', '管理者アカウントは契約なしでPremium機能を利用できます。Stripe決済は不要です。');
        }

        if ($this->syncKnownStripeSubscriptionForUser($user)) {
            return $this->alreadySubscribed();
        }

        if (! is_string($secret) || $secret === '') {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripeの設定が未完了です。STRIPE_SECRET または STRIPE_SECRET_KEY を設定してください。');
        }

        if ($this->requiresConfiguredPriceId() && ! $this->hasConfiguredPriceId()) {
            Log::error('Stripe checkout blocked because production price id is not configured.', [
                'user_id' => $user->id,
            ]);

            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripe決済設定が未完了のため、現在Premium申込を停止しています。管理者へお問い合わせください。');
        }

        try {
            $customerId = $this->ensureStripeCustomer($user, $secret);
            $response = Http::asForm()
                ->timeout(10)
                ->withToken($secret)
                ->post($this->stripeApiBase().'/checkout/sessions', $this->checkoutPayload($user, $customerId));

            if ($this->shouldRetryCheckoutWithInlinePrice($response)) {
                Log::warning('Stripe checkout price id was not usable; retrying with inline price data.', $this->stripeFailureLogContext($response, $user));

                $response = Http::asForm()
                    ->timeout(10)
                    ->withToken($secret)
                    ->post($this->stripeApiBase().'/checkout/sessions', $this->checkoutPayload($user, $customerId, forceInlinePrice: true));
            }
        } catch (Throwable $exception) {
            Log::warning('Stripe checkout request failed.', [
                'user_id' => $user->id,
                'error_class' => $exception::class,
            ]);

            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripeに接続できませんでした。Stripeキー、ネットワーク、またはStripe側の設定を確認してください。');
        }

        if ($response->failed()) {
            Log::warning('Stripe checkout session creation failed.', $this->stripeFailureLogContext($response, $user));

            return redirect()
                ->route('subscriptions.index')
                ->with('error', $this->stripeFailureMessage($response));
        }

        $checkoutUrl = $response->json('url');

        if (! is_string($checkoutUrl) || $checkoutUrl === '') {
            Log::warning('Stripe checkout session did not include url.', [
                'user_id' => $user->id,
                'stripe_request_id' => $response->header('Request-Id'),
            ]);

            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripe決済画面のURLを取得できませんでした。Stripe側の応答を確認してください。');
        }

        return redirect()->away($checkoutUrl);
    }

    private function alreadySubscribed(): RedirectResponse
    {
        return redirect()
            ->route('subscriptions.index')
            ->with('status', 'すでにPremium契約が有効です。契約・解約画面から契約状態を確認できます。');
    }

    private function ensureStripeCustomer(User $user, string $secret): string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            if ($this->stripeCustomerExists($user->stripe_customer_id, $secret)) {
                return $user->stripe_customer_id;
            }
        }

        $response = Http::asForm()
            ->timeout(10)
            ->withToken($secret)
            ->post($this->stripeApiBase().'/customers', [
                'email' => $user->email,
                'name' => $user->name,
                'metadata[user_id]' => (string) $user->id,
            ]);

        $response->throw();

        $customerId = (string) $response->json('id');
        $user->forceFill(['stripe_customer_id' => $customerId])->save();

        return $customerId;
    }

    private function stripeCustomerExists(string $customerId, string $secret): bool
    {
        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/customers/'.rawurlencode($customerId));
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && $response->json('deleted') !== true;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(User $user, string $customerId, bool $forceInlinePrice = false): array
    {
        $payload = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [$this->subscriptionLineItem($forceInlinePrice)],
            'success_url' => route('subscriptions.success', [], true).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('subscriptions.index', [], true),
            'locale' => config('services.stripe.checkout_locale', 'ja'),
            'client_reference_id' => (string) $user->id,
            'metadata[user_id]' => (string) $user->id,
            'subscription_data[metadata][user_id]' => (string) $user->id,
        ];

        if ($this->trialPeriodDays() > 0 && $user->trial_used_at === null) {
            $payload['subscription_data[trial_period_days]'] = $this->trialPeriodDays();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionLineItem(bool $forceInlinePrice = false): array
    {
        $priceId = config('services.stripe.subscription_price_id');

        if (! $forceInlinePrice && is_string($priceId) && $priceId !== '') {
            return [
                'price' => $priceId,
                'quantity' => 1,
            ];
        }

        return [
            'quantity' => 1,
            'price_data' => [
                'currency' => config('services.stripe.subscription_currency', 'jpy'),
                'unit_amount' => (int) config('services.stripe.subscription_amount', 480),
                'recurring' => ['interval' => 'month'],
                'product_data' => [
                    'name' => config('services.stripe.subscription_product_name', 'FurimaDeck Premium'),
                    'description' => config('services.stripe.subscription_product_description', 'FurimaDeck paid subscription.'),
                ],
            ],
        ];
    }

    private function shouldRetryCheckoutWithInlinePrice(HttpResponse $response): bool
    {
        return $response->failed()
            && ! $this->requiresConfiguredPriceId()
            && $response->json('error.param') === 'line_items[0][price]'
            && filled(config('services.stripe.subscription_price_id'));
    }

    private function requiresConfiguredPriceId(): bool
    {
        return app()->environment('production');
    }

    private function hasConfiguredPriceId(): bool
    {
        $priceId = config('services.stripe.subscription_price_id');

        return is_string($priceId) && str_starts_with($priceId, 'price_');
    }

    private function syncKnownStripeSubscriptionForUser(User $user): bool
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            if ($this->syncLatestSubscriptionForCustomer($user, $user->stripe_customer_id)) {
                $user->refresh();

                if ($user->hasActiveSubscription()) {
                    return true;
                }
            }
        }

        return $this->syncLatestSubscriptionByEmail($user);
    }

    private function syncLatestSubscriptionByEmail(User $user): bool
    {
        $secret = config('services.stripe.secret');

        if (! $user->hasVerifiedEmail() || ! is_string($secret) || $secret === '' || ! is_string($user->email) || $user->email === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/customers', [
                    'email' => $user->email,
                    'limit' => 10,
                ]);
        } catch (Throwable) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        $customers = $response->json('data');

        if (! is_array($customers)) {
            return false;
        }

        foreach ($customers as $customer) {
            if (! is_array($customer)) {
                continue;
            }

            $customerId = data_get($customer, 'id');

            if (! is_string($customerId) || $customerId === '') {
                continue;
            }

            $user->forceFill(['stripe_customer_id' => $customerId])->save();

            if ($this->syncLatestSubscriptionForCustomer($user, $customerId)) {
                $user->refresh();

                if ($user->hasActiveSubscription()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function syncLatestSubscriptionForCustomer(User $user, string $customerId): bool
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/subscriptions', [
                    'customer' => $customerId,
                    'status' => 'all',
                    'limit' => 10,
                ]);
        } catch (Throwable) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        $subscriptions = $response->json('data');

        if (! is_array($subscriptions) || $subscriptions === []) {
            return false;
        }

        $subscription = collect($subscriptions)
            ->first(fn (mixed $item): bool => is_array($item) && in_array(data_get($item, 'status'), ['active', 'trialing'], true));

        if (! is_array($subscription)) {
            return false;
        }

        $this->syncSubscriptionPayload($user, $subscription);

        return true;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function syncSubscriptionPayload(User $user, array $subscription): void
    {
        $subscriptionId = data_get($subscription, 'id');
        $customerId = data_get($subscription, 'customer');
        $status = (string) data_get($subscription, 'status', '');
        $periodEnd = data_get($subscription, 'current_period_end');
        $trialStart = data_get($subscription, 'trial_start');
        $trialEnd = data_get($subscription, 'trial_end');
        $usedTrial = $status === 'trialing' || is_numeric($trialStart) || is_numeric($trialEnd);
        $subscriptionEndsAt = $this->subscriptionEndsAtTimestamp($subscription);

        $user->forceFill([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => $status ?: null,
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'premium_started_at' => $user->premium_started_at ?? now(),
            'premium_ends_at' => $subscriptionEndsAt !== null ? Carbon::createFromTimestamp($subscriptionEndsAt) : $user->premium_ends_at,
            'trial_used_at' => $usedTrial && $user->trial_used_at === null ? now() : $user->trial_used_at,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionEndsAtTimestamp(array $subscription): ?int
    {
        $periodEnd = data_get($subscription, 'current_period_end');

        if (is_numeric($periodEnd)) {
            return (int) $periodEnd;
        }

        $trialEnd = data_get($subscription, 'trial_end');

        if (data_get($subscription, 'status') === 'trialing' && is_numeric($trialEnd)) {
            return (int) $trialEnd;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stripeFailureLogContext(HttpResponse $response, User $user): array
    {
        return [
            'user_id' => $user->id,
            'http_status' => $response->status(),
            'stripe_error_type' => $response->json('error.type'),
            'stripe_error_code' => $response->json('error.code'),
            'stripe_error_param' => $response->json('error.param'),
            'stripe_request_id' => $response->header('Request-Id'),
        ];
    }

    private function stripeFailureMessage(HttpResponse $response): string
    {
        $type = $response->json('error.type');
        $code = $response->json('error.code');
        $param = $response->json('error.param');

        if ($type === 'authentication_error') {
            return 'Stripe決済画面を作成できませんでした。STRIPE_SECRET または STRIPE_SECRET_KEY が正しいか確認してください。';
        }

        if ($param === 'line_items[0][price]') {
            return 'Stripe決済画面を作成できませんでした。STRIPE_PREMIUM_PRICE_ID / STRIPE_SUBSCRIPTION_PRICE_ID が、現在のStripeキーと同じテスト/本番モードのPrice IDか確認してください。';
        }

        if ($param === 'success_url' || $param === 'cancel_url') {
            return 'Stripe決済画面を作成できませんでした。APP_URL が正しいURLになっているか確認してください。';
        }

        if ($param === 'customer') {
            return 'Stripe決済画面を作成できませんでした。保存済みのStripe顧客IDが現在のStripeキーで見つかりません。契約状態を再確認してから、もう一度お試しください。';
        }

        if (is_string($code) && $code !== '') {
            return 'Stripe決済画面を作成できませんでした。Stripeエラーコード: '.$code;
        }

        return 'Stripe決済画面を作成できませんでした。Stripe設定を確認してください。';
    }

    private function stripeApiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base'), '/');
    }

    private function trialPeriodDays(): int
    {
        return max(0, (int) config('services.stripe.trial_period_days', 7));
    }
}
