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

class SubscriptionPortalController extends Controller
{
    public function portal(Request $request): RedirectResponse
    {
        $user = $request->user();
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return $this->backWithError('Stripeの設定が未完了です。STRIPE_SECRET または STRIPE_SECRET_KEY を設定してください。');
        }

        $customerId = $this->resolveCustomerId($user, $secret);

        if (! is_string($customerId) || $customerId === '') {
            return $this->backWithError('契約管理画面を開けませんでした。Stripeの顧客情報または契約情報を確認してください。');
        }

        return $this->createPortalSession($user, $secret, [
            'customer' => $customerId,
            'return_url' => route('subscriptions.index', [], true),
        ]);
    }

    public function cancelFeedback(Request $request): RedirectResponse
    {
        $user = $request->user();
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return $this->backWithError('Stripeの設定が未完了です。STRIPE_SECRET または STRIPE_SECRET_KEY を設定してください。');
        }

        $customerId = $this->resolveCustomerId($user, $secret);

        if (! is_string($customerId) || $customerId === '') {
            return $this->backWithError('解約画面を開けませんでした。Stripeの顧客情報または契約情報を確認してください。');
        }

        $user->refresh();
        $subscriptionId = $this->resolveCancelableSubscriptionId($user, $customerId, $secret);

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return $this->backWithError('解約画面を開けませんでした。Stripeの契約情報を確認してください。');
        }

        $returnUrl = route('subscriptions.index', [], true);

        return $this->createPortalSession($user, $secret, [
            'customer' => $customerId,
            'return_url' => $returnUrl,
            'flow_data' => [
                'type' => 'subscription_cancel',
                'subscription_cancel' => [
                    'subscription' => $subscriptionId,
                ],
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'return_url' => $returnUrl,
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createPortalSession(User $user, string $secret, array $payload): RedirectResponse
    {
        try {
            $configurationId = config('services.stripe.portal_configuration_id');

            if (is_string($configurationId) && $configurationId !== '') {
                $payload['configuration'] = $configurationId;
            }

            $response = Http::asForm()
                ->timeout(10)
                ->withToken($secret)
                ->post($this->stripeApiBase().'/billing_portal/sessions', $payload);
        } catch (Throwable $exception) {
            Log::warning('Stripe portal session request failed.', [
                'user_id' => $user->id,
                'error_class' => $exception::class,
            ]);

            return $this->backWithError('Stripeに接続できませんでした。ネットワークまたはStripe設定を確認してください。');
        }

        if ($response->failed()) {
            Log::warning('Stripe portal session creation failed.', $this->stripeFailureLogContext($response, $user));

            return $this->backWithError($this->stripeFailureMessage($response));
        }

        $portalUrl = $response->json('url');

        if (! is_string($portalUrl) || $portalUrl === '') {
            Log::warning('Stripe portal session did not include url.', [
                'user_id' => $user->id,
                'stripe_request_id' => $response->header('Request-Id'),
            ]);

            return $this->backWithError('契約管理画面のURLを取得できませんでした。Stripe側の応答を確認してください。');
        }

        return redirect()->away($portalUrl);
    }

    private function resolveCustomerId(User $user, string $secret): ?string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            if ($this->stripeCustomerExists($user->stripe_customer_id, $secret)) {
                return $user->stripe_customer_id;
            }
        }

        if (is_string($user->stripe_subscription_id) && $user->stripe_subscription_id !== '') {
            $subscription = $this->fetchSubscription($user->stripe_subscription_id, $secret);
            $customerId = data_get($subscription, 'customer');

            if (is_string($customerId) && $customerId !== '') {
                $this->syncSubscriptionPayload($user, $subscription);

                return $customerId;
            }
        }

        return $this->resolveCustomerIdByEmail($user, $secret);
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

    private function resolveCancelableSubscriptionId(User $user, string $customerId, string $secret): ?string
    {
        if (is_string($user->stripe_subscription_id) && $user->stripe_subscription_id !== '') {
            $subscription = $this->fetchSubscription($user->stripe_subscription_id, $secret);

            if ($this->isCancelableSubscription($subscription)) {
                $this->syncSubscriptionPayload($user, $subscription);

                $subscriptionId = data_get($subscription, 'id');

                return is_string($subscriptionId) && $subscriptionId !== '' ? $subscriptionId : null;
            }
        }

        $subscription = $this->fetchActiveSubscriptionForCustomer($customerId, $secret);

        if (! $this->isCancelableSubscription($subscription)) {
            return null;
        }

        $this->syncSubscriptionPayload($user, $subscription);

        $subscriptionId = data_get($subscription, 'id');

        return is_string($subscriptionId) && $subscriptionId !== '' ? $subscriptionId : null;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function isCancelableSubscription(array $subscription): bool
    {
        return in_array(data_get($subscription, 'status'), ['active', 'trialing'], true)
            && is_string(data_get($subscription, 'id'))
            && data_get($subscription, 'id') !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSubscription(string $subscriptionId, string $secret): array
    {
        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/subscriptions/'.rawurlencode($subscriptionId));
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function resolveCustomerIdByEmail(User $user, string $secret): ?string
    {
        if (! $user->hasVerifiedEmail()) {
            return null;
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
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $customers = $response->json('data');

        if (! is_array($customers)) {
            return null;
        }

        foreach ($customers as $customer) {
            if (! is_array($customer)) {
                continue;
            }

            $customerId = data_get($customer, 'id');

            if (! is_string($customerId) || $customerId === '') {
                continue;
            }

            $subscription = $this->fetchActiveSubscriptionForCustomer($customerId, $secret);

            if ($subscription !== []) {
                $this->syncSubscriptionPayload($user, $subscription);

                return $customerId;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchActiveSubscriptionForCustomer(string $customerId, string $secret): array
    {
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
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $subscriptions = $response->json('data');

        if (! is_array($subscriptions)) {
            return [];
        }

        $subscription = collect($subscriptions)
            ->first(fn (mixed $item): bool => is_array($item) && in_array(data_get($item, 'status'), ['active', 'trialing'], true));

        return is_array($subscription) ? $subscription : [];
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
        $isActive = in_array($status, ['active', 'trialing'], true);
        $usedTrial = $status === 'trialing' || is_numeric($trialStart) || is_numeric($trialEnd);
        $subscriptionEndsAt = $this->subscriptionEndsAtTimestamp($subscription);

        $user->forceFill([
            'subscription_plan' => $isActive ? User::SUBSCRIPTION_ACTIVE : User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => $status ?: null,
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'premium_started_at' => $isActive && $user->premium_started_at === null ? now() : $user->premium_started_at,
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
            return '契約管理画面を作成できませんでした。STRIPE_SECRET または STRIPE_SECRET_KEY が正しいか確認してください。';
        }

        if ($code === 'billing_portal_not_configured' || $code === 'customer_portal_not_configured') {
            return '契約管理画面を作成できませんでした。StripeダッシュボードでCustomer portalの設定を保存してください。';
        }

        if ($param === 'customer') {
            return '契約管理画面を作成できませんでした。Stripe顧客IDが現在のStripeキーで見つかりません。テスト/本番キーの組み合わせを確認してください。';
        }

        if ($param === 'configuration') {
            return '契約管理画面を作成できませんでした。STRIPE_PORTAL_CONFIGURATION_ID が現在のStripeキーと一致しているか確認してください。';
        }

        if ($param === 'return_url') {
            return '契約管理画面を作成できませんでした。APP_URL が正しいURLになっているか確認してください。';
        }

        if (is_string($code) && $code !== '') {
            return '契約管理画面を作成できませんでした。Stripeエラーコード: '.$code;
        }

        return '契約管理画面を作成できませんでした。Stripe設定を確認してください。';
    }

    private function backWithError(string $message): RedirectResponse
    {
        return redirect()
            ->route('subscriptions.index')
            ->with('error', $message);
    }

    private function stripeApiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base'), '/');
    }
}
