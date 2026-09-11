<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckStripeSettings extends Command
{
    private const EXPECTED_PRODUCTION_APP_URL = 'https://furupro.shinji.work';

    private const EXPECTED_SUBSCRIPTION_AMOUNT = 980;

    private const EXPECTED_SUBSCRIPTION_CURRENCY = 'jpy';

    private const EXPECTED_SUBSCRIPTION_INTERVAL = 'month';

    private const EXPECTED_TRIAL_PERIOD_DAYS = 7;

    protected $signature = 'stripe:check';

    protected $description = 'Validate Stripe billing settings without printing secrets.';

    public function handle(): int
    {
        $secret = config('services.stripe.secret');
        $priceId = config('services.stripe.subscription_price_id');
        $appUrl = config('app.url');
        $webhookSecret = config('services.stripe.webhook_secret');
        $trialPeriodDays = (int) config('services.stripe.trial_period_days', self::EXPECTED_TRIAL_PERIOD_DAYS);
        $isProduction = app()->environment('production');

        $this->line('Stripe設定を確認します。秘密鍵の値は表示しません。');
        $this->line('Laravel environment='.app()->environment());
        $this->newLine();

        $ok = true;

        if (! is_string($secret) || $secret === '') {
            $this->error('NG: STRIPE_SECRET または STRIPE_SECRET_KEY が未設定です。');
            $ok = false;
        } else {
            $mode = str_starts_with($secret, 'sk_live_') ? 'live' : (str_starts_with($secret, 'sk_test_') ? 'test' : 'unknown');
            $this->line('OK: Stripe Secret Key は設定されています。mode='.$mode);

            if ($mode === 'unknown') {
                $this->warn('注意: Secret Key が sk_test_ または sk_live_ で始まっていません。');
                $ok = false;
            }

            if ($isProduction && $mode !== 'live') {
                $this->error('NG: 本番環境では Stripe Secret Key が sk_live_ である必要があります。');
                $ok = false;
            }
        }

        if (! is_string($priceId) || $priceId === '') {
            if ($isProduction) {
                $this->error('NG: 本番環境では STRIPE_SUBSCRIPTION_PRICE_ID / STRIPE_PREMIUM_PRICE_ID が必須です。');
                $ok = false;
            } else {
                $this->warn('注意: STRIPE_SUBSCRIPTION_PRICE_ID / STRIPE_PREMIUM_PRICE_ID が未設定です。開発環境では動的Price fallbackを利用できます。');
            }
        } elseif (! str_starts_with($priceId, 'price_')) {
            $this->error('NG: Price ID は price_ で始まる必要があります。Product ID(prod_...)ではありません。');
            $ok = false;
        } else {
            $ok = $this->checkPrice($secret, $priceId) && $ok;
        }

        if (is_string($secret) && $secret !== '') {
            $ok = $this->checkAccount($secret, $isProduction) && $ok;
            $portalOk = $this->checkPortalConfiguration($secret);

            if ($isProduction) {
                $ok = $portalOk && $ok;
            }
        }

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            $this->error('NG: STRIPE_WEBHOOK_SECRET が未設定です。');
            $ok = false;
        } else {
            $this->line('OK: STRIPE_WEBHOOK_SECRET は設定されています。');
        }

        if (! is_string($appUrl) || ! str_starts_with($appUrl, 'http')) {
            $this->error('NG: APP_URL がURL形式ではありません。');
            $ok = false;
        } else {
            $this->line('OK: APP_URL='.$appUrl);

            if (app()->environment('production') && ! str_starts_with($appUrl, 'https://')) {
                $this->warn('注意: 本番環境のAPP_URLは https:// から始まるURLを推奨します。');
            }

            if ($isProduction && $appUrl !== self::EXPECTED_PRODUCTION_APP_URL) {
                $this->error('NG: 本番環境のAPP_URLは '.self::EXPECTED_PRODUCTION_APP_URL.' にしてください。');
                $ok = false;
            }
        }

        if ($trialPeriodDays === self::EXPECTED_TRIAL_PERIOD_DAYS) {
            $this->line('OK: STRIPE_TRIAL_PERIOD_DAYS='.$trialPeriodDays);
        } else {
            $this->error('NG: STRIPE_TRIAL_PERIOD_DAYS は '.self::EXPECTED_TRIAL_PERIOD_DAYS.' にしてください。現在値='.$trialPeriodDays);
            $ok = false;
        }

        $this->newLine();

        if ($ok) {
            $this->info('Stripe基本設定は正常に見えます。');

            return self::SUCCESS;
        }

        $this->error('Stripe設定に修正が必要です。上のNGを確認してください。');

        return self::FAILURE;
    }

    private function checkAccount(string $secret, bool $requireEnabled): bool
    {
        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/account');
        } catch (Throwable) {
            $this->error('NG: Stripe APIへ接続できません。ネットワークまたはサーバーの外向き通信を確認してください。');

            return false;
        }

        if ($response->failed()) {
            $this->error('NG: Stripe Secret Keyで認証できません。HTTP '.$response->status().' / '.$this->stripeErrorSummary($response));

            return false;
        }

        $chargesEnabled = $response->json('charges_enabled');
        $detailsSubmitted = $response->json('details_submitted');

        $this->line('OK: Stripeアカウントに接続できました。charges_enabled='.($chargesEnabled ? 'yes' : 'no').', details_submitted='.($detailsSubmitted ? 'yes' : 'no'));

        if ($requireEnabled && ($chargesEnabled !== true || $detailsSubmitted !== true)) {
            $this->error('NG: 本番環境では charges_enabled=yes かつ details_submitted=yes が必須です。');

            return false;
        }

        return true;
    }

    private function checkPrice(string $secret, string $priceId): bool
    {
        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/prices/'.rawurlencode($priceId));
        } catch (Throwable) {
            $this->error('NG: Stripe Priceを確認できません。ネットワークまたはサーバーの外向き通信を確認してください。');

            return false;
        }

        if ($response->failed()) {
            $this->error('NG: Price IDを現在のStripeキーで取得できません。HTTP '.$response->status().' / '.$this->stripeErrorSummary($response));
            $this->warn('確認: Price IDとSecret Keyのテスト/本番モード、Stripeアカウントが一致している必要があります。');

            return false;
        }

        $active = $response->json('active');
        $currency = $response->json('currency');
        $unitAmount = $response->json('unit_amount');
        $interval = $response->json('recurring.interval');

        $this->line('OK: Price IDを取得できました。active='.($active ? 'yes' : 'no').', amount='.$unitAmount.', currency='.$currency.', interval='.$interval);

        if ($active !== true) {
            $this->error('NG: Priceが無効です。Stripeダッシュボードで有効なPriceを指定してください。');

            return false;
        }

        if ($unitAmount !== self::EXPECTED_SUBSCRIPTION_AMOUNT) {
            $this->error('NG: Priceの金額が'.self::EXPECTED_SUBSCRIPTION_AMOUNT.'円ではありません。');

            return false;
        }

        if ($currency !== self::EXPECTED_SUBSCRIPTION_CURRENCY) {
            $this->error('NG: Priceの通貨がJPYではありません。');

            return false;
        }

        if ($interval !== self::EXPECTED_SUBSCRIPTION_INTERVAL) {
            $this->error('NG: Priceが月額サブスクリプションではありません。');

            return false;
        }

        return true;
    }

    private function checkPortalConfiguration(string $secret): bool
    {
        try {
            $response = Http::timeout(10)
                ->withToken($secret)
                ->acceptJson()
                ->get($this->stripeApiBase().'/billing_portal/configurations', [
                    'active' => 'true',
                    'limit' => 1,
                ]);
        } catch (Throwable) {
            $this->warn('注意: Customer Portal設定を確認できませんでした。契約済みユーザーの管理画面だけ失敗する場合はPortal設定を確認してください。');

            return false;
        }

        if ($response->failed()) {
            $this->warn('注意: Customer Portal設定を確認できませんでした。HTTP '.$response->status().' / '.$this->stripeErrorSummary($response));

            return false;
        }

        $data = $response->json('data');

        if (is_array($data) && $data !== []) {
            $this->line('OK: 有効なCustomer Portal設定が見つかりました。');

            return true;
        }

        $this->warn('注意: 有効なCustomer Portal設定が見つかりません。StripeダッシュボードでCustomer Portalを保存してください。');

        return false;
    }

    private function stripeErrorSummary($response): string
    {
        $type = $response->json('error.type');
        $code = $response->json('error.code');
        $param = $response->json('error.param');

        return 'type='.($type ?: 'unknown').', code='.($code ?: 'none').', param='.($param ?: 'none');
    }

    private function stripeApiBase(): string
    {
        return rtrim((string) config('services.stripe.api_base'), '/');
    }
}
