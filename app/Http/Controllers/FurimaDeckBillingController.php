<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class FurimaDeckBillingController extends Controller
{
    public function index(Request $request): View
    {
        [$invoices, $invoiceLoadFailed] = $this->invoicesFor($request->user());

        return view('furimadeck_billing.index', [
            'user' => $request->user(),
            'price' => (int) config('furimadeck.billing.monthly_price_jpy'),
            'trialDays' => (int) config('furimadeck.billing.trial_period_days'),
            'invoices' => $invoices,
            'invoiceLoadFailed' => $invoiceLoadFailed,
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $request->validate(['billing_terms_confirmed' => ['accepted']]);
        $user = $request->user();
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

    public function success(): RedirectResponse
    {
        return redirect()->route('furimadeck-billing.index')->with('success', 'Stripeでの処理を受け付けました。契約状態はWebhook確認後に反映されます。');
    }

    private function ensureCustomer(User $user, string $secret): string
    {
        if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
            return $user->stripe_customer_id;
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
