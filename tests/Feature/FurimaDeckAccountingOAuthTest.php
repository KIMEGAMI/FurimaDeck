<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\User;
use App\Services\FurimaDeckAccountingOAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $this->assertSame('read write', $query['scope']);
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

        $callback->assertRedirect(route('furimadeck-accounting.index'));
        $stored = AccountingConnection::query()->firstOrFail();
        $this->assertSame('access-secret', $stored->access_token);
        $this->assertNotSame('access-secret', DB::connection('furimadeck')->table('accounting_connections')->value('access_token'));
        $this->assertSame('123', $stored->external_account_id);
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

    public function test_invalid_oauth_state_is_rejected_and_disconnect_removes_connection(): void
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
            'status' => 'connected',
        ]);
        $this->withoutMiddleware()
            ->actingAs($user)
            ->delete(route('furimadeck-accounting.disconnect', 'freee'))
            ->assertRedirect();
        $this->assertSame(0, AccountingConnection::query()->count());
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
            ->get(route('furimadeck-accounting.index'))
            ->assertOk()
            ->assertSee('Money Forwardの仕訳APIへ、Confirm済みデータを送信できます。初回は勘定科目と税区分を設定してください。')
            ->assertSee('Money Forward仕訳設定を開く')
            ->assertSee('送信設定が不足しています:');
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
