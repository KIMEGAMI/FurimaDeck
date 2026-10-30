<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckFurimaDeckCutover extends Command
{
    private const STRIPE_API_TIMEOUT_SECONDS = 10;

    private const HTTPS_PORT = 443;

    private const MAX_PRODUCT_IMAGE_SOURCE_PIXELS = 12_000_000;

    /** @var array<string, array{label:string,scopes:list<string>,endpoints:array<string,string>}> */
    private const ACCOUNTING_OAUTH_REQUIREMENTS = [
        'freee' => [
            'label' => 'freee',
            'scopes' => ['read'],
            'endpoints' => [
                'authorize_url' => 'accounts.secure.freee.co.jp',
                'token_url' => 'accounts.secure.freee.co.jp',
                'api_base' => 'api.freee.co.jp',
            ],
        ],
        'money_forward' => [
            'label' => 'Money Forward',
            'scopes' => [
                'mfc/admin/tenant.read',
                'mfc/accounting/accounts.read',
                'mfc/accounting/taxes.read',
            ],
            'endpoints' => [
                'authorize_url' => 'api.biz.moneyforward.com',
                'token_url' => 'api.biz.moneyforward.com',
                'api_base' => 'api.biz.moneyforward.com',
                'accounting_api_base' => 'api-accounting.moneyforward.com',
            ],
        ],
    ];

    private const REQUIRED_TABLES = [
        'users',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'failed_jobs',
        'system_settings',
        'notices',
        'subscription_cancellation_feedback',
        'suppliers',
        'product_categories',
        'category_attributes',
        'products',
        'product_attribute_values',
        'product_images',
        'marketplaces',
        'listings',
        'sales',
        'audit_logs',
        'ai_response_caches',
        'ai_usage_logs',
        'furimadeck_stripe_webhook_events',
        'accounting_entries',
        'accounting_connections',
        'accounting_sync_batches',
        'accounting_sync_batch_items',
        'accounting_entry_syncs',
        'accounting_purchase_cap_reviews',
        'manual_product_purchase_reconciliations',
        'manual_product_purchase_reconciliation_refs',
        'import_batches',
        'import_row_results',
        'contact_inquiries',
    ];

    /** @var array<string, list<string>> */
    private const REQUIRED_COLUMNS = [
        'users' => ['id', 'email', 'email_verified_at', 'password', 'subscription_plan', 'subscription_status', 'stripe_customer_id', 'stripe_subscription_id', 'is_admin'],
        'system_settings' => ['id', 'key', 'value'],
        'notices' => ['id', 'user_id', 'title', 'body', 'published_at'],
        'subscription_cancellation_feedback' => ['id', 'user_id', 'reason', 'detail'],
        'cache' => ['key', 'value', 'expiration'],
        'cache_locks' => ['key', 'owner', 'expiration'],
        'jobs' => ['id', 'queue', 'payload', 'attempts', 'available_at', 'created_at'],
        'failed_jobs' => ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
        'products' => ['id', 'user_id', 'internal_sku', 'product_name', 'purchase_date', 'purchase_unit_cost', 'purchase_quantity', 'quantity_available', 'purchase_shipping_cost', 'other_purchase_expense', 'supplier_id', 'inventory_status', 'purchase_accounting_enabled', 'purchase_document_type', 'purchase_tax_category', 'purchase_invoice_issuer', 'purchase_payment_sources', 'purchase_evidence', 'purchase_special_case_data'],
        'marketplaces' => ['id', 'code', 'name', 'default_fee_rate', 'is_active'],
        'listings' => ['id', 'user_id', 'product_id', 'marketplace_id', 'status'],
        'sales' => ['id', 'user_id', 'product_id', 'marketplace_id', 'sold_price', 'sold_at', 'status', 'cost_basis', 'sales_fee', 'shipping_fee', 'packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense', 'accounting_expense_details'],
        'accounting_entries' => ['id', 'user_id', 'sale_id', 'product_id', 'entry_type', 'transaction_date', 'management_id', 'source_status', 'sync_status', 'confirmed_at', 'expense_breakdown', 'purchase_special_case_data'],
        'accounting_connections' => ['id', 'user_id', 'provider', 'access_token', 'refresh_token', 'expires_at', 'external_account_id', 'status', 'last_synced_at'],
        'accounting_sync_batches' => ['id', 'user_id', 'provider', 'period_from', 'period_to', 'status', 'total_count', 'processed_count', 'succeeded_count', 'failed_count'],
        'accounting_sync_batch_items' => ['id', 'batch_id', 'accounting_entry_id', 'status', 'diagnostic'],
        'accounting_entry_syncs' => ['id', 'accounting_entry_id', 'provider', 'status', 'external_transaction_id', 'external_import_id', 'request_payload', 'sensitive_request_payload', 'verified_at', 'manual_reconciliation_evidence', 'manual_reconciled_by_user_id', 'manual_reconciled_at'],
        'accounting_purchase_cap_reviews' => ['id', 'user_id', 'period_from', 'period_to', 'counterparty_fingerprint', 'external_qualifying_purchase_amount', 'reviewed_through', 'furimadeck_history_complete', 'external_history_complete', 'reviewed_at'],
        'manual_product_purchase_reconciliations' => ['id', 'user_id', 'product_id', 'provider', 'product_snapshot_hash', 'evidence_history', 'reviewed_by_user_id', 'reviewed_at'],
        'manual_product_purchase_reconciliation_refs' => ['id', 'user_id', 'product_id', 'provider', 'external_transaction_id_hash', 'transaction_total', 'allocated_amount'],
        'jobs' => ['id', 'queue', 'payload', 'attempts', 'available_at', 'created_at'],
        'failed_jobs' => ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
    ];

    protected $signature = 'furimadeck:check-cutover';

    protected $description = 'Validate FurimaDeck cutover settings without changing data or displaying secrets';

    public function handle(): int
    {
        $ready = true;
        $environmentReady = $this->checkEnvironment();
        $ready = $environmentReady && $ready;
        $imageStorageReady = $this->checkProductImageStorage();
        $ready = $imageStorageReady && $ready;
        $imageProcessingReady = $this->checkImageProcessingRuntime();
        $ready = $imageProcessingReady && $ready;
        $googleOAuthReady = $this->checkGoogleOAuthSettings();
        $ready = $googleOAuthReady && $ready;
        $accountingOAuthReady = $this->checkAccountingOAuthSettings();
        $ready = $accountingOAuthReady && $ready;
        $mailReady = $this->checkMailSettings();
        $featureFlagsReady = $this->checkFeatureFlags();
        $schemaReady = $this->checkSchema();
        $ready = $mailReady && $ready;
        $ready = $featureFlagsReady && $ready;
        $ready = $schemaReady && $ready;
        $ready = $this->checkBillingSettings($environmentReady && $imageStorageReady && $imageProcessingReady && $googleOAuthReady && $accountingOAuthReady && $featureFlagsReady && $mailReady && $schemaReady) && $ready;

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

    private function checkImageProcessingRuntime(): bool
    {
        $ready = true;

        if (! extension_loaded('gd') || ! function_exists('imagewebp') || ! extension_loaded('exif') || ! function_exists('exif_read_data')) {
            $this->error('NG: 商品画像処理にはPHPのGD（WebP対応）とEXIF拡張が必要です。');
            $ready = false;
        }

        $maxSourcePixels = filter_var(config('furimadeck.product_images.max_source_pixels'), FILTER_VALIDATE_INT);
        if ($maxSourcePixels === false || $maxSourcePixels < 1 || $maxSourcePixels > self::MAX_PRODUCT_IMAGE_SOURCE_PIXELS) {
            $this->error('NG: FURIMADECK_PRODUCT_IMAGE_MAX_SOURCE_PIXELSは1〜12,000,000の範囲で設定してください。');
            $ready = false;
        }

        return $ready;
    }

    private function checkProductImageStorage(): bool
    {
        if (config('furimadeck.product_images.disk') !== ProductImage::PRODUCTION_R2_DISK) {
            $this->error('NG: 本番の商品画像保存先はFURIMADECK_PRODUCT_IMAGE_DISK=furimadeck_r2に設定してください。');

            return false;
        }

        $disk = config('filesystems.disks.'.ProductImage::PRODUCTION_R2_DISK);
        if (! is_array($disk)
            || ($disk['driver'] ?? null) !== 's3'
            || ($disk['visibility'] ?? null) !== 'private'
            || ($disk['use_path_style_endpoint'] ?? null) !== true) {
            $this->error('NG: 本番商品画像用R2ディスクはprivateなS3互換設定が必要です。');

            return false;
        }

        foreach (['key', 'secret', 'bucket', 'endpoint'] as $setting) {
            if (! is_string($disk[$setting] ?? null) || trim($disk[$setting]) === '') {
                $this->error('NG: 本番商品画像用R2のアクセス設定が不足しています。');

                return false;
            }
        }

        if (! $this->isHttpsUrl($disk['endpoint'])) {
            $this->error('NG: 本番商品画像用R2 endpointはHTTPS URLにしてください。');

            return false;
        }

        return true;
    }

    private function checkMailSettings(): bool
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || str_ends_with($from, '@localhost')) {
            $this->error('NG: 本番のMAIL_FROM_ADDRESSが正しいメールアドレスではありません。');

            return false;
        }

        $fromDomain = strtolower(substr(strrchr($from, '@'), 1));
        $verifiedSendingDomain = config('services.resend.verified_sending_domain');
        if (! (bool) config('services.resend.sending_domain_verified') || ! is_string($verifiedSendingDomain) || strtolower(trim($verifiedSendingDomain)) !== $fromDomain) {
            $this->error('NG: Resend画面で送信ドメインがVerifiedかつSending enabledであることを確認し、RESEND_SENDING_DOMAIN_VERIFIED=trueとMAIL_FROM_ADDRESSに一致するRESEND_VERIFIED_SENDING_DOMAINを設定してください。');

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

        $requiredFlags = [
            'cutover_enabled' => 'FurimaDeck切替',
            'product_management_enabled' => '商品管理',
        ];

        foreach ($requiredFlags as $setting => $label) {
            if (! (bool) config('furimadeck.'.$setting)) {
                $this->error('NG: '.$label.'の機能フラグを有効にしてください。');
                $ready = false;
            }
        }

        if ((bool) config('furimadeck.accounting.provider_api_enabled')
            || (bool) config('furimadeck.accounting.direct_journal_send_enabled')) {
            $this->error('NG: FurimaDeck 1.0では会計API送信ゲートを無効にしてください。');
            $ready = false;
        }

        return $ready;
    }

    private function checkGoogleOAuthSettings(): bool
    {
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');
        $isConfigured = is_string($clientId) && $clientId !== '';
        $hasSecret = is_string($clientSecret) && $clientSecret !== '';

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

    private function checkAccountingOAuthSettings(): bool
    {
        $ready = true;

        foreach (self::ACCOUNTING_OAUTH_REQUIREMENTS as $provider => $requirements) {
            $prefix = 'furimadeck.accounting.oauth.'.$provider;
            $missingCredentials = [];
            foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
                if (trim((string) config($prefix.'.'.$key)) === '') {
                    $missingCredentials[] = $key;
                }
            }

            if ($missingCredentials !== []) {
                $this->error('NG: '.$requirements['label'].'会計OAuthのclient ID、client secret、redirect URIが未設定です。');
                $ready = false;
            }

            $redirectUri = trim((string) config($prefix.'.redirect_uri'));
            $callbackPath = route('furimadeck-accounting.callback', ['provider' => $provider], false);
            $expectedRedirectUri = rtrim((string) config('app.url'), '/').$callbackPath;
            if (! $this->isHttpsUrl($redirectUri) || $redirectUri !== $expectedRedirectUri) {
                $this->error('NG: '.$requirements['label'].'会計OAuth redirect URIが本番APP_URLのコールバックURLと一致しません。');
                $ready = false;
            }

            foreach ($requirements['endpoints'] as $key => $expectedHost) {
                if (! $this->isOfficialHttpsEndpoint(config($prefix.'.'.$key), $expectedHost)) {
                    $this->error('NG: '.$requirements['label'].'会計連携 endpoint '.$key.' は公式HTTPS endpointである必要があります。');
                    $ready = false;
                }
            }

            $scope = preg_split('/\s+/', trim((string) config($prefix.'.scope')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $missingScopes = array_values(array_diff($requirements['scopes'], $scope));
            if ($missingScopes !== []) {
                $this->error('NG: '.$requirements['label'].'会計OAuth scopeに必須権限が不足しています: '.implode(', ', $missingScopes));
                $ready = false;
            }
        }

        return $ready;
    }

    private function isOfficialHttpsEndpoint(mixed $url, string $expectedHost): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === $expectedHost
            && (($parts['port'] ?? self::HTTPS_PORT) === self::HTTPS_PORT)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
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
            $this->error('NG: FurimaDeckのStripe Priceが有効なJPY月額プランと一致しません。');
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
            $missingTables = array_values(array_filter(self::REQUIRED_TABLES, fn (string $table): bool => ! $schema->hasTable($table)));
            if ($missingTables !== []) {
                $this->error('NG: FurimaDeck DBに必須テーブルがありません。');
                foreach ($missingTables as $table) {
                    $this->line('NG詳細: '.$table);
                }

                return false;
            }

            $missingColumns = [];
            foreach (self::REQUIRED_COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    if (! $schema->hasColumn($table, $column)) {
                        $missingColumns[] = $table.'.'.$column;
                    }
                }
            }
            if ($missingColumns !== []) {
                $this->error('NG: FurimaDeck DBに必須カラムがありません。');
                foreach ($missingColumns as $column) {
                    $this->line('NG詳細: '.$column);
                }

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
