<?php

namespace Tests\Feature;

use App\Jobs\SendFreeeAccountingEntryJob;
use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\AccountingSyncBatch;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckAccountingProviderScopeService;
use App\Services\FurimaDeckAccountingStaleSendRecoveryService;
use App\Services\FurimaDeckAccountingVendorCsvService;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FurimaDeckFreeeAccountingSyncTest extends TestCase
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
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        config()->set('furimadeck.accounting.freee_sync', [
            'api_base' => 'https://api.freee.test',
            'company_id' => '123',
            'tax_codes' => ['settlement' => '21', 'sales' => '22', 'fees' => '23', 'shipping' => '26', 'cost' => '24', 'inventory' => '25'],
            'account_item_ids' => [
                'settlement' => '1',
                'sales' => '2',
                'fees' => '3',
                'shipping' => '6',
                'cost' => '4',
                'inventory' => '5',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_csv_pending_entry_cannot_be_sent_to_freee(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token',
            'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => ['company_id' => '123'],
        ]);
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'csv_pending']);
        Http::fake();

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry->fresh());
            $this->fail('CSV出力済み明細を送信できてしまいました。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('送信済み、または処理中', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_failed_freee_sync_with_prior_journal_evidence_cannot_be_resent(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token',
            'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => ['company_id' => '123'],
        ]);
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'failed',
            'external_transaction_id' => 'freee-existing-journal',
        ]);
        Http::fake();

        foreach ([
            ['external_transaction_id' => 'freee-existing-journal', 'synced_at' => null],
            ['external_transaction_id' => null, 'synced_at' => now()],
        ] as $journalEvidence) {
            $sync->update($journalEvidence);

            try {
                app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry->fresh());
                $this->fail('外部仕訳の記録がある失敗状態を再送できてしまいました。');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('外部仕訳IDまたは同期済み記録', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_confirmed_purchase_entry_is_sent_as_balanced_inventory_journal(): void
    {
        [$user, $entry] = $this->entryFixture(0, false);
        $product = Product::query()->findOrFail($entry->sale->product_id);
        $product->update(['purchase_payment_sources' => ['freee' => '1']]);
        $purchase = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product->fresh());
        $purchase->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $connection = AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => [
                'purchase_payment_accounts' => [
                    '1' => ['account_id' => '1', 'name' => '普通預金', 'account_category' => '現金・預金'],
                ],
            ],
        ]);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => [
                ['id' => 1, 'available' => true, 'default_tax_code' => 21, 'categories' => ['資産', '流動資産']],
                ['id' => 5, 'available' => true, 'default_tax_code' => 25, 'categories' => ['資産', '流動資産']],
            ]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [
                ['code' => 25, 'name' => 'purchase_with_tax_10', 'available' => true],
            ]]),
            'https://api.freee.test/api/1/manual_journals' => Http::response(['manual_journal' => ['id' => 789]], 201),
        ]);

        $externalId = app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $purchase);

        $this->assertSame('789', $externalId);
        $this->assertSame('synced', $purchase->fresh()->providerSync('freee')->status);
        $this->assertSame('789', $purchase->fresh()->providerSync('freee')->external_transaction_id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && $request->data()['issue_date'] === '2026-01-01'
            && count($request->data()['details']) === 2
            && collect($request->data()['details'])->where('entry_side', 'debit')->sum('amount') === 1000
            && collect($request->data()['details'])->where('entry_side', 'credit')->sum('amount') === 1000
            && collect($request->data()['details'])->contains(fn ($line): bool => $line['entry_side'] === 'debit'
                && $line['account_item_id'] === 5 && $line['tax_code'] === 25)
            && collect($request->data()['details'])->contains(fn ($line): bool => $line['entry_side'] === 'credit'
                && $line['account_item_id'] === 1 && $line['tax_code'] === 21));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/1/taxes/companies/123'));
    }

    public function test_antique_dealer_purchase_identity_is_sent_only_in_encrypted_payload(): void
    {
        [$user, $saleEntry] = $this->entryFixture(0, false);
        $product = Product::query()->findOrFail($saleEntry->sale->product_id);
        $product->update([
            'purchase_payment_sources' => ['freee' => '1'],
            'purchase_evidence' => [
                'purchase_unit_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'unqualified',
                    'date_matches_entry_confirmed' => true,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => '第123456789012号',
                'counterparty_name' => '機密仕入先氏名',
                'counterparty_address' => '東京都千代田区1-2-3',
                'confirmed_at' => now()->toIso8601String(),
                'confirmations' => [
                    'dealer_license' => true,
                    'qualifying_used_goods' => true,
                    'resale_inventory' => true,
                    'supplier_unqualified' => true,
                    'not_specific_metal_scrap' => true,
                ],
            ],
        ]);
        $purchase = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product->fresh());
        $purchase->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => [
                'purchase_payment_accounts' => [
                    '1' => ['account_id' => '1', 'name' => '普通預金', 'account_category' => '現金・預金'],
                ],
            ],
        ]);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => [
                ['id' => 1, 'available' => true, 'default_tax_code' => 21, 'categories' => ['資産', '流動資産']],
                ['id' => 5, 'available' => true, 'default_tax_code' => 25, 'categories' => ['資産', '流動資産']],
            ]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [
                ['code' => 25, 'name' => 'purchase_with_tax_10', 'available' => true],
            ]]),
            'https://api.freee.test/api/1/manual_journals' => Http::response(['manual_journal' => ['id' => 790]], 201),
        ]);

        app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $purchase);

        $sync = $purchase->fresh()->providerSync('freee');
        $this->assertSame('[暗号化された仕入摘要]', $sync->request_payload['details'][0]['description']);
        $this->assertStringContainsString('機密仕入先氏名', $sync->accountingPayload()['details'][0]['description']);
        $this->assertStringContainsString('東京都千代田区1-2-3', $sync->accountingPayload()['details'][0]['description']);
        $encryptedPayload = DB::connection('furimadeck')->table('accounting_entry_syncs')
            ->where('id', $sync->id)
            ->value('sensitive_request_payload');
        $this->assertIsString($encryptedPayload);
        $this->assertStringNotContainsString('機密仕入先氏名', $encryptedPayload);
        $this->assertStringNotContainsString('東京都千代田区1-2-3', $encryptedPayload);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && str_contains($request->data()['details'][0]['description'], '機密仕入先氏名')
            && str_contains($request->data()['details'][0]['description'], '東京都千代田区1-2-3'));

    }

    public function test_antique_dealer_purchase_with_conflicting_evidence_stops_before_freee_api_access(): void
    {
        Http::fake();
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'expense_breakdown' => [
                'evidence' => [
                    'purchase_unit_cost' => ['tax_category' => 'standard_10', 'invoice_issuer' => 'qualified'],
                ],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => '許可番号',
                'counterparty_name' => '仕入先名',
                'counterparty_address' => '住所',
                'confirmed_at' => '2026-10-15T00:00:00+09:00',
                'confirmations' => array_fill_keys(['dealer_license', 'qualifying_used_goods', 'resale_inventory', 'supplier_unqualified', 'not_specific_metal_scrap'], true),
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('証憑区分が一致しない');
        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->validatedPurchaseTaxCodesForDate(
                new AccountingConnection,
                '123',
                [],
                $entry,
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_confirmed_entry_is_sent_once_and_external_id_is_saved(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => $this->datedAccountItemsResponse(),
            'https://api.freee.test/api/1/manual_journals' => Http::response([
                'manual_journal' => ['id' => 456],
            ], 201),
        ]);

        $externalId = app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);

        $entry->refresh();
        $this->assertSame('456', $externalId);
        $this->assertSame('synced', $entry->sync_status);
        $this->assertSame('456', $entry->external_transaction_id);
        $this->assertNotNull($entry->synced_at);
        $this->assertSame('2026-01-10', $entry->providerSync('freee')->request_payload['issue_date']);
        $this->assertNull($entry->providerSync('freee')->verified_at);
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
            'status' => 'synced',
            'external_transaction_id' => '456',
        ], 'furimadeck');

        $this->expectExceptionMessage('すでに送信済み');
        app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.freee.test/api/1/manual_journals'
                && count($payload['details']) === 8
                && collect($payload['details'])->sum('amount') === 24000
                && collect($payload['details'])->filter(fn ($detail): bool => str_ends_with($detail['description'], '販売手数料'))->count() === 2
                && collect($payload['details'])->filter(fn ($detail): bool => str_ends_with($detail['description'], '送料'))->count() === 2
                && collect($payload['details'])->where('account_item_id', 1)->every(fn ($detail): bool => $detail['tax_code'] === 21)
                && collect($payload['details'])->where('account_item_id', 2)->every(fn ($detail): bool => $detail['tax_code'] === 22)
                && collect($payload['details'])->where('account_item_id', 3)->every(fn ($detail): bool => $detail['tax_code'] === 23)
                && collect($payload['details'])->where('account_item_id', 6)->every(fn ($detail): bool => $detail['tax_code'] === 26)
                && collect($payload['details'])->where('account_item_id', 4)->every(fn ($detail): bool => $detail['tax_code'] === 24)
                && collect($payload['details'])->where('account_item_id', 5)->every(fn ($detail): bool => $detail['tax_code'] === 25);
        });
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/1/account_items?')
            && str_contains($request->url(), 'company_id=123')
            && str_contains($request->url(), 'base_date=2026-01-10'));
    }

    public function test_expired_freee_access_token_refreshes_and_retries_journal_once(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.oauth.freee', [
            'client_id' => 'freee-client',
            'client_secret' => 'freee-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/freee',
            'token_url' => 'https://accounts.secure.freee.co.jp/public_api/token',
        ]);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $tokenRequests = 0;
        $journalRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests, &$journalRequests) {
            if ($request->url() === 'https://accounts.secure.freee.co.jp/public_api/token') {
                $tokenRequests++;

                return Http::response([
                    'access_token' => 'new-access',
                    'refresh_token' => 'new-refresh',
                    'expires_in' => 21600,
                ]);
            }
            if (str_contains($request->url(), '/api/1/account_items')) {
                return $this->datedAccountItemsResponse();
            }
            if ($request->url() === 'https://api.freee.test/api/1/manual_journals') {
                $journalRequests++;

                return $journalRequests === 1
                    ? Http::response(['code' => 'expired_access_token'], 401)
                    : Http::response(['manual_journal' => ['id' => 457]], 201);
            }

            return Http::response([], 404);
        });

        $externalId = app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);

        $this->assertSame('457', $externalId);
        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $journalRequests);
        $this->assertSame('new-access', $user->accountingConnections()->where('provider', 'freee')->firstOrFail()->access_token);
        $this->assertSame('synced', $entry->fresh()->providerSync('freee')->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && $request->hasHeader('Authorization', 'Bearer old-access'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && $request->hasHeader('Authorization', 'Bearer new-access'));
    }

    public function test_expired_token_during_freee_tax_preflight_refreshes_once_and_posts_with_rotated_token(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.oauth.freee', [
            'client_id' => 'freee-client',
            'client_secret' => 'freee-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/freee',
            'token_url' => 'https://accounts.secure.freee.co.jp/public_api/token',
        ]);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $tokenRequests = 0;
        $accountRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests, &$accountRequests) {
            if ($request->url() === 'https://accounts.secure.freee.co.jp/public_api/token') {
                $tokenRequests++;

                return Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 21600]);
            }
            if (str_contains($request->url(), '/api/1/account_items')) {
                $accountRequests++;

                return $request->hasHeader('Authorization', 'Bearer old-access')
                    ? Http::response(['code' => 'expired_access_token'], 401)
                    : $this->datedAccountItemsResponse();
            }
            if ($request->url() === 'https://api.freee.test/api/1/manual_journals') {
                return Http::response(['manual_journal' => ['id' => 458]], 201);
            }

            return Http::response([], 404);
        });

        $externalId = app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);

        $this->assertSame('458', $externalId);
        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $accountRequests);
        $this->assertSame('synced', $entry->fresh()->providerSync('freee')->status);
        $this->assertSame('new-access', $user->accountingConnections()->where('provider', 'freee')->firstOrFail()->access_token);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && $request->hasHeader('Authorization', 'Bearer new-access'));
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && $request->hasHeader('Authorization', 'Bearer old-access'));
    }

    public function test_freee_permission_401_does_not_refresh_or_retry_journal(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $tokenRequests = 0;
        $journalRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests, &$journalRequests) {
            if (str_contains($request->url(), '/api/1/account_items')) {
                return $this->datedAccountItemsResponse();
            }
            if ($request->url() === 'https://api.freee.test/api/1/manual_journals') {
                $journalRequests++;

                return Http::response([
                    'status_code' => 401,
                    'errors' => [['codes' => ['user_do_not_have_permission']]],
                ], 401);
            }
            if (str_ends_with($request->url(), '/public_api/token')) {
                $tokenRequests++;
            }

            return Http::response([], 404);
        });

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('A permission failure must not be mistaken for an expired access token.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('事業所で連携ユーザーに必要な権限がありません', $exception->getMessage());
        }

        $this->assertSame(0, $tokenRequests);
        $this->assertSame(1, $journalRequests);
        $this->assertSame('failed', $entry->fresh()->providerSync('freee')->status);
        $this->assertSame('connected', $user->accountingConnections()->where('provider', 'freee')->firstOrFail()->status);
    }

    public function test_freee_batch_queues_confirmed_unsent_entries_once(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Queue::fake();

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('freeeへ一括送信（1件）');

        $response = $this->actingAs($user)->post(route('furimadeck-accounting.send.freee.batch'), ['year' => 2026]);
        $batch = AccountingSyncBatch::query()->where('provider', 'freee')->firstOrFail();

        $response->assertRedirect(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertSessionHas('success');
        $this->assertSame(1, $batch->total_count);
        $this->assertSame('queued', $entry->fresh()->providerSyncStatus('freee'));
        $this->actingAs($user)
            ->post(route('furimadeck-accounting.send.freee.batch'), ['year' => 2026])
            ->assertSessionHas('error');
        $this->assertSame(1, AccountingSyncBatch::query()->where('provider', 'freee')->count());
        Queue::assertPushed(
            SendFreeeAccountingEntryJob::class,
            fn (SendFreeeAccountingEntryJob $job): bool => $job->connection === 'database' && $job->queue === 'default',
        );
        Queue::assertPushed(SendFreeeAccountingEntryJob::class, 1);
    }

    public function test_freee_batch_job_sends_and_completes_the_batch_item(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'freee', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'queued', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'queued',
        ]);
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'queued']);
        $entry->update(['sync_status' => 'queued']);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => $this->datedAccountItemsResponse(),
            'https://api.freee.test/api/1/manual_journals' => Http::response(['manual_journal' => ['id' => 789]], 201),
        ]);

        (new SendFreeeAccountingEntryJob($batch->id, $item->id))
            ->handle(app(FurimaDeckFreeeAccountingSyncService::class));

        $this->assertSame('synced', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertSame('789', $entry->fresh()->providerSync('freee')->external_transaction_id);
        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertSame('completed', $batch->fresh()->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals');
    }

    public function test_freee_batch_failure_callback_preserves_a_recorded_external_success(): void
    {
        [, $entry] = $this->entryFixture();
        $batch = $entry->user->accountingSyncBatches()->create([
            'provider' => 'freee', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'running', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'running',
        ]);
        $entry->providerSyncs()->create([
            'provider' => 'freee', 'status' => 'synced', 'external_transaction_id' => 'freee-known-id',
        ]);
        $entry->update(['sync_status' => 'synced']);

        (new SendFreeeAccountingEntryJob($batch->id, $item->id))->failed(new \RuntimeException('worker ended'));

        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_freee_batch_failure_before_send_is_recorded_as_not_sent_and_resendable(): void
    {
        [, $entry] = $this->entryFixture();
        $batch = $entry->user->accountingSyncBatches()->create([
            'provider' => 'freee', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'running', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'running',
        ]);
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'queued']);
        $entry->update(['sync_status' => 'queued']);
        Http::fake();

        $job = new SendFreeeAccountingEntryJob($batch->id, $item->id);
        $job->failed(new \RuntimeException('worker ended before send'));
        $job->failed(new \RuntimeException('duplicate failure callback'));

        $this->assertSame('failed', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertSame('queue_interrupted_before_send', $entry->fresh()->providerSync('freee')->last_error);
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertStringContainsString('登録は行われていません', $item->fresh()->diagnostic);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->processed_count);
        Http::assertNothingSent();
    }

    public function test_freee_batch_failure_after_send_started_requires_external_reconciliation(): void
    {
        [, $entry] = $this->entryFixture();
        $batch = $entry->user->accountingSyncBatches()->create([
            'provider' => 'freee', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'running', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'running',
        ]);
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'sending', 'attempted_at' => now()]);
        $entry->update(['sync_status' => 'sending']);
        Http::fake();

        (new SendFreeeAccountingEntryJob($batch->id, $item->id))->failed(new \RuntimeException('worker ended after request start'));

        $this->assertSame('sync_unknown', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertSame('uncertain', $item->fresh()->status);
        $this->assertStringContainsString('結果を確認できない', $item->fresh()->diagnostic);
        $this->assertSame(1, $batch->fresh()->processed_count);
        Http::assertNothingSent();
    }

    public function test_freee_batch_job_marks_ambiguous_network_failure_uncertain(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token',
            'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => ['company_id' => '123'],
        ]);
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'freee', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'queued', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'queued',
        ]);
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'queued']);
        $entry->update(['sync_status' => 'queued']);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => $this->datedAccountItemsResponse(),
            'https://api.freee.test/api/1/manual_journals' => fn () => throw new ConnectionException('connection dropped'),
        ]);

        (new SendFreeeAccountingEntryJob($batch->id, $item->id))
            ->handle(app(FurimaDeckFreeeAccountingSyncService::class));

        $this->assertSame('sync_unknown', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertSame('uncertain', $item->fresh()->status);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_SALE)->where('sync_status', 'not_synced')->count());
    }

    public function test_long_management_id_is_mapped_to_a_unique_freee_reference_within_the_api_limit(): void
    {
        [$user, $entry] = $this->entryFixture();
        $managementId = 'FD-ACCT-TEST-20261012-API-1';
        $entry->sale()->update(['internal_sku_snapshot' => $managementId]);
        $entry->update(['management_id' => $managementId]);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => $this->datedAccountItemsResponse(),
            'https://api.freee.test/api/1/manual_journals' => Http::response([
                'manual_journal' => ['id' => 789],
            ], 201),
        ]);

        app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);

        $expectedReference = 'FD-'.strtoupper(substr(hash('sha256', $managementId), 0, 17));
        $this->assertSame(20, mb_strlen($expectedReference, 'UTF-8'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && $request->data()['ref_number'] === $expectedReference
            && mb_strlen($request->data()['ref_number'], 'UTF-8') <= 20);
    }

    public function test_shipping_is_not_sent_as_a_fee_when_its_account_is_missing(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        config()->set('furimadeck.accounting.freee_sync.account_item_ids.shipping', '');
        Http::fake();

        $this->expectExceptionMessage('shipping');
        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_other_expense_uses_its_own_account_in_the_journal(): void
    {
        [$user, $entry] = $this->entryFixture(200);
        $detail = [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'qualified',
            'date_matches_entry_confirmed' => true,
            'reviewed_at' => now()->toIso8601String(),
            'payment_sources' => ['freee' => '8'],
        ];
        $entry->sale->update(['accounting_expense_details' => ['packing_cost' => $detail]]);
        $breakdown = (array) $entry->expense_breakdown;
        $breakdown['sale_expenses']['packing_cost'] = array_intersect_key($detail, array_flip(['document_type', 'tax_category', 'invoice_issuer', 'date_matches_entry_confirmed', 'reviewed_at']));
        $entry->update(['expense_breakdown' => $breakdown]);
        AccountingConnection::query()->create([
            'user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token',
            'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => ['purchase_payment_accounts' => ['8' => ['account_id' => '8', 'name' => '事業用口座']]],
        ]);
        config()->set('furimadeck.accounting.freee_sync.account_item_ids.other', '7');
        config()->set('furimadeck.accounting.freee_sync.tax_codes.other', '27');
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => [
                ['id' => 1, 'default_tax_code' => 21], ['id' => 2, 'default_tax_code' => 22],
                ['id' => 3, 'default_tax_code' => 23], ['id' => 4, 'default_tax_code' => 24],
                ['id' => 5, 'default_tax_code' => 25], ['id' => 6, 'default_tax_code' => 26],
                ['id' => 7, 'default_tax_code' => 27],
                ['id' => 8, 'default_tax_code' => 28, 'categories' => ['資産']],
            ]]),
            'https://api.freee.test/api/1/taxes/companies/123*' => Http::response(['taxes' => [
                ['code' => '27', 'name' => 'purchase_with_tax_10', 'name_ja' => '課対仕入10%'],
                ['code' => '28', 'name' => 'non_taxable', 'name_ja' => '対象外'],
            ]]),
            'https://api.freee.test/api/1/manual_journals' => Http::response(['manual_journal' => ['id' => 789]], 201),
        ]);

        app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && collect($request->data()['details'])->contains(fn ($detail): bool => $detail['entry_side'] === 'debit'
                && $detail['account_item_id'] === 7 && $detail['tax_code'] === 27 && $detail['amount'] === 200)
            && collect($request->data()['details'])->contains(fn ($detail): bool => $detail['entry_side'] === 'credit'
                && $detail['account_item_id'] === 8 && $detail['tax_code'] === 28 && $detail['amount'] === 200));
    }

    public function test_freee_csv_scope_rejects_itemized_sale_expense(): void
    {
        [$user, $entry] = $this->entryFixture(200, true, 0);
        $entry->update(['selling_fee' => 0]);
        $detail = [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'qualified',
            'date_matches_entry_confirmed' => true,
            'reviewed_at' => now()->toIso8601String(),
            'payment_sources' => ['freee' => '8'],
        ];
        $entry->sale->update(['accounting_expense_details' => ['packing_cost' => $detail]]);
        $breakdown = (array) $entry->expense_breakdown;
        $breakdown['sale_expenses']['packing_cost'] = array_intersect_key($detail, array_flip(['document_type', 'tax_category', 'invoice_issuer', 'date_matches_entry_confirmed', 'reviewed_at']));
        $entry->update(['expense_breakdown' => $breakdown]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('別途支払った販売費用を含む明細');
        app(FurimaDeckAccountingVendorCsvService::class)->assertProviderCsvScope(collect([$entry]), 'freee');
    }

    public function test_legacy_confirm_without_per_entry_review_cannot_send_to_freee(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['confirmed_at' => null]);
        Http::fake();

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry->fresh());
            $this->fail('Legacy bulk confirmation must not authorize an external journal.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Confirm済み', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_direct_journal_send_stays_disabled_when_legacy_flag_is_true_but_new_gate_is_missing(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        Http::fake();

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('既定では外部に仕訳を送ってはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('1.0', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_stale_send_recovery_does_not_change_legacy_api_states_when_new_gate_is_missing(): void
    {
        [, $entry] = $this->entryFixture();
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'sending',
            'attempted_at' => now()->subSeconds(600),
        ]);
        $entry->update(['sync_status' => 'queued']);
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);

        $counts = app(FurimaDeckAccountingStaleSendRecoveryService::class)->recover(300);

        $this->assertSame(['uncertain' => 0, 'not_sent' => 0, 'succeeded' => 0, 'deferred' => 0], $counts);
        $this->assertSame('sending', $sync->fresh()->status);
        $this->assertSame('queued', $entry->fresh()->sync_status);
    }

    public function test_deleted_freee_test_voucher_stays_blocked_from_generic_resolution(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'sync_unknown',
            'external_transaction_id' => 'deleted-test-voucher',
            'last_error' => AccountingEntrySync::EXTERNAL_VOUCHER_DELETED_TAX_REVIEW,
        ]);
        $entry->refreshLegacySyncStatus();
        $this->assertSame('not_synced', $entry->providerSyncStatus('money_forward'));

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('削除済み・会計確認待ち')
            ->assertDontSee('freeeの仕訳ID')
            ->assertDontSee('freeeに該当仕訳がないことを確認');

        foreach ([
            ['action' => 'retry', 'verified_absent' => '1'],
            ['action' => 'mark_synced', 'external_transaction_id' => 'another-voucher'],
        ] as $payload) {
            $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), $payload)
                ->assertSessionHas('error');
        }

        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
            'status' => 'sync_unknown',
            'external_transaction_id' => 'deleted-test-voucher',
            'last_error' => AccountingEntrySync::EXTERNAL_VOUCHER_DELETED_TAX_REVIEW,
        ], 'furimadeck');
    }

    public function test_legacy_single_tax_code_does_not_satisfy_per_account_tax_configuration(): void
    {
        config()->set('furimadeck.accounting.freee_sync', [
            'api_base' => 'https://api.freee.test',
            'company_id' => '123',
            'account_item_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
        ]);
        $legacyConnection = new AccountingConnection(['settings' => ['tax_code' => '129']]);

        $missing = app(FurimaDeckFreeeAccountingSyncService::class)->missingConfiguration($legacyConnection);

        $this->assertSame([
            'tax_codes.settlement',
            'tax_codes.sales',
            'tax_codes.fees',
            'tax_codes.cost',
            'tax_codes.inventory',
        ], $missing);
    }

    public function test_identical_tax_codes_are_rejected_before_any_freee_request(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => ['tax_codes' => array_fill_keys(['settlement', 'sales', 'fees', 'cost', 'inventory'], '129'), 'tax_code' => '21'],
        ]);
        Http::fake();

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('全役割が同じ税区分の仕訳は送信できてはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('設定が不足', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
    }

    public function test_legacy_synced_entry_is_blocked_until_its_provider_is_checked(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['sync_status' => 'synced', 'external_transaction_id' => '456']);
        Http::fake();

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('送信済みデータは拒否される必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('確認', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_api_failure_is_recorded_without_marking_the_entry_synced(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => $this->datedAccountItemsResponse(),
            'https://api.freee.test/api/1/manual_journals' => Http::response(['error' => 'rejected'], 422),
        ]);

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('APIエラーは例外になる必要があります。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('送信に失敗', $exception->getMessage());
        }

        $this->assertSame('not_synced', $entry->fresh()->sync_status);
        $this->assertSame('http_422', $connection->fresh()->last_error);
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
            'status' => 'failed',
        ], 'furimadeck');
    }

    public function test_ambiguous_http_responses_require_verification_before_retry(): void
    {
        foreach ([408, 425, 429] as $status) {
            [$user, $entry] = $this->entryFixture();
            AccountingConnection::query()->create([
                'user_id' => $user->id,
                'provider' => 'freee',
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_at' => now()->addHour(),
                'status' => 'connected',
            ]);
            Http::fake([
                'https://api.freee.test/api/1/account_items*' => $this->datedAccountItemsResponse(),
                'https://api.freee.test/api/1/manual_journals' => Http::response(['error' => 'transient'], $status),
            ]);

            try {
                app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
                $this->fail('曖昧なHTTP応答は二重登録防止のため要照合になる必要があります。');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('応答が不確定', $exception->getMessage());
            }

            $sync = $entry->providerSync('freee')->fresh();
            $this->assertSame('sync_unknown', $sync->status);
            $this->assertSame(1, $sync->attempt_count);
        }
    }

    public function test_connection_failure_is_recorded_without_marking_the_entry_synced(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/1/account_items?')) {
                return $this->datedAccountItemsResponse();
            }

            throw new ConnectionException('offline');
        });

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('通信例外は送信失敗として扱う必要があります。');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(\RuntimeException::class, $exception);
            $this->assertStringContainsString('確認できません', $exception->getMessage());
        }

        $this->assertSame('sync_unknown', $entry->fresh()->sync_status);
        $this->assertSame('network_error', $connection->fresh()->last_error);
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
            'status' => 'sync_unknown',
        ], 'furimadeck');

        Http::fake();
        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('送信結果不明の仕訳は自動再送できてはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('再送できません', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_another_user_cannot_send_the_entry(): void
    {
        [$owner, $entry] = $this->entryFixture();
        $otherUser = User::factory()->create();
        AccountingConnection::query()->create([
            'user_id' => $owner->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake();

        $this->expectException(HttpException::class);
        app(FurimaDeckFreeeAccountingSyncService::class)->send($otherUser, $entry);
        Http::assertNothingSent();
    }

    public function test_missing_dated_tax_code_fails_before_a_journal_is_created(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => [
                ['id' => 1, 'default_tax_code' => 21],
                ['id' => 2, 'default_tax_code' => 22],
                ['id' => 3, 'default_tax_code' => null],
                ['id' => 4, 'default_tax_code' => 24],
                ['id' => 5, 'default_tax_code' => 25],
            ]]),
        ]);

        $this->expectExceptionMessage('仕訳は送信していません');
        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
        } finally {
            Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/1/manual_journals'));
            $this->assertSame('not_synced', $entry->fresh()->sync_status);
        }
    }

    public function test_changed_dated_tax_code_requires_review_before_a_journal_is_created(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => [
                ['id' => 1, 'default_tax_code' => 21],
                ['id' => 2, 'default_tax_code' => 22],
                ['id' => 3, 'default_tax_code' => 29],
                ['id' => 4, 'default_tax_code' => 24],
                ['id' => 5, 'default_tax_code' => 25],
            ]]),
        ]);

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('確認済み設定と違う税区分を自動採用してはいけません。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('確認済み設定と異なります', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/1/manual_journals'));
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
        $this->assertDatabaseMissing('accounting_entry_syncs', ['accounting_entry_id' => $entry->id, 'provider' => 'freee'], 'furimadeck');
    }

    public function test_dated_tax_lookup_failure_does_not_mark_the_journal_as_uncertain(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake(['https://api.freee.test/api/1/account_items*' => Http::response([], 503)]);

        $this->expectExceptionMessage('仕訳は送信していません');
        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
        } finally {
            Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/1/manual_journals'));
            $this->assertSame('not_synced', $entry->fresh()->sync_status);
            $this->assertDatabaseMissing('accounting_entry_syncs', ['accounting_entry_id' => $entry->id, 'provider' => 'freee'], 'furimadeck');
        }
    }

    public function test_returned_sale_entry_is_not_sent(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->sale()->update(['status' => 'returned']);
        Http::fake();

        $this->expectExceptionMessage('売上記録と会計データが一致しません');
        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_uncertain_freee_send_requires_matching_external_journal_before_resolution(): void
    {
        [$user, $entry] = $this->entryFixture();
        AccountingConnection::query()->create([
            'user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'access-token',
            'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => ['company_id' => '12'],
        ]);
        $payload = [
            'company_id' => 12, 'issue_date' => '2026-01-10', 'adjustment' => false,
            'ref_number' => $entry->management_id,
            'details' => [
                ['entry_side' => 'debit', 'account_item_id' => 1, 'tax_code' => 2, 'amount' => 100, 'description' => 'sale'],
                ['entry_side' => 'credit', 'account_item_id' => 3, 'tax_code' => 4, 'amount' => 100, 'description' => 'sale'],
            ],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee', 'status' => 'sync_unknown', 'request_payload' => $payload,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)->fingerprint('freee', '12'),
        ]);
        $mismatch = $payload;
        $mismatch['details'][0]['amount'] = 101;
        Http::fakeSequence()->push(['manual_journal' => ['id' => 123, ...$mismatch]])
            ->push(['manual_journal' => ['id' => 123, ...$payload]]);

        $route = route('furimadeck-accounting.sync.resolve', [$entry, 'freee']);
        $request = ['action' => 'mark_synced', 'external_transaction_id' => '123'];
        $this->actingAs($user)->post($route, $request)->assertSessionHas('error');
        $this->assertSame('sync_unknown', $sync->fresh()->status);

        $this->actingAs($user)->post($route, $request)->assertSessionHas('success');
        $this->assertSame('synced', $sync->fresh()->status);
        $this->assertNotNull($sync->fresh()->verified_at);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/1/manual_journals/123?company_id=12'));
    }

    public function test_stale_freee_send_is_recovered_as_unknown_but_fresh_send_is_left_alone(): void
    {
        [, $staleEntry] = $this->entryFixture();
        [, $freshEntry] = $this->entryFixture();
        $staleEntry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'sending',
            'attempted_at' => now()->subSeconds(600),
        ]);
        $staleEntry->update(['sync_status' => 'queued']);
        $freshEntry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'sending',
            'attempted_at' => now(),
        ]);
        $freshEntry->update(['sync_status' => 'queued']);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);
        config()->set('queue.connections.database.retry_after', 90);

        $this->artisan('furimadeck:accounting-recover-stale-sends')->assertSuccessful();

        $this->assertSame('sync_unknown', $staleEntry->providerSync('freee')->fresh()->status);
        $this->assertStringContainsString('登録結果を確認できません', $staleEntry->providerSync('freee')->fresh()->last_error_label);
        $this->assertSame('sync_unknown', $staleEntry->fresh()->sync_status);
        $this->assertSame('sending', $freshEntry->providerSync('freee')->fresh()->status);
    }

    public function test_stale_recovery_uses_the_accounting_job_queue_connection_retry_window(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.retry_after', 120);
        config()->set('queue.connections.database.retry_after', 600);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);

        $this->artisan('furimadeck:accounting-recover-stale-sends')
            ->expectsOutput('送信状態の復旧時間は、キューの再試行時間とジョブのタイムアウトより長く設定してください。')
            ->assertFailed();
    }

    public function test_stale_recovery_ignores_default_queue_when_accounting_jobs_are_pinned(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.retry_after', 600);
        config()->set('queue.connections.database.retry_after', 120);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);

        $this->artisan('furimadeck:accounting-recover-stale-sends')->assertSuccessful();
    }

    public function test_stale_freee_batch_send_is_marked_uncertain_without_retry(): void
    {
        [, $entry] = $this->entryFixture();
        $batch = $entry->user->accountingSyncBatches()->create([
            'provider' => 'freee', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'running', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'running',
        ]);
        $entry->providerSyncs()->create([
            'provider' => 'freee', 'status' => 'sending', 'attempted_at' => now()->subSeconds(600),
        ]);
        $entry->update(['sync_status' => 'queued']);
        DB::connection('furimadeck')->table('accounting_sync_batch_items')
            ->where('id', $item->id)
            ->update(['updated_at' => now()->subSeconds(600)]);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);
        config()->set('queue.connections.database.retry_after', 90);

        $this->artisan('furimadeck:accounting-recover-stale-sends')->assertSuccessful();

        $this->assertSame('sync_unknown', $entry->fresh()->providerSync('freee')->status);
        $this->assertSame('uncertain', $item->fresh()->status);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
    }

    public function test_unconfirmed_entry_is_not_sent(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'ready']);
        $this->expectExceptionMessage('Confirm済み');
        app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
        Http::assertNothingSent();
    }

    public function test_sale_with_unreconciled_purchase_is_blocked_before_freee_network_access(): void
    {
        [$user, $entry] = $this->entryFixture(0, false);
        Http::fake();

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('仕入未照合の商品をfreeeへ送信できてしまいました。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('この商品の仕入会計明細が見つかりません', $exception->getMessage());
            $this->assertStringContainsString('この売上CSVは保留です', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseMissing('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
        ], 'furimadeck');
    }

    public function test_freee_purchase_external_id_without_content_verification_does_not_unblock_sale(): void
    {
        [$user, $entry] = $this->entryFixture();
        $purchase = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        $purchase->providerSyncs()->where('provider', 'freee')->update(['verified_at' => null]);
        Http::fake();

        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
            $this->fail('外部仕入IDだけで内容未照合なのにfreee売上を送信できてしまいました。');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('freee側で未照合', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /** @return array{0: User, 1: AccountingEntry} */
    private function entryFixture(int $packingCost = 0, bool $purchaseReconciled = true, int $shippingFee = 500): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create([
            'internal_sku' => 'FREEE-'.uniqid(),
            'product_name' => 'freee送信商品',
            'condition' => 'used',
            'purchase_date' => '2026-01-01',
            'purchase_accounting_enabled' => true,
            'purchase_unit_cost' => 1000,
            'purchase_evidence' => [
                'purchase_unit_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'qualified',
                    'date_matches_entry_confirmed' => true,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ],
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'freee-'.uniqid(),
            'name' => 'freee販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 10000,
            'sold_at' => '2026-01-10',
            'status' => 'completed',
            'cost_basis' => 1000,
            'sales_fee' => 500,
            'shipping_fee' => $shippingFee,
            'packing_cost' => $packingCost,
            'net_profit' => 8500 - $shippingFee - $packingCost,
        ]);
        $entry = app(FurimaDeckAccountingEntryService::class)->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))->firstOrFail();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

        if ($purchaseReconciled) {
            $purchase = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product);
            $purchase->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
            $purchase->providerSyncs()->create([
                'provider' => 'freee',
                'status' => 'synced',
                'external_transaction_id' => 'freee-purchase-'.$product->id,
                'request_payload' => ['test_fixture' => true],
                'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)->fingerprint('freee', '123'),
                'verified_at' => now(),
            ]);
        }

        return [$user, $entry];
    }

    private function datedAccountItemsResponse(): PromiseInterface
    {
        return Http::response(['account_items' => [
            ['id' => 1, 'default_tax_code' => 21],
            ['id' => 2, 'default_tax_code' => 22],
            ['id' => 3, 'default_tax_code' => 23],
            ['id' => 4, 'default_tax_code' => 24],
            ['id' => 5, 'default_tax_code' => 25],
            ['id' => 6, 'default_tax_code' => 26],
        ]]);
    }
}
