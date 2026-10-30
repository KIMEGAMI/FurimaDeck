<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\User;
use App\Services\FurimaDeckFreeeAccountingSetupService;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use App\Services\FurimaDeckFreeeCsvMappingService;
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

    public function test_freee_csv_requires_receivable_category_for_sale_debit(): void
    {
        $this->assertTrue(FurimaDeckFreeeAccountingSetupService::categoryMatchesCsvRole('settlement', ['資産', '流動資産', '売掛金']));
        $this->assertFalse(FurimaDeckFreeeAccountingSetupService::categoryMatchesCsvRole('settlement', ['資産', '流動資産', '現金・預金']));
    }

    public function test_freee_csv_blocks_a_legacy_bank_account_settlement_setting(): void
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
            'settings' => [
                'company_id' => '123',
                'account_item_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
                'tax_codes' => ['settlement' => '21', 'sales' => '11', 'fees' => '31', 'cost' => '41', 'inventory' => '21'],
            ],
        ]);
        $categories = [
            1 => ['資産', '流動資産', '現金・預金'],
            2 => ['損益', '収入金額'],
            3 => ['損益', '経費'],
            4 => ['損益', '売上原価'],
            5 => ['資産', '流動資産', '棚卸資産'],
        ];
        Http::fake(function ($request) use ($categories) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/1/companies') {
                return Http::response(['companies' => [['id' => 123, 'name' => '事業所']]]);
            }
            if ($path === '/api/1/account_items') {
                return Http::response(['account_items' => collect(range(1, 5))->map(fn (int $id): array => [
                    'id' => $id,
                    'name' => '科目'.$id,
                ])->all()]);
            }
            if ($path === '/api/1/taxes/companies/123') {
                return Http::response(['taxes' => collect(['11', '21', '31', '41'])->map(fn (string $code): array => [
                    'code' => $code,
                    'name' => 'tax_'.$code,
                    'name_ja' => '税区分'.$code,
                ])->all()]);
            }
            if (preg_match('#^/api/1/account_items/(\d+)$#', $path, $matches) === 1) {
                $id = (int) $matches[1];

                return Http::response(['account_item' => [
                    'id' => $id,
                    'name' => '科目'.$id,
                    'default_tax_code' => ['1' => '21', '2' => '11', '3' => '31', '4' => '41', '5' => '21'][(string) $id],
                    'categories' => $categories[$id],
                ]]);
            }

            return Http::response([], 404);
        });

        try {
            app(FurimaDeckFreeeCsvMappingService::class)->forEntries($user, collect());
            $this->fail('保存済み設定に銀行口座が残っている場合、freee CSV出力前に停止する必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('銀行口座科目のままではCSVを出力しません', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => strtoupper($request->method()) === 'POST');
    }

    public function test_freee_setup_rejects_an_unavailable_account_item(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $connection = AccountingConnection::query()->create(['user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected']);
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => '事業所']]]),
            'https://api.freee.test/api/1/account_items?company_id=123' => Http::response(['account_items' => [
                ['id' => 1, 'name' => '売掛金', 'available' => true],
                ['id' => 2, 'name' => '売上高', 'available' => false],
                ['id' => 3, 'name' => '支払手数料', 'available' => true],
                ['id' => 4, 'name' => '仕入高', 'available' => true],
                ['id' => 5, 'name' => '商品', 'available' => true],
            ]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [['code' => '21', 'name' => '対象外', 'name_ja' => '対象外']]]),
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->assertOk()
            ->assertDontSee('<option value="2"', false);

        try {
            app(FurimaDeckFreeeAccountingSetupService::class)->save($user, [
                'company_id' => 123,
                'account_item_ids' => ['settlement' => 1, 'sales' => 2, 'fees' => 3, 'cost' => 4, 'inventory' => 5],
            ]);
            $this->fail('freeeで利用不可の勘定科目を保存してはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('利用可能な勘定科目', $exception->getMessage());
        }

        $this->assertNull($connection->fresh()->settings);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/1/account_items/'));
    }

    public function test_freee_csv_blocks_a_previously_saved_unavailable_account_item(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => [
                'company_id' => '123',
                'account_item_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
                'tax_codes' => ['settlement' => '21', 'sales' => '11', 'fees' => '31', 'cost' => '41', 'inventory' => '21'],
            ],
        ]);
        $categories = [
            1 => ['資産', '流動資産', '売掛金'],
            2 => ['損益', '収入金額'],
            3 => ['損益', '経費'],
            4 => ['損益', '売上原価'],
            5 => ['資産', '流動資産', '棚卸資産'],
        ];
        $taxCodes = ['21' => '対象外', '11' => '課税売上10%', '31' => '課対仕入10%'];
        Http::fake(function ($request) use ($categories, $taxCodes) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/1/companies') {
                return Http::response(['companies' => [['id' => 123, 'name' => '事業所']]]);
            }
            if ($path === '/api/1/account_items') {
                return Http::response(['account_items' => collect(range(1, 5))->map(fn (int $id): array => [
                    'id' => $id,
                    'name' => '科目'.$id,
                    'available' => $id !== 2,
                    'categories' => $categories[$id],
                ])->all()]);
            }
            if ($path === '/api/1/taxes/companies/123') {
                return Http::response(['taxes' => collect($taxCodes)->map(fn (string $name, string $code): array => [
                    'code' => $code,
                    'name' => $code,
                    'name_ja' => $name,
                ])->values()->all()]);
            }
            if (preg_match('#^/api/1/account_items/(\d+)$#', $path, $matches) === 1) {
                $id = (int) $matches[1];

                return Http::response(['account_item' => [
                    'id' => $id,
                    'name' => '科目'.$id,
                    'default_tax_code' => [1 => '21', 2 => '11', 3 => '31', 4 => '31', 5 => '21'][$id],
                    'categories' => $categories[$id],
                ]]);
            }

            return Http::response([], 404);
        });

        try {
            app(FurimaDeckFreeeCsvMappingService::class)->forEntries($user, collect());
            $this->fail('以前保存された利用不可のfreee勘定科目があればCSV出力を停止する必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('現在利用できません', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => strtoupper($request->method()) === 'POST');
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
                ['id' => 1, 'name' => '売掛金', 'categories' => ['資産', '流動資産', '売掛金']], ['id' => 2, 'name' => '売上高'], ['id' => 3, 'name' => '支払手数料'], ['id' => 4, 'name' => '仕入高'], ['id' => 5, 'name' => '商品'], ['id' => 6, 'name' => '荷造運賃'], ['id' => 7, 'name' => '雑費'],
                ['id' => 8, 'name' => '利用不可口座', 'categories' => ['資産'], 'available' => false],
                ['id' => 9, 'name' => '消耗品費', 'categories' => ['費用'], 'available' => true],
            ]]),
            'https://api.freee.test/api/1/account_items/1?company_id=123' => Http::response(['account_item' => ['id' => 1, 'default_tax_code' => '21', 'categories' => ['資産', '流動資産', '売掛金']]]),
            'https://api.freee.test/api/1/account_items/2?company_id=123' => Http::response(['account_item' => ['id' => 2, 'default_tax_code' => '11', 'categories' => ['損益', '収入金額']]]),
            'https://api.freee.test/api/1/account_items/3?company_id=123' => Http::response(['account_item' => ['id' => 3, 'default_tax_code' => '31', 'categories' => ['損益', '経費']]]),
            'https://api.freee.test/api/1/account_items/4?company_id=123' => Http::response(['account_item' => ['id' => 4, 'default_tax_code' => '41', 'categories' => ['損益', '売上原価']]]),
            'https://api.freee.test/api/1/account_items/5?company_id=123' => Http::response(['account_item' => ['id' => 5, 'default_tax_code' => '21', 'categories' => ['資産', '流動資産', '棚卸資産']]]),
            'https://api.freee.test/api/1/account_items/6?company_id=123' => Http::response(['account_item' => ['id' => 6, 'default_tax_code' => '31', 'categories' => ['損益', '経費']]]),
            'https://api.freee.test/api/1/account_items/7?company_id=123' => Http::response(['account_item' => ['id' => 7, 'default_tax_code' => '31', 'categories' => ['損益', '経費']]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [
                ['code' => '11', 'name' => 'sales_with_tax_10', 'name_ja' => '課税売上10%'], ['code' => '21', 'name' => 'non_taxable', 'name_ja' => '対象外'], ['code' => '31', 'name' => 'purchase_with_tax_10', 'name_ja' => '課対仕入10%'], ['code' => '41', 'name' => 'purchase_with_tax_10', 'name_ja' => '課対仕入10%'],
            ]]),
        ]);

        $options = app(FurimaDeckFreeeAccountingSetupService::class)->options($user, 123);
        $this->assertSame('課税売上10%', collect($options['tax_codes'])->firstWhere('code', '11')['name_ja']);
        $this->assertSame([1], collect($options['purchase_payment_account_items'])->pluck('id')->all());

        $this->actingAs($user)->get(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->assertOk()
            ->assertSee('個人事業所')
            ->assertSee('売掛金（入金前の売上）')
            ->assertSee('送料')
            ->assertSee('その他費用')
            ->assertSee('freeeの標準税区分')
            ->assertSee('保存時に確認')
            ->assertDontSee('の税区分<select');

        $this->actingAs($user)->post(route('furimadeck-accounting.freee.setup.save'), [
            'company_id' => 123,
            'account_item_ids' => ['settlement' => 1, 'sales' => 2, 'fees' => 3, 'shipping' => 6, 'other' => 7, 'cost' => 4, 'inventory' => 5],
            'purchase_payment_account_ids_present' => '1',
            'purchase_payment_account_ids' => [1],
        ])->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']));

        $connection = $user->accountingConnections()->firstOrFail();
        $this->assertSame('123', $connection->external_account_id);
        $this->assertSame('11', $connection->settings['tax_codes']['sales']);
        $this->assertSame('21', $connection->settings['tax_codes']['settlement']);
        $this->assertSame('31', $connection->settings['tax_codes']['fees']);
        $this->assertSame('6', $connection->settings['account_item_ids']['shipping']);
        $this->assertSame('7', $connection->settings['account_item_ids']['other']);
        $this->assertSame(['資産', '流動資産', '棚卸資産'], $connection->settings['account_categories']['inventory']);
        $this->assertSame(['account_id' => '1', 'name' => '売掛金', 'account_category' => ''], $connection->settings['purchase_payment_accounts']['1']);
        $this->assertCount(7, $connection->settings['tax_codes']);
        $this->assertSame([], app(FurimaDeckFreeeAccountingSyncService::class)->missingConfiguration($connection));

        $this->actingAs($user)->post(route('furimadeck-accounting.freee.setup.save'), [
            'company_id' => 123,
            'account_item_ids' => ['settlement' => 1, 'sales' => 2, 'fees' => 3, 'cost' => 4, 'inventory' => 5],
        ])->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']));

        $connection = $connection->fresh();
        $this->assertSame(['settlement', 'sales', 'fees', 'cost', 'inventory'], array_keys($connection->settings['account_item_ids']));
        $this->assertArrayNotHasKey('shipping', $connection->settings['account_item_ids']);
        $this->assertArrayNotHasKey('other', $connection->settings['account_item_ids']);
        $this->assertSame([], app(FurimaDeckFreeeAccountingSyncService::class)->missingConfiguration($connection));
    }

    public function test_freee_setup_rejects_a_bank_account_as_csv_settlement(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $connection = AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $categories = [
            1 => ['資産', '流動資産', '現金・預金'],
            2 => ['損益', '収入金額'],
            3 => ['損益', '経費'],
            4 => ['損益', '売上原価'],
            5 => ['資産', '流動資産', '棚卸資産'],
        ];
        Http::fake(function ($request) use ($categories) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/1/companies') {
                return Http::response(['companies' => [['id' => 123, 'name' => '事業所']]]);
            }
            if ($path === '/api/1/account_items') {
                return Http::response(['account_items' => collect(range(1, 5))->map(fn (int $id): array => ['id' => $id, 'name' => '科目'.$id])->all()]);
            }
            if (preg_match('#^/api/1/account_items/(\d+)$#', $path, $matches) === 1) {
                $id = (int) $matches[1];

                return Http::response(['account_item' => ['id' => $id, 'default_tax_code' => (string) ($id * 10 + 1), 'categories' => $categories[$id]]]);
            }
            if ($path === '/api/1/taxes/companies/123') {
                return Http::response(['taxes' => collect(range(1, 5))->map(fn (int $id): array => ['code' => (string) ($id * 10 + 1), 'name' => 'tax_'.$id, 'name_ja' => '税区分'.$id])->all()]);
            }

            return Http::response([], 404);
        });

        try {
            app(FurimaDeckFreeeAccountingSetupService::class)->save($user, [
                'company_id' => 123,
                'account_item_ids' => ['settlement' => 1, 'sales' => 2, 'fees' => 3, 'cost' => 4, 'inventory' => 5],
            ]);
            $this->fail('売上の相手科目に銀行口座を保存してはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('売掛金（売上日・入金前）」用勘定科目の区分を確認できません', $exception->getMessage());
        }

        $this->assertNull($connection->fresh()->settings);
    }

    public function test_freee_company_read_refreshes_an_expired_access_token_once(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $tokenRequests = 0;
        $companyRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests, &$companyRequests) {
            if ($request->url() === 'https://accounts.secure.freee.co.jp/public_api/token') {
                $tokenRequests++;

                return Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 21600]);
            }
            if (str_contains($request->url(), '/api/1/companies')) {
                $companyRequests++;

                return $request->hasHeader('Authorization', 'Bearer old-access')
                    ? Http::response(['code' => 'expired_access_token'], 401)
                    : Http::response(['companies' => [['id' => 123, 'name' => 'テスト事業所']]]);
            }

            return Http::response([], 404);
        });

        $companies = app(FurimaDeckFreeeAccountingSetupService::class)->companies($user);

        $this->assertSame('テスト事業所', $companies['companies'][0]['name']);
        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $companyRequests);
        $this->assertSame('new-access', $user->accountingConnections()->where('provider', 'freee')->firstOrFail()->access_token);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer new-access')
            && str_contains($request->url(), '/api/1/companies'));
    }

    public function test_freee_permission_401_does_not_refresh_a_read_request(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $tokenRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests) {
            if (str_ends_with($request->url(), '/public_api/token')) {
                $tokenRequests++;

                return Http::response(['access_token' => 'unexpected'], 200);
            }

            return Http::response(['errors' => [['codes' => ['user_do_not_have_permission']]]], 401);
        });

        try {
            app(FurimaDeckFreeeAccountingSetupService::class)->companies($user);
            $this->fail('権限不足の401をトークン期限切れとして更新してはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('事業所で連携ユーザーに必要な権限がありません', $exception->getMessage());
        }

        $this->assertSame(0, $tokenRequests);
        $this->assertSame('connected', $user->accountingConnections()->where('provider', 'freee')->firstOrFail()->status);
    }

    public function test_freee_reauthorization_401_marks_only_the_rejected_connection_expired(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $connection = $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/public_api/token')) {
                return Http::response(['access_token' => 'unexpected'], 200);
            }

            return Http::response(['errors' => [['codes' => ['re_authorization_required']]]], 401);
        });

        try {
            app(FurimaDeckFreeeAccountingSetupService::class)->companies($user);
            $this->fail('再認可要求を読み取り成功として扱ってはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('再認可が必要', $exception->getMessage());
        }

        $this->assertSame('expired', $connection->fresh()->status);
        $this->assertSame('re_authorization_required', $connection->fresh()->last_error);
    }

    public function test_stale_freee_reauthorization_response_does_not_expire_a_newly_connected_token(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $connection = $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake(function ($request) use ($connection) {
            if (str_contains($request->url(), '/api/1/companies')) {
                $connection->update(['access_token' => 'replacement-access']);

                return Http::response(['errors' => [['codes' => ['re_authorization_required']]]], 401);
            }

            return Http::response([], 404);
        });

        try {
            app(FurimaDeckFreeeAccountingSetupService::class)->companies($user);
            $this->fail('再認可要求を読み取り成功として扱ってはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('再認可が必要', $exception->getMessage());
        }

        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('replacement-access', $connection->fresh()->access_token);
        $this->assertNull($connection->fresh()->last_error);
    }

    public function test_freee_setup_refuses_accounts_without_a_default_tax_code(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $connection = AccountingConnection::query()->create(['user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected']);
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => '事業所']]]),
            'https://api.freee.test/api/1/account_items?company_id=123' => Http::response(['account_items' => [
                ['id' => 1, 'name' => '普通預金', 'default_tax_code' => '21'], ['id' => 2, 'name' => '売上高', 'default_tax_code' => '11'], ['id' => 3, 'name' => '支払手数料'], ['id' => 4, 'name' => '仕入高', 'default_tax_code' => '41'], ['id' => 5, 'name' => '商品', 'default_tax_code' => '21'],
            ]]),
            'https://api.freee.test/api/1/account_items/1?company_id=123' => Http::response(['account_item' => ['id' => 1, 'default_tax_code' => '21', 'categories' => ['資産', '流動資産', '現金・預金']]]),
            'https://api.freee.test/api/1/account_items/2?company_id=123' => Http::response(['account_item' => ['id' => 2, 'default_tax_code' => '11', 'categories' => ['損益', '収入金額']]]),
            'https://api.freee.test/api/1/account_items/3?company_id=123' => Http::response(['account_item' => ['id' => 3, 'categories' => ['損益', '経費']]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [['code' => '11', 'name' => 'sales_with_tax_10', 'name_ja' => '課税売上10%'], ['code' => '21', 'name' => 'non_taxable', 'name_ja' => '対象外'], ['code' => '41', 'name' => 'purchase_with_tax_10', 'name_ja' => '課対仕入10%']]]),
        ]);

        $this->actingAs($user)->from(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->post(route('furimadeck-accounting.freee.setup.save'), ['company_id' => 123, 'account_item_ids' => ['settlement' => 1, 'sales' => 2, 'fees' => 3, 'cost' => 4, 'inventory' => 5]])
            ->assertRedirect(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->assertSessionHasErrors();

        $this->assertNull($connection->fresh()->settings);
    }

    public function test_freee_setup_refuses_the_same_tax_code_for_every_accounting_role(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $connection = AccountingConnection::query()->create(['user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected']);
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => '事業所']]]),
            'https://api.freee.test/api/1/account_items?company_id=123' => Http::response(['account_items' => collect(range(1, 5))->map(fn (int $id): array => ['id' => $id, 'name' => '科目'.$id, 'default_tax_code' => '129'])->all()]),
            'https://api.freee.test/api/1/account_items/1?company_id=123' => Http::response(['account_item' => ['id' => 1, 'default_tax_code' => '129', 'categories' => ['資産', '流動資産', '現金・預金']]]),
            'https://api.freee.test/api/1/account_items/2?company_id=123' => Http::response(['account_item' => ['id' => 2, 'default_tax_code' => '129', 'categories' => ['損益', '収入金額']]]),
            'https://api.freee.test/api/1/account_items/3?company_id=123' => Http::response(['account_item' => ['id' => 3, 'default_tax_code' => '129', 'categories' => ['損益', '経費']]]),
            'https://api.freee.test/api/1/account_items/4?company_id=123' => Http::response(['account_item' => ['id' => 4, 'default_tax_code' => '129', 'categories' => ['損益', '売上原価']]]),
            'https://api.freee.test/api/1/account_items/5?company_id=123' => Http::response(['account_item' => ['id' => 5, 'default_tax_code' => '129', 'categories' => ['資産', '流動資産', '棚卸資産']]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [['code' => '129', 'name' => 'sales_with_tax_10', 'name_ja' => '課税売上10%']]]),
        ]);

        $this->actingAs($user)->from(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->post(route('furimadeck-accounting.freee.setup.save'), ['company_id' => 123, 'account_item_ids' => ['settlement' => 1, 'sales' => 2, 'fees' => 3, 'cost' => 4, 'inventory' => 5]])
            ->assertRedirect(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->assertSessionHasErrors('accounting');

        $this->assertNull($connection->fresh()->settings);
        $connection->update(['settings' => ['company_id' => '123', 'account_item_ids' => ['settlement' => 1, 'sales' => 2, 'fees' => 3, 'cost' => 4, 'inventory' => 5], 'tax_codes' => array_fill_keys(['settlement', 'sales', 'fees', 'cost', 'inventory'], '129')]]);
        $this->assertContains('tax_codes.distinct_roles', app(FurimaDeckFreeeAccountingSyncService::class)->missingConfiguration($connection->fresh()));
    }

    public function test_freee_setup_refuses_reusing_an_account_for_multiple_roles(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $connection = AccountingConnection::query()->create(['user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token', 'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected']);
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => '事業所']]]),
            'https://api.freee.test/api/1/account_items?company_id=123' => Http::response(['account_items' => collect(range(1, 5))->map(fn (int $id): array => ['id' => $id, 'name' => '科目'.$id])->all()]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [['code' => '21', 'name' => '対象外']]]),
        ]);

        $this->actingAs($user)->from(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->post(route('furimadeck-accounting.freee.setup.save'), [
                'company_id' => 123,
                'account_item_ids' => ['settlement' => 1, 'sales' => 1, 'fees' => 3, 'cost' => 4, 'inventory' => 5],
            ])
            ->assertRedirect(route('furimadeck-accounting.freee.setup', ['company_id' => 123]))
            ->assertSessionHasErrors('accounting');

        $this->assertNull($connection->fresh()->settings);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/1/account_items/'));

        $connection->update(['settings' => [
            'company_id' => '123',
            'account_item_ids' => ['settlement' => 1, 'sales' => 1, 'fees' => 3, 'cost' => 4, 'inventory' => 5],
            'tax_codes' => ['settlement' => '21', 'sales' => '11', 'fees' => '31', 'cost' => '41', 'inventory' => '21'],
        ]]);
        $this->assertContains('account_item_ids.distinct_roles', app(FurimaDeckFreeeAccountingSyncService::class)->missingConfiguration($connection->fresh()));
    }
}
