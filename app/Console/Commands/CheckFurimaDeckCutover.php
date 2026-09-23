<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckFurimaDeckCutover extends Command
{
    private const STRIPE_API_TIMEOUT_SECONDS = 10;

    private const REQUIRED_TABLES = [
        'users',
        'products',
        'product_images',
        'listings',
        'sales',
        'audit_logs',
        'furimadeck_stripe_webhook_events',
        'accounting_entries',
        'import_batches',
        'import_row_results',
        'contact_inquiries',
    ];

    protected $signature = 'furimadeck:check-cutover';

    protected $description = 'Validate FurimaDeck cutover settings without changing data or displaying secrets';

    public function handle(): int
    {
        $ready = true;
        $environmentReady = $this->checkEnvironment();
        $ready = $environmentReady && $ready;
        $googleOAuthReady = $this->checkGoogleOAuthSettings();
        $ready = $googleOAuthReady && $ready;
        $mailReady = $this->checkMailSettings();
        $featureFlagsReady = $this->checkFeatureFlags();
        $ready = $mailReady && $ready;
        $ready = $featureFlagsReady && $ready;
        $ready = $this->checkBillingSettings($environmentReady && $googleOAuthReady && $featureFlagsReady) && $ready;
        $ready = $this->checkSchema() && $ready;

        if ($ready) {
            $this->info('FurimaDeck切替前チェックは成功しました。秘密値は表示していません。');

            return self::SUCCESS;
        }

        $this->error('FurimaDeck切替前チェックは未完了です。上のNGを解消してください。');

        return self::FAILURE;
    }

    private function checkEnvironment(): bool
    {
        $ready = true;

        if (! app()->environment('production')) {
            $this->error('NG: APP_ENV=production が必要です。');
            $ready = false;
        }
        if ((bool) config('app.debug')) {
            $this->error('NG: APP_DEBUG=false が必要です。');
            $ready = false;
        }
        if (config('database.default') !== 'furimadeck') {
            $this->error('NG: DB_CONNECTION=furimadeck が必要です。');
            $ready = false;
        }
        if (! $this->isHttpsUrl(config('app.url'))) {
            $this->error('NG: APP_URL はHTTPS URLである必要があります。');
            $ready = false;
        }
        if (! (bool) config('app.force_https')) {
            $this->error('NG: APP_FORCE_HTTPS=true が必要です。');
            $ready = false;
        }
        if (! (bool) config('session.secure')) {
            $this->error('NG: SESSION_SECURE_COOKIE=true が必要です。');
            $ready = false;
        }
        if (File::exists(public_path('hot'))) {
            $this->error('NG: public/hot を削除して本番ビルドを使用してください。');
            $ready = false;
        }

        return $ready;
    }

    private function checkMailSettings(): bool
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || str_ends_with($from, '@localhost')) {
            $this->error('NG: 本番のMAIL_FROM_ADDRESSが正しいメールアドレスではありません。');

            return false;
        }
        if ($mailer === 'resend' && str_starts_with((string) config('services.resend.key'), 're_')) {
            return true;
        }
        if ($mailer === 'smtp' && config('mail.mailers.smtp.host') === 'smtp.resend.com' && (int) config('mail.mailers.smtp.port') === 587 && config('mail.mailers.smtp.username') === 'resend' && filled(config('mail.mailers.smtp.password'))) {
            return true;
        }
        $this->error('NG: 本番メールはResend（APIまたはsmtp.resend.com:587）に設定してください。');

        return false;
    }

    private function checkFeatureFlags(): bool
    {
        $ready = true;

        foreach (['cutover_enabled', 'product_management_enabled'] as $setting) {
            if (! (bool) config('furimadeck.'.$setting)) {
                $this->error('NG: FurimaDeckの'.$setting.'を有効にしてください。');
                $ready = false;
            }
        }

        return $ready;
    }

    private function checkGoogleOAuthSettings(): bool
    {
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        $isConfigured = is_string($clientId) && $clientId !== '';
        $hasSecret = is_string($clientSecret) && $clientSecret !== '';

        if (! $isConfigured && ! $hasSecret) {
            return true;
        }

        if (! $isConfigured || ! $hasSecret) {
            $this->error('NG: Google OAuthのclient IDとclient secretは両方必要です。');

            return false;
        }

        $appUrl = config('app.url');
        $expectedRedirectUri = is_string($appUrl) ? rtrim($appUrl, '/').'/auth/google/callback' : null;
        if (config('services.google.redirect') !== $expectedRedirectUri) {
            $this->error('NG: Google OAuth redirect URIがAPP_URLと一致しません。');

            return false;
        }

        return true;
    }

    private function checkBillingSettings(bool $validateStripePrice): bool
    {
        $ready = true;

        foreach (['stripe_secret', 'stripe_webhook_secret', 'stripe_price_id'] as $setting) {
            $value = config('furimadeck.billing.'.$setting);
            if (! is_string($value) || $value === '') {
                $this->error('NG: FurimaDeckの'.$setting.'が未設定です。');
                $ready = false;
            }
        }

        $secret = config('furimadeck.billing.stripe_secret');
        $priceId = config('furimadeck.billing.stripe_price_id');
        if (is_string($secret) && $secret !== '' && ! str_starts_with($secret, 'sk_live_')) {
            $this->error('NG: FurimaDeckのstripe_secretはStripe Live Modeキーが必要です。');
            $ready = false;
        }

        $webhookSecret = config('furimadeck.billing.stripe_webhook_secret');
        if (is_string($webhookSecret) && $webhookSecret !== '' && ! str_starts_with($webhookSecret, 'whsec_')) {
            $this->error('NG: FurimaDeckのstripe_webhook_secretはStripe Webhook署名シークレットが必要です。');
            $ready = false;
        }

        $apiBase = rtrim((string) config('furimadeck.billing.stripe_api_base'), '/');
        if ($apiBase !== 'https://api.stripe.com/v1') {
            $this->error('NG: FurimaDeckのstripe_api_baseは公式Stripe API endpointである必要があります。');
            $ready = false;
        }

        if (! $ready || ! is_string($secret) || ! is_string($priceId)) {
            return false;
        }

        if (! $validateStripePrice) {
            return true;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(self::STRIPE_API_TIMEOUT_SECONDS)
                ->withToken($secret)
                ->get($apiBase.'/prices/'.rawurlencode($priceId));
        } catch (Throwable) {
            $this->error('NG: FurimaDeckのStripe Priceを確認できませんでした。');

            return false;
        }

        $price = $response->successful() ? $response->json() : null;
        $expectedAmount = (int) config('furimadeck.billing.monthly_price_jpy');
        $mismatches = [];
        if (! $response->successful() || ! is_array($price)) {
            $mismatches[] = 'Stripe APIからPriceを取得できませんでした（HTTP '.$response->status().'）。';
        } else {
            if (data_get($price, 'active') !== true) {
                $mismatches[] = 'Priceがactiveではありません。';
            }
            if (data_get($price, 'currency') !== 'jpy') {
                $mismatches[] = '通貨がJPYではありません。';
            }
            if (data_get($price, 'recurring.interval') !== 'month') {
                $mismatches[] = '請求間隔が月額ではありません。';
            }
            if (data_get($price, 'unit_amount') !== $expectedAmount) {
                $mismatches[] = '金額が'.$expectedAmount.'円ではありません。';
            }
        }

        if ($mismatches !== []) {
            $this->error('NG: FurimaDeckのStripe Priceが条件に一致しません。');
            foreach ($mismatches as $mismatch) {
                $this->line('NG詳細: '.$mismatch);
            }

            return false;
        }

        return true;
    }

    private function checkSchema(): bool
    {
        try {
            $schema = DB::connection('furimadeck')->getSchemaBuilder();
            $missingTables = array_filter(self::REQUIRED_TABLES, fn (string $table): bool => ! $schema->hasTable($table));
            if ($missingTables !== []) {
                $this->error('NG: FurimaDeck DBに必須テーブルがありません。');

                return false;
            }
            if ($schema->hasTable('auction_items')) {
                $this->error('NG: FurimaDeck DBに旧auction_itemsテーブルが存在します。');

                return false;
            }
        } catch (Throwable) {
            $this->error('NG: FurimaDeck DBへ接続できません。接続設定とDBバックアップを確認してください。');

            return false;
        }

        return true;
    }

    private function isHttpsUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'https://');
    }
}
