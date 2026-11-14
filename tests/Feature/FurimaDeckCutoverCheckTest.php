<?php

namespace Tests\Feature;

use App\Models\ProductInventoryEvent;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FurimaDeckCutoverCheckTest extends TestCase
{
    private const STRIPE_SECRET_PREFIX = 'sk';

    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('database.default', 'furimadeck');
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
        $this->assertTrue(DB::connection('furimadeck')->getSchemaBuilder()->hasTable('users'));
        User::factory()->create(['is_admin' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://furimadeck.kimegami.jp');
        config()->set('furimadeck.production_app_url', 'https://furimadeck.kimegami.jp');
        config()->set('app.force_https', true);
        config()->set('session.secure', true);
        config()->set('services.google.client_id', 'configured');
        config()->set('services.google.client_secret', 'configured');
        config()->set('services.google.redirect', 'https://furimadeck.kimegami.jp/auth/google/callback');
        config()->set('furimadeck.accounting.oauth.freee.client_id', 'configured');
        config()->set('furimadeck.accounting.oauth.freee.client_secret', 'configured');
        config()->set('furimadeck.accounting.oauth.freee.redirect_uri', rtrim((string) config('app.url'), '/').route('furimadeck-accounting.callback', ['provider' => 'freee'], false));
        config()->set('furimadeck.accounting.oauth.freee.scope', 'read');
        config()->set('furimadeck.accounting.oauth.freee.api_base', 'https://api.freee.co.jp');
        config()->set('furimadeck.accounting.oauth.money_forward.client_id', 'configured');
        config()->set('furimadeck.accounting.oauth.money_forward.client_secret', 'configured');
        config()->set('furimadeck.accounting.oauth.money_forward.redirect_uri', rtrim((string) config('app.url'), '/').route('furimadeck-accounting.callback', ['provider' => 'money_forward'], false));
        config()->set('furimadeck.accounting.oauth.money_forward.scope', 'mfc/admin/tenant.read mfc/accounting/accounts.read mfc/accounting/taxes.read');
        config()->set('furimadeck.accounting.oauth.money_forward.api_base', 'https://api.biz.moneyforward.com');
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.moneyforward.com');
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'noreply@furimadeck.example');
        config()->set('mail.mailers.smtp.host', 'smtp.resend.com');
        config()->set('mail.mailers.smtp.port', 587);
        config()->set('mail.mailers.smtp.username', 'resend');
        config()->set('mail.mailers.smtp.password', 'configured');
        config()->set('contact.to_address', 'support@kimegami.jp');
        config()->set('legal.contact_email', 'support@kimegami.jp');
        config()->set('services.resend.verified_sending_domain', 'furimadeck.example');
        config()->set('services.resend.sending_domain_verified', true);
        config()->set('furimadeck.product_images.disk', 'furimadeck_r2');
        config()->set('furimadeck.production_product_image_bucket', 'furimadeck-prod-images');
        config()->set('filesystems.disks.furimadeck_r2', [
            'driver' => 's3',
            'key' => 'configured',
            'secret' => 'configured',
            'region' => 'auto',
            'bucket' => 'furimadeck-prod-images',
            'endpoint' => 'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
        ]);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', false);
        config()->set('demo.user_enabled', false);
        config()->set('furimadeck.billing.stripe_secret', $this->stripeSecretFor('live'));
        config()->set('furimadeck.billing.stripe_webhook_secret', 'whsec_configured');
        config()->set('furimadeck.billing.stripe_price_id', 'price_furimadeck_980');
        config()->set('furimadeck.billing.stripe_portal_configuration_id', null);
        config()->set('furimadeck.billing.monthly_price_jpy', 980);
        config()->set('furimadeck.billing.stripe_api_base', 'https://api.stripe.com/v1');
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
            'https://api.stripe.com/v1/billing_portal/configurations*' => Http::response([
                'data' => [
                    [
                        'id' => 'bpc_live_furimadeck',
                        'active' => true,
                        'is_default' => true,
                        'livemode' => true,
                        'features' => ['subscription_cancel' => ['enabled' => true]],
                    ],
                ],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    private function stripeSecretFor(string $mode): string
    {
        return sprintf('%s_%s_configured', self::STRIPE_SECRET_PREFIX, $mode);
    }

    public function test_it_passes_for_a_clean_production_ready_furimadeck_database(): void
    {
        $exitCode = Artisan::call('furimadeck:check-cutover');

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    public function test_it_blocks_cutover_and_skips_stripe_requests_when_required_feature_flags_are_disabled(): void
    {
        config()->set('furimadeck.cutover_enabled', false);
        config()->set('furimadeck.product_management_enabled', false);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck切替の機能フラグを有効にしてください。')
            ->expectsOutput('NG: 商品管理の機能フラグを有効にしてください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_requires_the_product_inventory_event_ledger_before_cutover(): void
    {
        Schema::connection('furimadeck')->drop('product_inventory_events');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck DBに必須テーブルがありません。')
            ->expectsOutput('NG詳細: product_inventory_events')
            ->assertExitCode(1);
    }

    public function test_it_blocks_cutover_when_inventory_and_ledger_balances_differ(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'CUTOVER-LEDGER-001',
            'product_name' => '合成テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        ProductInventoryEvent::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_INITIAL_PURCHASE,
            'quantity_delta' => 0,
            'source_type' => 'test',
            'source_id' => $product->id,
            'idempotency_key' => hash('sha256', 'cutover-inventory-ledger-test'),
            'occurred_at' => now(),
            'after_state' => [],
            'actor_user_id' => $user->id,
            'reason' => '合成テストデータ',
        ]);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: 在庫残高の不一致 1件')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_requires_a_verified_admin_user_without_calling_stripe(): void
    {
        DB::connection('furimadeck')->table('users')->where('is_admin', true)->delete();
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: メール認証済みの管理者ユーザーがいません。管理者アカウントを準備してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_rejects_an_unverified_admin_user_without_calling_stripe(): void
    {
        DB::connection('furimadeck')->table('users')->where('is_admin', true)->delete();
        User::factory()->unverified()->create(['is_admin' => true]);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: メール認証済みの管理者ユーザーがいません。管理者アカウントを準備してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_requires_a_configured_demo_user_to_exist_and_be_verified(): void
    {
        config()->set('demo.user_enabled', true);
        config()->set('demo.user_email', 'demo@example.com');
        config()->set('demo.user_password', 'demo-password');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: デモユーザーアカウントが未作成です。furimadeck:sync-demo-userを実行してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_rejects_an_unverified_demo_user_without_calling_stripe(): void
    {
        config()->set('demo.user_enabled', true);
        config()->set('demo.user_email', 'demo@example.com');
        config()->set('demo.user_password', 'password');
        User::factory()->unverified()->create(['email' => 'demo@example.com']);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: デモユーザーのメール認証状態が未完了です。furimadeck:sync-demo-userを実行してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_requires_a_configured_demo_users_password_to_match_database(): void
    {
        config()->set('demo.user_enabled', true);
        config()->set('demo.user_email', 'demo@example.com');
        config()->set('demo.user_password', 'configured-demo-password');
        User::factory()->create([
            'email' => 'demo@example.com',
            'password' => 'different-demo-password',
        ]);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: デモユーザーの設定パスワードがDBと一致しません。設定を確認し、必要なら furimadeck:sync-demo-user --sync-password を実行してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_passes_with_a_verified_demo_user_matching_the_configured_password(): void
    {
        config()->set('demo.user_enabled', true);
        config()->set('demo.user_email', 'demo@example.com');
        config()->set('demo.user_password', 'demo-password');
        User::factory()->create([
            'email' => 'demo@example.com',
            'password' => Hash::make('demo-password'),
        ]);

        $this->assertSame(0, Artisan::call('furimadeck:check-cutover'), Artisan::output());
    }

    public function test_it_rejects_a_trial_period_other_than_seven_days_before_calling_stripe(): void
    {
        config()->set('furimadeck.billing.trial_period_days', 14);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FURIMADECK_TRIAL_PERIOD_DAYSは7日に設定してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_rejects_a_non_production_app_url_without_calling_stripe(): void
    {
        config()->set('app.url', 'https://legacy.example.test');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: APP_URLはFURIMADECK_PRODUCTION_APP_URLと一致する必要があります。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_the_expected_production_app_url_is_missing(): void
    {
        config()->set('furimadeck.production_app_url', null);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FURIMADECK_PRODUCTION_APP_URLに本番HTTPS URLを設定してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_requires_the_production_r2_disk_for_product_images(): void
    {
        config()->set('furimadeck.product_images.disk', 'local');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: 本番の商品画像保存先はCloudflare R2（FURIMADECK_PRODUCT_IMAGE_DISK=furimadeck_r2）にしてください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_rejects_incomplete_or_non_cloudflare_production_r2_configuration(): void
    {
        config()->set('filesystems.disks.furimadeck_r2.secret', null);
        config()->set('filesystems.disks.furimadeck_r2.endpoint', 'https://storage.example.test');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FURIMADECK_PRODUCTION_R2_BUCKETと本番Cloudflare R2の認証情報・バケット・HTTPS endpoint・非公開設定を確認してください。秘密値は表示していません。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_rejects_the_development_bucket_in_production_configuration(): void
    {
        config()->set('filesystems.disks.furimadeck_r2.bucket', 'furimadeck-dev-images');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FURIMADECK_PRODUCTION_R2_BUCKETと本番Cloudflare R2の認証情報・バケット・HTTPS endpoint・非公開設定を確認してください。秘密値は表示していません。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_the_expected_production_r2_bucket_is_not_configured(): void
    {
        config()->set('furimadeck.production_product_image_bucket', null);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FURIMADECK_PRODUCTION_R2_BUCKETと本番Cloudflare R2の認証情報・バケット・HTTPS endpoint・非公開設定を確認してください。秘密値は表示していません。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_no_active_live_stripe_customer_portal_configuration_exists(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
            'https://api.stripe.com/v1/billing_portal/configurations*' => Http::response(['data' => []]),
        ]);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck用の有効なStripe本番Customer Portal設定（解約機能有効）がありません。')
            ->assertExitCode(1);

        Http::assertSentCount(2);
    }

    public function test_it_requires_the_default_portal_configuration_when_no_id_is_configured(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
            'https://api.stripe.com/v1/billing_portal/configurations*' => Http::response([
                'data' => [
                    [
                        'id' => 'bpc_live_other',
                        'active' => true,
                        'is_default' => false,
                        'livemode' => true,
                        'features' => ['subscription_cancel' => ['enabled' => true]],
                    ],
                ],
            ]),
        ]);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck用の有効なStripe本番Customer Portal設定（解約機能有効）がありません。')
            ->assertExitCode(1);

        Http::assertSentCount(2);
    }

    public function test_it_requires_portal_cancellation_to_be_enabled(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
            'https://api.stripe.com/v1/billing_portal/configurations*' => Http::response([
                'data' => [[
                    'id' => 'bpc_live_furimadeck',
                    'active' => true,
                    'is_default' => true,
                    'livemode' => true,
                    'features' => ['subscription_cancel' => ['enabled' => false]],
                ]],
            ]),
        ]);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck用の有効なStripe本番Customer Portal設定（解約機能有効）がありません。')
            ->assertExitCode(1);

        Http::assertSentCount(2);
    }

    public function test_it_requires_the_configured_stripe_customer_portal_id_to_be_active_and_live(): void
    {
        config()->set('furimadeck.billing.stripe_portal_configuration_id', 'bpc_furimadeck');
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
            'https://api.stripe.com/v1/billing_portal/configurations/bpc_furimadeck' => Http::response([
                'id' => 'bpc_furimadeck',
                'active' => true,
                'livemode' => false,
                'features' => ['subscription_cancel' => ['enabled' => true]],
            ]),
        ]);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck用の有効なStripe本番Customer Portal設定（解約機能有効）がありません。')
            ->assertExitCode(1);

        Http::assertSentCount(2);
    }

    public function test_it_accepts_the_configured_active_live_stripe_customer_portal_id(): void
    {
        config()->set('furimadeck.billing.stripe_portal_configuration_id', 'bpc_furimadeck');
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/billing_portal/configurations/bpc_furimadeck' => Http::response([
                'id' => 'bpc_furimadeck',
                'active' => true,
                'livemode' => true,
                'features' => ['subscription_cancel' => ['enabled' => true]],
            ]),
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => true,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
        ]);

        $this->assertSame(0, Artisan::call('furimadeck:check-cutover'), Artisan::output());

        Http::assertSentCount(2);
    }

    public function test_cutover_requires_webp_and_exif_image_support_before_checking_stripe(): void
    {
        if (function_exists('imagewebp') && function_exists('exif_read_data')) {
            $this->assertSame(0, Artisan::call('furimadeck:check-cutover'), Artisan::output());

            return;
        }

        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: 商品画像処理にはPHPのGD（WebP対応）とEXIF拡張が必要です。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_cutover_rejects_legacy_image_pixel_limit_before_checking_stripe(): void
    {
        config()->set('furimadeck.product_images.max_source_pixels', 40_000_000);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FURIMADECK_PRODUCT_IMAGE_MAX_SOURCE_PIXELSは1〜12,000,000の範囲で設定してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_a_required_accounting_table_is_missing(): void
    {
        Schema::connection('furimadeck')->drop('accounting_entry_syncs');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck DBに必須テーブルがありません。')
            ->expectsOutput('NG詳細: accounting_entry_syncs')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_before_cutover_when_accounting_sync_batches_table_is_missing(): void
    {
        Schema::connection('furimadeck')->drop('accounting_sync_batches');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck DBに必須テーブルがありません。')
            ->expectsOutput('NG詳細: accounting_sync_batches')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_a_required_accounting_column_is_missing(): void
    {
        Schema::connection('furimadeck')->table('accounting_entry_syncs', function (Blueprint $table): void {
            $table->dropColumn('external_transaction_id');
        });

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck DBに必須カラムがありません。')
            ->expectsOutput('NG詳細: accounting_entry_syncs.external_transaction_id')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_multi_product_purchase_allocation_columns_are_missing(): void
    {
        Schema::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->dropColumn('allocated_amount');
        });

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck DBに必須カラムがありません。')
            ->expectsOutput('NG詳細: manual_product_purchase_reconciliation_refs.allocated_amount')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_required_runtime_table_is_missing(): void
    {
        Schema::connection('furimadeck')->drop('system_settings');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck DBに必須テーブルがありません。')
            ->expectsOutput('NG詳細: system_settings')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_required_runtime_column_is_missing(): void
    {
        Schema::connection('furimadeck')->table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('value');
        });

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck DBに必須カラムがありません。')
            ->expectsOutput('NG詳細: system_settings.value')
            ->assertExitCode(1);
    }

    public function test_it_allows_an_empty_legacy_auction_table_to_remain(): void
    {
        Schema::connection('furimadeck')->create('auction_items', function (Blueprint $table): void {
            $table->id();
        });

        $exitCode = Artisan::call('furimadeck:check-cutover');

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertStringNotContainsString('旧auction_items', Artisan::output());
    }

    public function test_it_fails_when_legacy_auction_data_is_present(): void
    {
        Schema::connection('furimadeck')->create('auction_items', function (Blueprint $table): void {
            $table->id();
        });
        DB::connection('furimadeck')->table('auction_items')->insert(['id' => 1]);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck DBに旧auction_itemsテーブルの未移行データが存在します。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_the_application_default_connection_is_not_furimadeck(): void
    {
        config()->set('database.default', 'sqlite');

        $this->artisan('furimadeck:check-cutover')->assertExitCode(1);
    }

    public function test_it_rejects_a_legacy_send_flag_when_the_new_gate_is_missing(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck 1.0では会計API送信ゲートを無効にしてください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_rejects_the_provider_api_gate_when_direct_journal_send_is_disabled(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', false);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck 1.0では会計API送信ゲートを無効にしてください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_rejects_an_enabled_accounting_api_gate_for_version_one(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeck 1.0では会計API送信ゲートを無効にしてください。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_debug_mode_is_enabled(): void
    {
        config()->set('app.debug', true);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: APP_DEBUG=false が必要です。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_vite_development_server_marker_is_present(): void
    {
        File::shouldReceive('exists')
            ->once()
            ->with(public_path('hot'))
            ->andReturn(true);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: public/hot を削除して本番ビルドを使用してください。')
            ->assertExitCode(1);
    }

    public function test_it_does_not_call_stripe_when_local_checks_fail(): void
    {
        config()->set('app.debug', true);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_stripe_secret_is_not_a_live_mode_key(): void
    {
        config()->set('furimadeck.billing.stripe_secret', $this->stripeSecretFor('test'));

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeckのstripe_secretはStripe Live Modeキーが必要です。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_stripe_webhook_secret_is_not_a_webhook_signing_secret(): void
    {
        config()->set('furimadeck.billing.stripe_webhook_secret', 'configured');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeckのstripe_webhook_secretはStripe Webhook署名シークレットが必要です。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_stripe_price_is_not_an_active_jpy_monthly_plan(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://api.stripe.com/v1/prices/*' => Http::response([
                'active' => false,
                'currency' => 'jpy',
                'recurring' => ['interval' => 'month'],
                'unit_amount' => 980,
            ]),
        ]);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeckのStripe Priceが有効なJPY月額プランと一致しません。')
            ->assertExitCode(1);
    }

    public function test_it_fails_closed_when_stripe_price_cannot_be_read(): void
    {
        Http::swap(new Factory);
        Http::fake(function (): void {
            throw new ConnectionException('Connection timed out.');
        });

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: FurimaDeckのStripe Priceを確認できませんでした。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_configured_google_oauth_uses_a_different_redirect_uri(): void
    {
        config()->set('services.google.client_id', 'configured');
        config()->set('services.google.client_secret', 'configured');
        config()->set('services.google.redirect', 'https://legacy.example.test/auth/google/callback');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: Google OAuth redirect URIがAPP_URLと一致しません。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_google_oauth_credentials_are_missing(): void
    {
        config()->set('services.google.client_id', null);
        config()->set('services.google.client_secret', null);
        config()->set('services.google.redirect', null);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: Google OAuthのclient IDとclient secretは両方必要です。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_accounting_oauth_credentials_are_missing_without_calling_stripe(): void
    {
        config()->set('furimadeck.accounting.oauth.freee.client_secret', null);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: freee会計OAuthのclient ID、client secret、redirect URIが未設定です。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_accounting_oauth_callback_does_not_match_the_app_url(): void
    {
        config()->set('furimadeck.accounting.oauth.freee.redirect_uri', 'https://legacy.example.test/furimadeck-accounting/callback/freee');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: freee会計OAuth redirect URIが本番APP_URLのコールバックURLと一致しません。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_money_forward_csv_master_scope_is_missing(): void
    {
        config()->set('furimadeck.accounting.oauth.money_forward.scope', 'mfc/admin/tenant.read mfc/accounting/offices.read mfc/accounting/accounts.read');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: Money Forward会計OAuth scopeに必須権限が不足しています: mfc/accounting/taxes.read')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_money_forward_tenant_api_base_uses_a_non_provider_host(): void
    {
        config()->set('furimadeck.accounting.oauth.money_forward.api_base', 'https://attacker.example.test');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: Money Forward会計連携 endpoint api_base は公式HTTPS endpointである必要があります。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_money_forward_csv_master_api_base_uses_a_non_provider_host(): void
    {
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://attacker.example.test');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: Money Forward会計連携 endpoint accounting_api_base は公式HTTPS endpointである必要があります。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_resend_sending_domain_has_not_been_verified(): void
    {
        config()->set('services.resend.sending_domain_verified', false);

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: Resend画面で送信ドメインがVerifiedかつSending enabledであることを確認し、RESEND_SENDING_DOMAIN_VERIFIED=trueとMAIL_FROM_ADDRESSに一致するRESEND_VERIFIED_SENDING_DOMAINを設定してください。')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_contact_recipient_is_missing(): void
    {
        config()->set('contact.to_address', '');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: CONTACT_TO_ADDRESSに有効な問い合わせ受信先メールアドレスを設定してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_public_contact_address_differs_from_the_mail_recipient(): void
    {
        config()->set('legal.contact_email', 'other@kimegami.jp');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: 特商法ページのLEGAL_CONTACT_EMAILと実際の送信先CONTACT_TO_ADDRESSを同じメールアドレスに設定してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_fails_when_mail_from_domain_does_not_match_the_verified_resend_domain(): void
    {
        config()->set('mail.from.address', 'noreply@kimegami.jp');

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: Resend画面で送信ドメインがVerifiedかつSending enabledであることを確認し、RESEND_SENDING_DOMAIN_VERIFIED=trueとMAIL_FROM_ADDRESSに一致するRESEND_VERIFIED_SENDING_DOMAINを設定してください。')
            ->assertExitCode(1);
    }

    public function test_it_rejects_an_smtp_mail_url_that_overrides_the_resend_host_settings(): void
    {
        config()->set('mail.mailers.smtp.url', 'smtp://unexpected.example.test:2525');
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')
            ->expectsOutput('NG: SMTP MAIL_URLが接続先を上書きするため、空欄にしてResend SMTPの個別設定を使用してください。')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_does_not_call_stripe_when_resend_sending_domain_is_not_verified(): void
    {
        config()->set('services.resend.sending_domain_verified', false);
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:check-cutover')->assertExitCode(1);

        Http::assertNothingSent();
    }
}
