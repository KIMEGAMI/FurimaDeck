<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\User;
use App\Services\FurimaDeckAccountingOAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckAccountingOAuthRefreshTest extends TestCase
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
        config()->set('furimadeck.accounting.oauth.freee', [
            'client_id' => 'client',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost/callback',
            'token_url' => 'https://accounts.secure.freee.co.jp/public_api/token',
        ]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_expired_connection_refreshes_and_preserves_rotating_refresh_token(): void
    {
        $user = User::factory()->create();
        $connection = $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->subMinute(),
            'status' => 'connected',
        ]);
        Http::fake(['https://accounts.secure.freee.co.jp/public_api/token' => Http::response([
            'access_token' => 'new-access',
            'expires_in' => 3600,
        ])]);

        $refreshed = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'freee');

        $this->assertSame('new-access', $refreshed->access_token);
        $this->assertSame('old-refresh', $refreshed->refresh_token);
        $this->assertSame('connected', $refreshed->status);
    }

    public function test_money_forward_refresh_rechecks_tenant(): void
    {
        $user = User::factory()->create();
        config()->set('furimadeck.accounting.oauth.money_forward', [
            'client_id' => 'mf-client',
            'client_secret' => 'mf-secret',
            'redirect_uri' => 'http://localhost/callback',
            'token_url' => 'https://api.biz.moneyforward.com/token',
            'api_base' => 'https://api.biz.moneyforward.com',
        ]);
        $user->accountingConnections()->create([
            'provider' => 'money_forward',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->subMinute(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.biz.moneyforward.com/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
            'https://api.biz.moneyforward.com/v2/tenant' => Http::response([
                'tenant_code' => 'tenant-refreshed',
            ]),
        ]);

        $refreshed = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward');

        $this->assertSame('new-access', $refreshed->access_token);
        $this->assertSame('tenant-refreshed', $refreshed->external_account_id);
        $this->assertSame('connected', $refreshed->status);
    }

    public function test_missing_refresh_token_marks_connection_expired(): void
    {
        $user = User::factory()->create();
        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'old-access',
            'refresh_token' => null,
            'expires_at' => now()->subMinute(),
            'status' => 'connected',
        ]);

        $this->expectException(RuntimeException::class);
        try {
            app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'freee');
        } finally {
            $this->assertSame('expired', AccountingConnection::query()->firstOrFail()->status);
        }
    }
}
