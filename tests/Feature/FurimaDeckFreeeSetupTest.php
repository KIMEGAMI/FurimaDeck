<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\User;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FurimaDeckFreeeSetupTest extends TestCase
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
            'api_base' => 'https://api.freee.test',
            'scope' => 'read write',
        ]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_freee_master_data_can_be_selected_and_saved_per_connection(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => '個人事業所', 'display_name' => '個人事業所']]]),
            'https://api.freee.test/api/1/account_items?company_id=123' => Http::response(['account_items' => [
                ['id' => 1, 'name' => '普通預金'], ['id' => 2, 'name' => '売上高'], ['id' => 3, 'name' => '支払手数料'], ['id' => 4, 'name' => '仕入高'], ['id' => 5, 'name' => '商品'],
            ]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [['code' => '課税売上10%', 'name' => '課税売上 10%']]]),
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->assertOk()
            ->assertSee('個人事業所')
            ->assertSee('普通預金')
            ->assertSee('課税売上 10%');

        $this->actingAs($user)->post(route('furimadeck-accounting.freee.setup.save'), [
            'company_id' => 123,
            'tax_code' => '課税売上10%',
            'account_item_ids' => ['settlement' => 1, 'sales' => 2, 'fees' => 3, 'cost' => 4, 'inventory' => 5],
        ])->assertRedirect(route('furimadeck-accounting.index'));

        $connection = $user->accountingConnections()->firstOrFail();
        $this->assertSame('123', $connection->external_account_id);
        $this->assertSame('課税売上10%', $connection->settings['tax_code']);
        $this->assertSame([], app(FurimaDeckFreeeAccountingSyncService::class)->missingConfiguration($connection));
    }
}
