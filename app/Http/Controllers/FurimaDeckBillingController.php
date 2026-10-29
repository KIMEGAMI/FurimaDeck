<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FurimaDeckStripeSubscriptionPriceMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class FurimaDeckBillingController extends Controller
{
    public function index(Request $request, FurimaDeckStripeSubscriptionPriceMatcher $priceMatcher): View
    {
        $user = $request->user();
        $complimentaryPremium = $user->hasComplimentaryPremiumAccess();
        $subscriptionSyncUnavailable = false;
        if (! $complimentaryPremium && is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            $subscriptionSyncUnavailable = $this->syncCustomerSubscription($user, $priceMatcher) === null;
            $user->refresh();
        }

        [$invoices, $invoiceLoadFailed] = $complimentaryPremium
            ? [[], false]
            : $this->invoicesFor($user);

        return view('furimadeck_billing.index', [
            'user' => $user,
            'price' => (int) config('furimadeck.billing.monthly_price_jpy'),
            'trialDays' => (int) config('furimadeck.billing.trial_period_days'),
            'invoices' => $invoices,
            'invoiceLoadFailed' => $invoiceLoadFailed,
            'complimentaryPremium' => $complimentaryPremium,
            'complimentaryPremiumLabel' => $user->isAdmin() ? '管理者アカウント' : 'デモユーザー',
            'subscriptionSyncUnavailable' => $subscriptionSyncUnavailable,
        ]);
    }

    public function checkout(Request $request, FurimaDeckStripeSubscriptionPriceMatcher $priceMatcher): RedirectResponse
    {
        $user = $request->user();
        if ($user->hasComplimentaryPremiumAccess()) {
            return $this->backWithError('このアカウントは決済不要でPremium機能を利用できます。');
        }

        $request->validate(['billing_terms_confirmed' => ['accepted']]);
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            $stripeHasActiveSubscription = $this->syncCustomerSubscription($user, $priceMatcher);
            $user->refresh();

            if ($stripeHasActiveSubscription === null) {
                return $this->backWithError('Stripeの契約状態を確認できませんでした。重複決済を防ぐため、新しい申込を停止しました。時間をおいて再度お試しください。');
            }

            if ($stripeHasActiveSubscription) {
                return $this->backWithError('すでにPremium契約が有効です。契約管理画面から状態を確認してください。');
            }
        }

        if ($user->hasActiveSubscription()) {
            return $this->backWithError('すでにPremium契約が有効です。');
        }

        $secret = $this->stripeSecret();
        $priceId = config('furimadeck.billing.stripe_price_id');
        if ($secret === null || ! is_string($priceId) || ! str_starts_with($priceId, 'price_')) {
            return $this->backWithError('決済設定が未完了のため、現在は申込を受け付けていません。');
        }

        try {
            $customerId = $this->ensureCustomer($user, $secret);
            $payload = [
                'mode' => 'subscription',
                'customer' => $customerId,
                'line_items[0][price]' => $priceId,
                'line_items[0][quantity]' => 1,
                'success_url' => route('furimadeck-billing.success', [], true).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('furimadeck-billing.index', [], true),
                'locale' => 'ja',
                'client_reference_id' => (string) $user->id,
                'metadata[user_id]' => (string) $user->id,
                'subscription_data[metadata][user_id]' => (string) $user->id,
                'payment_method_options[card][request_three_d_secure]' => $this->stripe3dsRequest(),
            ];
            if ($user->trial_used_at === null) {
                $payload['subscription_data[trial_period_days]'] = (int) config('furimadeck.billing.trial_period_days');
            }
            $response = Http::asForm()->timeout(10)->withToken($secret)->post($this->stripeApiBase().'/checkout/sessions', $payload);
        } catch (Throwable) {
            return $this->backWithError('Stripeへ接続できませんでした。時間をおいてもう一度お試しください。');
        }

        $checkoutUrl = $response->successful() ? $response->json('url') : null;
        if (! is_string($checkoutUrl) || ! $this->isStripeHostedUrl($checkoutUrl)) {
            return $this->backWithError('決済画面を作成できませんでした。設定を確認してからもう一度お試しください。');
        }

        return redirect()->away($checkoutUrl);
    }

    public function portal(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->hasComplimentaryPremiumAccess()) {
            return $this->backWithError('このアカウントにはStripe契約がありません。Premium機能は決済不要で利用できます。');
        }

        $secret = $this->stripeSecret();
        if ($secret === null || ! is_string($user->stripe_customer_id) || $user->stripe_customer_id === '') {
            return $this->backWithError('契約管理画面を開けませんでした。契約情報を確認してください。');
        }

        $payload = [
            'customer' => $user->stripe_customer_id,
            'return_url' => route('furimadeck-billing.index', [], true),
        ];
        $configurationId = config('furimadeck.billing.stripe_portal_configuration_id');
        if (is_string($configurationId) && $configurationId !== '') {
            $payload['configuration'] = $configurationId;
        }

        try {
            $response = Http::asForm()->timeout(10)->withToken($secret)->post($this->stripeApiBase().'/billing_portal/sessions', $payload);
        } catch (Throwable) {
            return $this->backWithError('Stripeへ接続できませんでした。時間をおいてもう一度お試しください。');
        }

        $portalUrl = $response->successful() ? $response->json('url') : null;
        if (! is_string($portalUrl) || ! $this->isStripeHostedUrl($portalUrl)) {
            return $this->backWithError('契約管理画面を作成できませんでした。設定を確認してください。');
        }

        return redirect()->away($portalUrl);
    }

    public function success(Request $request, FurimaDeckStripeSubscriptionPriceMatcher $priceMatcher): RedirectResponse
    {
        $sessionId = $request->query('session_id');
        if (! is_string($sessionId) || ! str_starts_with($sessionId, 'cs_') || strlen($sessionId) > 255) {
            return $this->backWithError('Stripeの契約情報を確認できませんでした。契約状態を再読み込みしてください。');
        }

        if (! $this->syncCheckoutSession($request->user(), $sessionId, $priceMatcher)) {
            return $this->backWithError('Stripeから契約状態を確認できませんでした。重複申込はせず、時間をおいて契約画面を再読み込みしてください。');
        }

        return redirect()->route('furimadeck-billing.index')->with('success', 'StripeのPremium契約を確認しました。契約管理画面から状態や解約を確認できます。');
    }

    private function syncCheckoutSession(User $user, string $sessionId, FurimaDeckStripeSubscriptionPriceMatcher $priceMatcher): bool
    {
        $secret = $this->stripeSecret();
        if ($secret === null) {
            return false;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/checkout/sessions/'.rawurlencode($sessionId));
        } catch (Throwable) {
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $session = $response->json();
        $sessionUserId = data_get($session, 'client_reference_id');
        $metadataUserId = data_get($session, 'metadata.user_id');
        $customerId = data_get($session, 'customer');
        if (! is_array($session)
            || data_get($session, 'mode') !== 'subscription'
            || data_get($session, 'status') !== 'complete'
            || ((string) $sessionUserId !== (string) $user->id && (string) $metadataUserId !== (string) $user->id)
            || ! is_string($customerId)
            || $customerId === ''
            || (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '' && ! hash_equals($user->stripe_customer_id, $customerId))) {
            return false;
        }

        $subscription = data_get($session, 'subscription');
        if (is_string($subscription) && $subscription !== '') {
            try {
                $subscriptionResponse = Http::timeout(10)
                    ->withToken($secret)
                    ->acceptJson()
                    ->get($this->stripeApiBase().'/subscriptions/'.rawurlencode($subscription));
            } catch (Throwable) {
                return false;
            }

            $subscription = $subscriptionResponse->successful() ? $subscriptionResponse->json() : null;
        }

        if (! is_array($subscription)
            || data_get($subscription, 'customer') !== $customerId
            || ! $priceMatcher->matchesConfiguredPrice($subscription)) {
            return false;
        }

        $this->storeStripeSubscription($user, $subscription);

        return $user->refresh()->hasActiveSubscription();
    }

    /** Returns null if Stripe could not be checked; otherwise reflects the newest relevant subscription. */
    private function syncCustomerSubscription(User $user, FurimaDeckStripeSubscriptionPriceMatcher $priceMatcher): ?bool
    {
        $secret = $this->stripeSecret();
        $customerId = $user->stripe_customer_id;
        if (! is_string($customerId) || $customerId === '') {
            return false;
        }
        if ($secret === null) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/subscriptions', [
                    'customer' => $customerId,
                    'status' => 'all',
                    'limit' => 100,
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            return null;
        }

        $subscriptions = collect($response->json('data'))
            ->filter(fn (mixed $subscription): bool => is_array($subscription)
                && data_get($subscription, 'customer') === $customerId
                && $priceMatcher->matchesConfiguredPrice($subscription))
            ->values();

        if ($subscriptions->isEmpty()) {
            if ($user->subscription_plan !== User::SUBSCRIPTION_INACTIVE || $user->subscription_status !== null) {
                $user->forceFill([
                    'subscription_plan' => User::SUBSCRIPTION_INACTIVE,
                    'subscription_status' => null,
                ])->save();
            }

            return false;
        }

        $subscription = $subscriptions->first(fn (array $item): bool => in_array(data_get($item, 'status'), ['active', 'trialing'], true));
        $subscription ??= $subscriptions->sortByDesc(fn (array $item): int => (int) data_get($item, 'created', 0))->first();
        if (! is_array($subscription)) {
            return false;
        }

        $this->storeStripeSubscription($user, $subscription);

        return $user->refresh()->hasActiveSubscription();
    }

    /** @param array<string, mixed> $subscription */
    private function storeStripeSubscription(User $user, array $subscription): void
    {
        $status = (string) data_get($subscription, 'status', '');
        $periodEnd = is_numeric(data_get($subscription, 'current_period_end'))
            ? Carbon::createFromTimestamp((int) data_get($subscription, 'current_period_end'))
            : null;
        $hasRemainingAccess = $status === 'canceled' && $periodEnd !== null && $periodEnd->isFuture();
        $isActive = in_array($status, ['active', 'trialing'], true) || $hasRemainingAccess;
        $hasTrial = $status === 'trialing'
            || is_numeric(data_get($subscription, 'trial_start'))
            || is_numeric(data_get($subscription, 'trial_end'));
        $customerId = data_get($subscription, 'customer');
        $subscriptionId = data_get($subscription, 'id');

        $user->forceFill([
            'subscription_plan' => $isActive ? User::SUBSCRIPTION_ACTIVE : User::SUBSCRIPTION_INACTIVE,
            'subscription_status' => $status !== '' ? $status : null,
            'stripe_customer_id' => is_string($customerId) ? $customerId : $user->stripe_customer_id,
            'stripe_subscription_id' => is_string($subscriptionId) ? $subscriptionId : $user->stripe_subscription_id,
            'premium_started_at' => $isActive && $user->premium_started_at === null ? now() : $user->premium_started_at,
            'premium_ends_at' => $periodEnd ?? $user->premium_ends_at,
            'trial_used_at' => $hasTrial && $user->trial_used_at === null ? now() : $user->trial_used_at,
        ])->save();
    }

    private function ensureCustomer(User $user, string $secret): string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            $existing = Http::timeout(10)->withToken($secret)->get($this->stripeApiBase().'/customers/'.rawurlencode($user->stripe_customer_id));
            if ($existing->successful() && $existing->json('deleted') !== true) {
                return $user->stripe_customer_id;
            }
        }

        $response = Http::asForm()->timeout(10)->withToken($secret)->post($this->stripeApiBase().'/customers', [
            'email' => $user->email,
            'name' => $user->name,
            'metadata[user_id]' => (string) $user->id,
        ]);
        $customerId = $response->successful() ? $response->json('id') : null;
        if (! is_string($customerId) || $customerId === '') {
            throw new \RuntimeException('Stripe customer creation failed.');
        }
        $user->forceFill(['stripe_customer_id' => $customerId])->save();

        return $customerId;
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: bool} */
    private function invoicesFor(User $user): array
    {
        $secret = $this->stripeSecret();
        if ($secret === null || ! is_string($user->stripe_customer_id) || $user->stripe_customer_id === '') {
            return [[], false];
        }

        try {
            $response = Http::timeout(10)->withToken($secret)->acceptJson()->get($this->stripeApiBase().'/invoices', [
                'customer' => $user->stripe_customer_id,
                'limit' => 10,
            ]);
        } catch (Throwable) {
            return [[], true];
        }
        if (! $response->successful() || ! is_array($response->json('data'))) {
            return [[], true];
        }

        $invoices = collect($response->json('data'))
            ->filter(fn (mixed $invoice): bool => is_array($invoice) && data_get($invoice, 'customer') === $user->stripe_customer_id)
            ->map(function (array $invoice): array {
                $paidAt = data_get($invoice, 'status_transitions.paid_at');

                return [
                    'number' => is_string(data_get($invoice, 'number')) ? data_get($invoice, 'number') : '請求書',
                    'status' => is_string(data_get($invoice, 'status')) ? data_get($invoice, 'status') : '',
                    'amountPaid' => is_numeric(data_get($invoice, 'amount_paid')) ? (int) data_get($invoice, 'amount_paid') : 0,
                    'paidAt' => is_numeric($paidAt) ? Carbon::createFromTimestamp((int) $paidAt)->format('Y/m/d') : null,
                    'hostedUrl' => $this->stripeHostedUrl(data_get($invoice, 'hosted_invoice_url')),
                    'pdfUrl' => $this->stripeHostedUrl(data_get($invoice, 'invoice_pdf')),
                ];
            })
            ->values()
            ->all();

        return [$invoices, false];
    }

    private function stripe3dsRequest(): string
    {
        $request = (string) config('furimadeck.billing.stripe_3ds_request', 'automatic');

        return in_array($request, ['automatic', 'any', 'challenge'], true) ? $request : 'automatic';
    }

    private function stripeSecret(): ?string
    {
        $secret = config('furimadeck.billing.stripe_secret');

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function stripeApiBase(): string
    {
        return rtrim((string) config('furimadeck.billing.stripe_api_base'), '/');
    }

    private function isStripeHostedUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return $scheme === 'https'
            && is_string($host)
            && ($host === 'stripe.com' || str_ends_with($host, '.stripe.com'));
    }

    private function stripeHostedUrl(mixed $url): ?string
    {
        return is_string($url) && $this->isStripeHostedUrl($url) ? $url : null;
    }

    private function backWithError(string $message): RedirectResponse
    {
        return redirect()->route('furimadeck-billing.index')->with('error', $message);
    }
}
