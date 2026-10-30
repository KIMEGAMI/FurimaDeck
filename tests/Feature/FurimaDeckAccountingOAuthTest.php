<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\User;
use App\Services\FurimaDeckAccountingOAuthService;
use App\Services\FurimaDeckMoneyForwardAccountingSetupService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckAccountingOAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('furimadeck.accounting.oauth.freee', [
            'client_id' => 'freee-client',
            'client_secret' => 'freee-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/freee',
            'authorize_url' => 'https://accounts.secure.freee.co.jp/public_api/authorize',
            'token_url' => 'https://accounts.secure.freee.co.jp/public_api/token',
            'scope' => 'read write',
        ]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_oauth_state_is_stored_and_callback_tokens_are_encrypted(): void
    {
        $user = $this->premiumUser();
        $response = $this->actingAs($user)->get(route('furimadeck-accounting.connect', 'freee'));

        $response->assertRedirect();
        $state = $response->headers->get('Location');
        parse_str((string) parse_url((string) $state, PHP_URL_QUERY), $query);
        $this->assertSame('read', $query['scope']);
        $this->assertSame($query['state'], session('furimadeck.accounting.oauth_state.freee'));

        Http::fake(['https://accounts.secure.freee.co.jp/public_api/token' => Http::response([
            'access_token' => 'access-secret',
            'refresh_token' => 'refresh-secret',
            'expires_in' => 21600,
            'company_id' => 123,
        ])]);

        $callback = $this->withSession(['furimadeck.accounting.oauth_state.freee' => $query['state']])
            ->actingAs($user)
            ->get(route('furimadeck-accounting.callback', ['provider' => 'freee', 'code' => 'authorization-code', 'state' => $query['state']]));

        $callback->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']));
        $stored = AccountingConnection::query()->firstOrFail();
        $this->assertSame('access-secret', $stored->access_token);
        $this->assertNotSame('access-secret', DB::connection('furimadeck')->table('accounting_connections')->value('access_token'));
        $this->assertSame('123', $stored->external_account_id);
    }

    public function test_money_forward_authorization_requests_only_csv_master_read_scopes(): void
    {
        $user = $this->premiumUser();
        $settings = (array) config('furimadeck.accounting.oauth.money_forward');
        $settings['client_id'] = 'mf-client';
        $settings['client_secret'] = 'mf-secret';
        $settings['redirect_uri'] = 'http://127.0.0.1/callback/money-forward';
        $settings['scope'] = 'mfc/admin/tenant.read mfc/accounting/offices.read mfc/accounting/accounts.read mfc/accounting/taxes.read mfc/accounting/journal.read mfc/accounting/journal.write';
        config()->set('furimadeck.accounting.oauth.money_forward', $settings);

        $url = app(FurimaDeckAccountingOAuthService::class)->authorizationUrl($user, 'money_forward', 'oauth-state');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame([
            'mfc/admin/tenant.read',
            'mfc/accounting/accounts.read',
            'mfc/accounting/taxes.read',
        ], explode(' ', (string) ($query['scope'] ?? '')));
    }

    public function test_money_forward_exchange_verifies_and_stores_tenant_code(): void
    {
        $user = $this->premiumUser();
        config()->set('furimadeck.accounting.oauth.money_forward', [
            'client_id' => 'mf-client',
            'client_secret' => 'mf-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/money-forward',
            'authorize_url' => 'https://api.biz.moneyforward.com/authorize',
            'token_url' => 'https://api.biz.moneyforward.com/token',
            'api_base' => 'https://api.biz.moneyforward.com',
            'scope' => 'mfc/admin/tenant.read',
        ]);
        Http::fake([
            'https://api.biz.moneyforward.com/token' => Http::response([
                'access_token' => 'mf-access',
                'refresh_token' => 'mf-refresh',
                'expires_in' => 3600,
            ]),
            'https://api.biz.moneyforward.com/v2/tenant' => Http::response([
                'tenant_code' => 'tenant-001',
                'tenant_name' => 'テスト事業者',
            ]),
        ]);

        $connection = app(FurimaDeckAccountingOAuthService::class)->exchange($user, 'money_forward', 'authorization-code');

        $this->assertSame('tenant-001', $connection->external_account_id);
        $this->assertSame('connected', $connection->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.biz.moneyforward.com/v2/tenant'
            && $request->hasHeader('Authorization', 'Bearer mf-access'));
    }

    public function test_forced_money_forward_refresh_rotates_tokens_even_when_expiry_is_in_the_future(): void
    {
        $user = $this->premiumUser();
        config()->set('furimadeck.accounting.oauth.money_forward', [
            'client_id' => 'mf-client',
            'client_secret' => 'mf-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/money-forward',
            'token_url' => 'https://api.biz.test/token',
            'api_base' => 'https://api.biz.test',
            'scope' => 'mfc/accounting.read',
        ]);
        $user->accountingConnections()->create([
            'provider' => 'money_forward',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.biz.test/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
            'https://api.biz.test/v2/tenant' => Http::response(['tenant_code' => 'test-tenant']),
        ]);

        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward', true);

        $this->assertSame('new-access', $connection->access_token);
        $this->assertSame('new-refresh', $connection->refresh_token);
        $this->assertSame('test-tenant', $connection->external_account_id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.biz.test/token'
            && $request->method() === 'POST'
            && $request->data()['grant_type'] === 'refresh_token');
    }

    public function test_forced_refresh_reuses_token_rotated_by_another_request(): void
    {
        $user = $this->premiumUser();
        $user->accountingConnections()->create([
            'provider' => 'money_forward',
            'access_token' => 'already-rotated-access',
            'refresh_token' => 'already-rotated-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake();

        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded(
            $user,
            'money_forward',
            true,
            'rejected-old-access',
        );

        $this->assertSame('already-rotated-access', $connection->access_token);
        $this->assertSame('already-rotated-refresh', $connection->refresh_token);
        Http::assertNothingSent();
    }

    public function test_refresh_token_transport_failure_is_not_retried_and_is_marked_unknown(): void
    {
        $user = $this->premiumUser();
        config()->set('furimadeck.accounting.oauth.money_forward', [
            'client_id' => 'mf-client',
            'client_secret' => 'mf-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/money-forward',
            'token_url' => 'https://api.biz.test/token',
            'api_base' => 'https://api.biz.test',
        ]);
        $user->accountingConnections()->create([
            'provider' => 'money_forward',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->subMinute(),
            'status' => 'connected',
        ]);
        $tokenRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests) {
            if ($request->url() === 'https://api.biz.test/token') {
                $tokenRequests++;
                throw new ConnectionException('simulated connection failure');
            }

            return Http::response([], 500);
        });

        try {
            app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward');
            $this->fail('The uncertain refresh should stop and request reauthorization.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('自動再試行を停止しました', $exception->getMessage());
        }

        $this->assertSame(1, $tokenRequests);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $this->assertSame('expired', $connection->status);
        $this->assertSame('refresh_result_unknown', $connection->last_error);

        try {
            app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward');
            $this->fail('An expired connection must not retry its refresh token automatically.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('自動再試行を停止しています', $exception->getMessage());
        }
        $this->assertSame(1, $tokenRequests);
    }

    public function test_authorization_code_exchange_is_not_retried_after_transport_failure(): void
    {
        $user = $this->premiumUser();
        config()->set('furimadeck.accounting.oauth.freee.token_url', 'https://api.freee.test/token');
        $tokenRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests) {
            if ($request->url() === 'https://api.freee.test/token') {
                $tokenRequests++;
                throw new ConnectionException('simulated connection failure');
            }

            return Http::response([], 500);
        });

        try {
            app(FurimaDeckAccountingOAuthService::class)->exchange($user, 'freee', 'one-time-code');
            $this->fail('The failed one-time authorization code exchange should not be retried.');
        } catch (ConnectionException) {
            // The caller must start a fresh authorization flow after an uncertain one-time exchange.
        }

        $this->assertSame(1, $tokenRequests);
        $this->assertDatabaseCount('accounting_connections', 0);
    }

    public function test_oauth_callback_shows_safe_error_when_token_exchange_connection_fails(): void
    {
        $user = $this->premiumUser();
        $tokenRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests) {
            if ($request->url() === 'https://accounts.secure.freee.co.jp/public_api/token') {
                $tokenRequests++;
                throw new ConnectionException('simulated connection failure');
            }

            return Http::response([], 500);
        });

        $this->withSession(['furimadeck.accounting.oauth_state.freee' => 'valid-state'])
            ->actingAs($user)
            ->get(route('furimadeck-accounting.callback', [
                'provider' => 'freee',
                'code' => 'one-time-code',
                'state' => 'valid-state',
            ]))
            ->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']))
            ->assertSessionHasErrors(['accounting' => '会計サービスとの連携に失敗しました。']);

        $this->assertSame(1, $tokenRequests);
        $this->assertDatabaseCount('accounting_connections', 0);
    }

    public function test_money_forward_master_read_401_refreshes_once_and_retries_read_only_requests(): void
    {
        $user = $this->premiumUser();
        config()->set('furimadeck.accounting.oauth.money_forward', [
            'client_id' => 'mf-client',
            'client_secret' => 'mf-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/money-forward',
            'token_url' => 'https://api.biz.test/token',
            'api_base' => 'https://api.biz.test',
            'accounting_api_base' => 'https://api-accounting.test',
            'scope' => 'mfc/accounting.read',
        ]);
        $user->accountingConnections()->create([
            'provider' => 'money_forward',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $taxRequests = 0;
        Http::fake(function ($request) use (&$taxRequests) {
            if ($request->url() === 'https://api.biz.test/token') {
                return Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]);
            }
            if ($request->url() === 'https://api.biz.test/v2/tenant') {
                return Http::response(['tenant_code' => 'test-tenant']);
            }
            if (str_starts_with($request->url(), 'https://api-accounting.test/api/v3/taxes')) {
                $taxRequests++;

                return $taxRequests === 1
                    ? Http::response(['errors' => []], 401)
                    : Http::response(['taxes' => [['id' => 'tax-1']]]);
            }
            if (str_starts_with($request->url(), 'https://api-accounting.test/api/v3/accounts')) {
                return Http::response(['accounts' => []]);
            }

            return Http::response([], 500);
        });

        $options = app(FurimaDeckMoneyForwardAccountingSetupService::class)->options($user);

        $this->assertSame('tax-1', $options['taxes']['taxes'][0]['id']);
        $this->assertSame('new-access', $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->access_token);
        $this->assertSame(2, $taxRequests);
    }

    public function test_money_forward_setup_reports_the_exact_missing_api_scopes(): void
    {
        $user = $this->premiumUser();
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
        $user->accountingConnections()->create([
            'provider' => 'money_forward',
            'access_token' => 'mf-access',
            'refresh_token' => 'mf-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response([
                'errors' => [[
                    'code' => 'INSUFFICIENT_SCOPES',
                    'message' => 'The provided access token scopes are missing the following scopes: [mfc/accounting/taxes.read]',
                ]],
            ], 403),
        ]);

        try {
            app(FurimaDeckMoneyForwardAccountingSetupService::class)->options($user);
            $this->fail('不足している会計APIスコープを示す例外が必要です。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('mfc/accounting/taxes.read', $exception->getMessage());
            $this->assertStringContainsString('連携を解除してから再連携', $exception->getMessage());
        }
    }

    public function test_invalid_oauth_state_is_rejected_and_reconnection_preserves_settings(): void
    {
        $user = $this->premiumUser();
        $this->withSession(['furimadeck.accounting.oauth_state.freee' => 'expected'])
            ->actingAs($user)
            ->get(route('furimadeck-accounting.callback', ['provider' => 'freee', 'code' => 'code', 'state' => 'wrong']))
            ->assertStatus(419);

        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'external_account_id' => 'company-001',
            'settings' => ['account_ids' => ['sales' => 'sales-account']],
            'status' => 'connected',
        ]);
        $this->withoutMiddleware()
            ->actingAs($user)
            ->delete(route('furimadeck-accounting.disconnect', 'freee'))
            ->assertRedirect();
        $connection = $user->accountingConnections()->where('provider', 'freee')->firstOrFail();
        $this->assertSame(1, AccountingConnection::query()->count());
        $this->assertSame('', $connection->access_token);
        $this->assertSame('', $connection->refresh_token);
        $this->assertNull($connection->expires_at);
        $this->assertNull($connection->external_account_id);
        $this->assertSame('disconnected', $connection->status);
        $this->assertSame(['account_ids' => ['sales' => 'sales-account']], $connection->settings);

        Http::fake(['https://accounts.secure.freee.co.jp/public_api/token' => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
            'company_id' => 'company-001',
        ])]);
        $reconnected = app(FurimaDeckAccountingOAuthService::class)->exchange($user, 'freee', 'authorization-code');

        $this->assertSame('connected', $reconnected->status);
        $this->assertSame('new-access', $reconnected->access_token);
        $this->assertSame(['account_ids' => ['sales' => 'sales-account']], $reconnected->settings);
    }

    public function test_accounting_page_explains_money_forward_setup_and_csv_support(): void
    {
        $user = $this->premiumUser();
        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'freee-token',
            'refresh_token' => 'freee-refresh',
            'status' => 'connected',
        ]);
        $user->accountingConnections()->create([
            'provider' => 'money_forward',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'status' => 'connected',
            'external_account_id' => 'tenant-001',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['section' => 'connections']))
            ->assertOk()
            ->assertSee('CSV出力前に次の設定が必要です。')
            ->assertSee('勘定科目が貸借のどちら側で使われるか確認')
            ->assertSee('入金先の勘定科目')
            ->assertSee('Money Forward仕訳設定を開く')
            ->assertDontSee('連携済み・仕訳設定済みです。');
    }

    public function test_accounting_page_explains_expired_connection_recovery_without_internal_error_codes(): void
    {
        $user = $this->premiumUser();
        $user->accountingConnections()->create([
            'provider' => 'money_forward',
            'access_token' => 'stale-access',
            'refresh_token' => 'stale-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'expired',
            'last_error' => 'refresh_result_unknown',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['section' => 'connections']))
            ->assertOk()
            ->assertSee('再連携が必要')
            ->assertSee('認証更新を完了できず、自動再試行を停止しました。')
            ->assertSee('保存済みの仕訳設定と送信履歴は保持されます。')
            ->assertSee('再連携する')
            ->assertDontSee('refresh_result_unknown');
    }

    public function test_vendor_journal_csv_is_unavailable_by_default_even_by_direct_url(): void
    {
        $user = $this->premiumUser();

        $this->actingAs($user)->post(route('furimadeck-accounting.freee', ['year' => 2026]))
            ->assertRedirect(route('furimadeck-accounting.index', ['section' => 'csv', 'year' => 2026]))
            ->assertSessionHas('error');
        $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'))
            ->assertRedirect(route('furimadeck-accounting.index', ['section' => 'csv', 'year' => now()->year]))
            ->assertSessionHas('error');
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['section' => 'csv']))
            ->assertOk()
            ->assertDontSee('freee CSV</a>', false)
            ->assertDontSee('Money Forward CSV</a>', false)
            ->assertSee('共通CSVをダウンロード');
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
    }
}
