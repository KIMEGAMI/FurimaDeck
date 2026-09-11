<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class SubscriptionController extends Controller
{
    private const RECENT_INVOICE_LIMIT = 5;

    public function index(Request $request): View
    {
        $user = $request->user();
        $isDemoUser = $user->isDemoUser();

        $hasManageableStripeSubscription = $isDemoUser ? false : $this->syncKnownStripeSubscriptionForUser($user);
        $user->refresh();
        $hasStripeSubscription = $hasManageableStripeSubscription && $this->hasStripeSubscriptionIdentifiers($user);
        [$stripeInvoices, $stripeInvoicesUnavailable] = $hasStripeSubscription
            ? $this->fetchRecentStripeInvoices($user)
            : [[], false];

        return view('subscriptions.index', [
            'user' => $user,
            'hasActiveSubscription' => $user->hasActiveSubscription(),
            'hasStripeSubscription' => $hasStripeSubscription,
            'isDemoUser' => $isDemoUser,
            'stripeInvoices' => $stripeInvoices,
            'stripeInvoicesUnavailable' => $stripeInvoicesUnavailable,
            'price' => config('services.stripe.subscription_amount'),
            'trialEndsAt' => $user->subscription_status === 'trialing' && $user->premium_ends_at?->isFuture()
                ? $user->premium_ends_at
                : null,
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        return app(SubscriptionCheckoutController::class)($request);

        $request->validate([
            'billing_terms_confirmed' => ['accepted'],
        ], [
            'billing_terms_confirmed.accepted' => 'Premiumプランの料金、自動更新、解約条件を確認してください。',
        ]);

        $user = $request->user();
        $secret = config('services.stripe.secret');

        if ($user->hasActiveSubscription()) {
            return $this->redirectAlreadySubscribed();
        }

        $this->syncKnownStripeSubscriptionForUser($user);
        $user->refresh();

        if ($user->hasActiveSubscription()) {
            return $this->redirectAlreadySubscribed();
        }

        if (! is_string($secret) || $secret === '') {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripeの設定が未完了です。STRIPE_SECRETを設定してください。');
        }

        try {
            $customerId = $this->ensureStripeCustomer($user, $secret);
        } catch (Throwable) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', '決済用の顧客情報を作成できませんでした。時間をおいて再度お試しください。');
        }

        try {
            $checkoutPayload = [
                'mode' => 'subscription',
                'customer' => $customerId,
                'line_items' => [$this->subscriptionLineItem()],
                'success_url' => route('subscriptions.success', [], true).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('subscriptions.index', [], true),
                'locale' => config('services.stripe.checkout_locale', 'ja'),
                'client_reference_id' => (string) $user->id,
                'metadata[user_id]' => (string) $user->id,
                'subscription_data[metadata][user_id]' => (string) $user->id,
            ];

            if ($this->canUseTrial($user)) {
                $checkoutPayload['subscription_data[trial_period_days]'] = $this->trialPeriodDays();
            }

            $response = Http::asForm()
                ->timeout(10)
                ->withToken($secret)
                ->post($this->stripeApiBase().'/checkout/sessions', $checkoutPayload);
        } catch (Throwable) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripeに接続できませんでした。ネットワークまたはStripeの設定を確認し、時間をおいて再度お試しください。');
        }

        if ($response->failed()) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', '決済画面を作成できませんでした。時間をおいて再度お試しください。');
        }

        $checkoutUrl = $response->json('url');

        if (! is_string($checkoutUrl) || $checkoutUrl === '') {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', '決済画面のURLを取得できませんでした。');
        }

        return redirect()->away($checkoutUrl);
    }

    public function portal(Request $request): RedirectResponse
    {
        return app(SubscriptionPortalController::class)->portal($request);

        $user = $request->user();
        $secret = config('services.stripe.secret');

        if (! is_string($user->stripe_customer_id) || $user->stripe_customer_id === '') {
            $this->syncKnownStripeSubscriptionForUser($user);
            $user->refresh();
        }

        if (! is_string($secret) || $secret === '' || ! is_string($user->stripe_customer_id) || $user->stripe_customer_id === '') {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', '契約・解約画面を開けませんでした。Stripeの顧客情報を確認してください。');
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->withToken($secret)
                ->post($this->stripeApiBase().'/billing_portal/sessions', [
                    'customer' => $user->stripe_customer_id,
                    'return_url' => route('subscriptions.index', [], true),
                ]);
        } catch (Throwable) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripeに接続できませんでした。ネットワークまたはStripeの設定を確認し、時間をおいて再度お試しください。');
        }

        if ($response->failed()) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', '契約管理画面を作成できませんでした。StripeダッシュボードのCustomer portal設定、Stripeキー、顧客IDを確認してください。');
        }

        $portalUrl = $response->json('url');

        if (! is_string($portalUrl) || $portalUrl === '') {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', '契約・解約画面のURLを取得できませんでした。');
        }

        return redirect()->away($portalUrl);
    }

    public function cancelFeedback(Request $request): RedirectResponse
    {
        return app(SubscriptionPortalController::class)->cancelFeedback($request);
    }

    public function success(Request $request): RedirectResponse
    {
        $sessionId = $request->query('session_id');

        if (! is_string($sessionId) || $sessionId === '') {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripeの決済結果を確認できませんでした。契約状態を再確認してください。');
        }

        if (! $this->syncCheckoutSession($request->user(), $sessionId)) {
            return redirect()
                ->route('subscriptions.index')
                ->with('error', 'Stripeの契約状態を反映できませんでした。時間をおいて契約状態を再確認してください。');
        }

        return redirect()
            ->route('subscriptions.index')
            ->with('status', 'Premium契約を確認しました。契約・解約画面から契約状態を確認できます。');
    }

    private function redirectAlreadySubscribed(): RedirectResponse
    {
        return redirect()
            ->route('subscriptions.index')
            ->with('status', 'すでにPremium契約が有効です。契約・解約画面から契約状態を確認できます。');
    }

    private function hasStripeSubscriptionIdentifiers(User $user): bool
    {
        return is_string($user->stripe_customer_id)
            && $user->stripe_customer_id !== ''
            && is_string($user->stripe_subscription_id)
            && $user->stripe_subscription_id !== '';
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: bool}
     */
    private function fetchRecentStripeInvoices(User $user): array
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '' || ! is_string($user->stripe_customer_id) || $user->stripe_customer_id === '') {
            return [[], false];
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/invoices', [
                    'customer' => $user->stripe_customer_id,
                    'limit' => self::RECENT_INVOICE_LIMIT,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Stripe invoice list request failed.', [
                'user_id' => $user->id,
                'error_class' => $exception::class,
            ]);

            return [[], true];
        }

        if ($response->failed()) {
            Log::warning('Stripe invoice list response failed.', [
                'user_id' => $user->id,
                'http_status' => $response->status(),
                'stripe_error_type' => $response->json('error.type'),
                'stripe_error_code' => $response->json('error.code'),
                'stripe_request_id' => $response->header('Request-Id'),
            ]);

            return [[], true];
        }

        $invoices = $response->json('data');

        if (! is_array($invoices)) {
            return [[], true];
        }

        return [
            collect($invoices)
                ->filter(fn (mixed $invoice): bool => is_array($invoice))
                ->map(fn (array $invoice): array => $this->formatStripeInvoice($invoice))
                ->values()
                ->all(),
            false,
        ];
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function formatStripeInvoice(array $invoice): array
    {
        $created = data_get($invoice, 'created');
        $total = data_get($invoice, 'total');
        $currency = data_get($invoice, 'currency');
        $number = data_get($invoice, 'number');
        $id = data_get($invoice, 'id');
        $status = data_get($invoice, 'status');

        return [
            'id' => is_string($id) ? $id : '',
            'number' => is_string($number) && $number !== '' ? $number : (is_string($id) ? $id : '未確定'),
            'status' => is_string($status) ? $status : '',
            'total' => is_numeric($total) ? (int) $total : null,
            'currency' => is_string($currency) ? strtoupper($currency) : 'JPY',
            'created_at' => is_numeric($created) ? Carbon::createFromTimestamp((int) $created) : null,
            'hosted_invoice_url' => $this->httpsUrl(data_get($invoice, 'hosted_invoice_url')),
            'invoice_pdf' => $this->httpsUrl(data_get($invoice, 'invoice_pdf')),
        ];
    }

    private function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return str_starts_with($value, 'https://') ? $value : null;
    }

    private function ensureStripeCustomer(User $user, string $secret): string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            return $user->stripe_customer_id;
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

    private function syncCheckoutSession(User $user, string $sessionId): bool
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/checkout/sessions/'.$sessionId, [
                    'expand' => ['subscription'],
                ]);
        } catch (Throwable) {
            return false;
        }

        if ($response->failed()) {
            return false;
        }

        $session = $response->json();

        if (! is_array($session)) {
            return false;
        }

        $sessionUserId = data_get($session, 'metadata.user_id') ?: data_get($session, 'client_reference_id');

        if ((string) $sessionUserId !== (string) $user->id) {
            return false;
        }

        $customerId = data_get($session, 'customer');
        $subscription = data_get($session, 'subscription');

        if (is_array($subscription)) {
            if (is_string($customerId)) {
                $subscription['customer'] = $customerId;
            }

            return $this->syncSubscriptionPayload($user, $subscription);
        }

        if (is_string($subscription) && $subscription !== '') {
            $payload = $this->fetchSubscription($subscription);

            if ($payload !== []) {
                if (is_string($customerId)) {
                    $payload['customer'] = $customerId;
                }

                return $this->syncSubscriptionPayload($user, $payload);
            }
        }

        return false;
    }

    private function syncKnownStripeSubscriptionForUser(User $user): bool
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            $synced = $this->syncLatestSubscriptionForUser($user);
            $user->refresh();

            if ($synced && $user->hasActiveSubscription()) {
                return true;
            }
        }

        return $this->syncLatestSubscriptionByEmail($user);
    }

    private function syncLatestSubscriptionForUser(User $user): bool
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '' || ! is_string($user->stripe_customer_id) || $user->stripe_customer_id === '') {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/subscriptions', [
                    'customer' => $user->stripe_customer_id,
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
            $subscription = collect($subscriptions)->first(fn (mixed $item): bool => is_array($item));
        }

        if (! is_array($subscription)) {
            return false;
        }

        return $this->syncSubscriptionPayload($user, $subscription);
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

        if (! is_array($customers) || $customers === []) {
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

            if ($this->syncLatestSubscriptionForUser($user)) {
                $user->refresh();

                if ($user->hasActiveSubscription()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function syncSubscriptionPayload(User $user, array $subscription): bool
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

        return true;
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
    private function fetchSubscription(string $subscriptionId): array
    {
        $secret = config('services.stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/subscriptions/'.$subscriptionId);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionLineItem(): array
    {
        $priceId = config('services.stripe.subscription_price_id');

        if (is_string($priceId) && $priceId !== '') {
            return [
                'price' => $priceId,
                'quantity' => 1,
            ];
        }

        return [
            'quantity' => 1,
            'price_data' => [
                'currency' => config('services.stripe.subscription_currency', 'jpy'),
                'unit_amount' => (int) config('services.stripe.subscription_amount'),
                'recurring' => ['interval' => 'month'],
                'product_data' => [
                    'name' => config('services.stripe.subscription_product_name', 'FurimaDeck Premium'),
                    'description' => config('services.stripe.subscription_product_description', 'FurimaDeck paid subscription.'),
                ],
            ],
        ];
    }

    private function stripeApiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base'), '/');
    }

    private function canUseTrial(User $user): bool
    {
        return $this->trialPeriodDays() > 0 && $user->trial_used_at === null;
    }

    private function trialPeriodDays(): int
    {
        return max(0, (int) config('services.stripe.trial_period_days', 7));
    }
}
