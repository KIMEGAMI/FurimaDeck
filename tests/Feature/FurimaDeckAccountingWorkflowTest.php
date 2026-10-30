<?php

namespace Tests\Feature;

use App\Jobs\SendFreeeAccountingEntryJob;
use App\Jobs\SendMoneyForwardAccountingEntryJob;
use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\FurimaDeckAccountingApiReleaseGate;
use App\Services\FurimaDeckAccountingBatchService;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckAccountingJournalVerificationService;
use App\Services\FurimaDeckAccountingProviderScopeService;
use App\Services\FurimaDeckAccountingVendorCsvService;
use App\Services\FurimaDeckFreeeAccountingSetupService;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use App\Services\FurimaDeckFreeeCsvMappingService;
use App\Services\FurimaDeckMoneyForwardAccountingSetupService;
use App\Services\FurimaDeckMoneyForwardAccountingSyncService;
use App\Services\FurimaDeckMoneyForwardBusinessPeriodService;
use App\Services\FurimaDeckMoneyForwardCsvMappingService;
use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use App\Services\SaleLifecycleService;
use App\Services\SaleProfitCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckAccountingWorkflowTest extends TestCase
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
        config()->set('furimadeck.accounting.provider_api_enabled', false);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_send_screen_shows_csv_workflow_without_out_of_scope_api_controls(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        [$user] = $this->entryFixture();

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSV取込状況')
            ->assertSee('会計ソフトでCSVを取り込んだ後')
            ->assertDontSee('freeeへ送信')
            ->assertDontSee('Money Forwardへ送信')
            ->assertDontSee('へ一括送信')
            ->assertDontSee('外部仕訳を')
            ->assertDontSee('API')
            ->assertDontSee('税区分、仕入原価、送料などの会計処理を検証中');
    }

    public function test_queued_provider_history_shows_only_csv_safety_statuses(): void
    {
        [$user, $entry] = $this->entryFixture();
        foreach ([
            ['freee', 'queued'],
            ['money_forward', 'sending'],
        ] as [$provider, $status]) {
            $entry->providerSyncs()->updateOrCreate(['provider' => $provider], ['status' => $status]);
        }
        $user->accountingSyncBatches()->create([
            'provider' => 'freee',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'queued',
            'total_count' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSV取込状況')
            ->assertSee('登録状況が未確定の記録があります。freee側で登録有無を確認してください。')
            ->assertSee('登録状況が未確定の記録があります。Money Forward側で登録有無を確認してください。')
            ->assertDontSee('一括送信の履歴')
            ->assertDontSee('旧API')
            ->assertDontSee('外部仕訳ID')
            ->assertDontSee('一括送信の処理中です。')
            ->assertDontSee('>送信中</span>');
    }

    public function test_send_screen_remains_available_when_optional_api_batch_tables_are_not_migrated(): void
    {
        [$user] = $this->entryFixture();
        Schema::connection('furimadeck')->dropIfExists('accounting_sync_batch_items');
        Schema::connection('furimadeck')->dropIfExists('accounting_sync_batches');

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSV取込状況');
    }

    public function test_provider_api_send_and_readback_routes_are_closed_when_release_gate_is_disabled(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        [$user, $entry] = $this->entryFixture();

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.send.freee', $entry))
            ->assertNotFound();

        $this->post(route('furimadeck-accounting.send.money-forward', $entry))
            ->assertNotFound();

        $this->post(route('furimadeck-accounting.send.freee.batch'))
            ->assertNotFound();

        $this->post(route('furimadeck-accounting.send.money-forward.batch'))
            ->assertNotFound();

        $this->post(route('furimadeck-accounting.sync.verify', [$entry, 'freee']))
            ->assertNotFound();

        $this->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'mark_synced',
            'external_transaction_id' => 'release-gate-test',
        ])->assertNotFound();

        $this->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'confirm_mf_default_invoice_kind',
            'confirm_mf_invoice_kind_correct' => '1',
            'confirm_mf_no_resend' => '1',
        ])->assertNotFound();

        $this->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertDontSee('freeeへ送信')
            ->assertDontSee('Money Forwardへ送信')
            ->assertDontSee('freeeへ一括送信')
            ->assertDontSee('Money Forwardへ一括送信')
            ->assertDontSee('freeeの仕訳を再照合')
            ->assertDontSee('Money Forwardの仕訳を再照合');
    }

    public function test_previously_queued_provider_jobs_fail_closed_after_release_gate_is_disabled(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        Http::fake();
        [$user, $entry] = $this->entryFixture();

        foreach ([
            ['freee', SendFreeeAccountingEntryJob::class, FurimaDeckFreeeAccountingSyncService::class],
            ['money_forward', SendMoneyForwardAccountingEntryJob::class, FurimaDeckMoneyForwardAccountingSyncService::class],
        ] as [$provider, $jobClass, $syncClass]) {
            $batch = $user->accountingSyncBatches()->create([
                'provider' => $provider,
                'period_from' => '2026-01-01',
                'period_to' => '2026-12-31',
                'status' => 'queued',
                'total_count' => 1,
            ]);
            $item = $batch->items()->create([
                'accounting_entry_id' => $entry->id,
                'management_id' => $entry->management_id,
                'title' => $entry->title,
                'status' => 'queued',
            ]);
            $entry->providerSyncs()->updateOrCreate(['provider' => $provider], ['status' => 'queued']);
            $entry->update(['sync_status' => 'queued']);

            (new $jobClass($batch->id, $item->id))->handle(app($syncClass));

            $this->assertSame('failed', $entry->providerSync($provider)->fresh()->status);
            $this->assertSame('failed', $item->fresh()->status);
            $this->assertStringContainsString('FurimaDeck 1.0', $item->fresh()->diagnostic);
            $this->assertSame('completed_with_errors', $batch->fresh()->status);
            $this->assertSame(1, $batch->fresh()->failed_count);
        }

        Http::assertNothingSent();

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSV未登録')
            ->assertDontSee('へ送信')
            ->assertDontSee('一括送信の履歴');
    }

    public function test_release_configuration_does_not_allow_environment_flags_to_reopen_journal_apis(): void
    {
        $flagNames = [
            'FURIMADECK_ACCOUNTING_PROVIDER_API_ENABLED',
            'FURIMADECK_DIRECT_JOURNAL_SEND_ENABLED',
        ];
        $originalValues = [];
        foreach ($flagNames as $flagName) {
            $originalValues[$flagName] = [
                'process' => getenv($flagName),
                'env_defined' => array_key_exists($flagName, $_ENV),
                'env' => $_ENV[$flagName] ?? null,
                'server_defined' => array_key_exists($flagName, $_SERVER),
                'server' => $_SERVER[$flagName] ?? null,
            ];
            putenv($flagName.'=true');
            $_ENV[$flagName] = 'true';
            $_SERVER[$flagName] = 'true';
        }

        try {
            foreach ($flagNames as $flagName) {
                $this->assertTrue((bool) env($flagName));
            }
            $releaseConfig = require base_path('config/furimadeck.php');
        } finally {
            foreach ($originalValues as $flagName => $originalValue) {
                if ($originalValue['process'] === false) {
                    putenv($flagName);
                } else {
                    putenv($flagName.'='.$originalValue['process']);
                }
                if ($originalValue['env_defined']) {
                    $_ENV[$flagName] = $originalValue['env'];
                } else {
                    unset($_ENV[$flagName]);
                }
                if ($originalValue['server_defined']) {
                    $_SERVER[$flagName] = $originalValue['server'];
                } else {
                    unset($_SERVER[$flagName]);
                }
            }
        }

        $this->assertFalse((bool) $releaseConfig['accounting']['provider_api_enabled']);
        $this->assertFalse((bool) $releaseConfig['accounting']['direct_journal_send_enabled']);
    }

    public function test_stale_true_accounting_api_flags_do_not_enable_apis_outside_phpunit(): void
    {
        $this->assertFalse(FurimaDeckAccountingApiReleaseGate::allows(false, true, true, true));
        $this->assertFalse(FurimaDeckAccountingApiReleaseGate::allows(false, true, true, false));
        $this->assertFalse(FurimaDeckAccountingApiReleaseGate::allows(true, false, true, true));
        $this->assertTrue(FurimaDeckAccountingApiReleaseGate::allows(true, true, true, true));
    }

    public function test_journal_readback_service_entry_points_do_not_call_provider_when_release_gate_is_disabled(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        Http::fake();
        [$user, $entry] = $this->entryFixture();
        $service = app(FurimaDeckAccountingJournalVerificationService::class);
        $expectedMessage = 'FurimaDeck 1.0では会計ソフト側の仕訳API読み戻しを提供していません。会計ソフト側で登録内容を確認してください。';

        $operations = [
            fn () => $service->verify($user, $entry, 'freee'),
            fn () => $service->confirmMoneyForwardDefaultInvoiceKind($user, $entry, true, true),
            fn () => $service->verifyLegacyUnscoped($user, $entry, 'freee', true, true, true),
            fn () => $service->reconcileUnknown($user, $entry, 'freee', 'external-id'),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('API readback must stay disabled while the release gate is closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_accounting_purchase_info_section_lists_unsold_products_and_scopes_them_to_the_owner(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-INFO-'.uniqid(),
            'product_name' => '売却前の仕入情報テスト商品',
            'condition' => 'used',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $supplier = $user->suppliers()->create(['name' => '会計画面だけで表示する仕入先', 'type' => 'online_shop']);
        $product->update(['supplier_id' => $supplier->id]);
        $otherOwnProduct = $user->products()->create([
            'internal_sku' => 'OTHER-OWN-PURCHASE-INFO-'.uniqid(),
            'product_name' => '別仕入先の商品',
            'condition' => 'used',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $otherProduct = $otherUser->products()->create([
            'internal_sku' => 'OTHER-PURCHASE-INFO-'.uniqid(),
            'product_name' => '別ユーザーの商品',
            'condition' => 'used',
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['section' => 'purchase-info']))
            ->assertOk()
            ->assertSee('商品別仕入情報')
            ->assertSee('売却前の商品も登録できます。')
            ->assertSee($product->internal_sku)
            ->assertSee(route('furimadeck-accounting.products.purchase-info.edit', $product), false)
            ->assertDontSee($otherProduct->internal_sku)
            ->assertDontSee(route('furimadeck-accounting.products.purchase-info.edit', $otherProduct), false);

        $purchaseInfoMenuUrl = route('furimadeck-accounting.index', ['section' => 'purchase-info']);
        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->assertOk()
            ->assertSeeInOrder([$purchaseInfoMenuUrl, '商品別仕入情報一覧へ']);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertOk()
            ->assertSeeInOrder([$purchaseInfoMenuUrl, '商品別仕入情報一覧へ']);

        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee($product->internal_sku)
            ->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('会計画面だけで表示する仕入先')
            ->assertSee($otherOwnProduct->product_name);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['section' => 'purchase-info', 'product_query' => '売却前の仕入情報']))
            ->assertOk()
            ->assertSee($product->internal_sku)
            ->assertDontSee($otherProduct->internal_sku);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['section' => 'purchase-info', 'supplier_id' => $supplier->id]))
            ->assertOk()
            ->assertSee('name="supplier_id"', false)
            ->assertSee($product->internal_sku)
            ->assertDontSee($otherOwnProduct->internal_sku)
            ->assertDontSee($otherProduct->internal_sku);
    }

    public function test_review_then_confirm_changes_only_unsent_entries_in_the_selected_period(): void
    {
        [$user, $entry] = $this->entryFixture();
        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026])->assertRedirect();
        $this->assertSame('review', $entry->fresh()->source_status);

        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'reviewed_source' => '1',
        ])->assertRedirect();
        $this->assertSame('confirmed', $entry->fresh()->source_status);
        $this->assertNotNull($entry->fresh()->confirmed_at);
    }

    public function test_confirm_requires_explicit_per_entry_acknowledgement_and_owner(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'review']);
        $other = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);

        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
        ])->assertSessionHasErrors('reviewed_source');
        $this->assertNull($entry->fresh()->confirmed_at);

        $this->actingAs($other)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'reviewed_source' => '1',
        ])->assertNotFound();
        $this->assertSame('review', $entry->fresh()->source_status);
    }

    public function test_classified_new_purchase_entry_can_be_confirmed_after_source_review(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-CONFIRM-001',
            'product_name' => '仕入確定テスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-12',
            'purchase_accounting_enabled' => true,
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_evidence' => [
                'purchase_unit_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'qualified',
                    'date_matches_entry_confirmed' => true,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ],
        ]);
        $entry = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product);

        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026])->assertRedirect();
        $this->assertSame('review', $entry->fresh()->source_status);
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()
            ->assertSee('仕入確定テスト商品')
            ->assertSee('この明細を確定');

        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'reviewed_source' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('confirmed', $entry->fresh()->source_status);
        $this->assertNotNull($entry->fresh()->confirmed_at);
        $this->assertSame(0, $entry->providerSyncs()->count());
    }

    public function test_purchase_entry_with_unclassified_evidence_cannot_be_confirmed(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-UNCLASSIFIED-001',
            'product_name' => '未分類仕入テスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-12',
            'purchase_accounting_enabled' => true,
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_evidence' => [
                'purchase_unit_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'unknown',
                    'invoice_issuer' => 'qualified',
                    'date_matches_entry_confirmed' => true,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ],
        ]);
        $entry = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product);

        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026])->assertRedirect();
        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'reviewed_source' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('review', $entry->fresh()->source_status);
        $this->assertNull($entry->fresh()->confirmed_at);
    }

    public function test_confirmed_purchase_entries_are_included_in_provider_batch_without_duplicate_batch(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        [$user, $entry] = $this->purchaseEntryFixture('freee');
        $roles = ['settlement', 'sales', 'fees', 'cost', 'inventory'];
        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => [
                'company_id' => '123',
                'account_item_ids' => array_combine($roles, ['1', '2', '3', '4', '5']),
                'tax_codes' => array_combine($roles, ['21', '22', '23', '24', '25']),
                'purchase_payment_accounts' => ['1' => ['account_id' => '1', 'name' => '普通預金']],
            ],
        ]);
        Queue::fake();

        $batch = app(FurimaDeckAccountingBatchService::class)->startFreee(
            $user,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2027-01-01'),
        );

        $this->assertSame(1, $batch->total_count);
        $this->assertSame($entry->id, $batch->items()->value('accounting_entry_id'));
        $this->assertSame('queued', $entry->providerSyncs()->where('provider', 'freee')->value('status'));
        Queue::assertPushed(SendFreeeAccountingEntryJob::class);

        try {
            app(FurimaDeckAccountingBatchService::class)->startFreee(
                $user,
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2027-01-01'),
            );
            $this->fail('An active provider batch must prevent duplicate dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('すでに処理中', $exception->getMessage());
        }

        $this->assertSame(1, $user->accountingSyncBatches()->where('provider', 'freee')->count());
    }

    public function test_purchase_batch_is_not_created_when_provider_payment_source_is_missing(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        [$user, $entry] = $this->purchaseEntryFixture('freee');
        $this->configurePurchaseAccountingConnections($user, false);
        Queue::fake();

        try {
            app(FurimaDeckAccountingBatchService::class)->startFreee(
                $user,
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2027-01-01'),
            );
            $this->fail('A purchase without a provider payment source must not be queued.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('支払元が未設定または無効', $exception->getMessage());
        }

        $this->assertSame(0, $user->accountingSyncBatches()->where('provider', 'freee')->count());
        $this->assertSame(0, $entry->providerSyncs()->where('provider', 'freee')->count());
        Queue::assertNothingPushed();
    }

    public function test_legacy_bulk_confirm_requires_fresh_per_entry_review(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => null]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()->assertSee('旧データ・再確認が必要');
        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'reviewed_source' => '1',
        ])->assertSessionHas('error');
        $this->assertNull($entry->fresh()->confirmed_at);

        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026])->assertRedirect();
        $this->assertSame('review', $entry->fresh()->source_status);
    }

    public function test_accounting_review_page_shows_missing_source_values_separately_from_zero_amounts(): void
    {
        [$user, $entry] = $this->entryFixture();

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()
            ->assertSee('原資料との照合が必要な取引: 1件')
            ->assertSee('仕入日、仕入先')
            ->assertSee('販売手数料、送料、その他費用')
            ->assertSee(route('furimadeck-accounting.products.purchase-info.edit', $entry->sale->product), false);
    }

    public function test_historical_purchase_candidates_are_read_only_and_cannot_create_accounting_entries(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $selected = $user->products()->create([
            'internal_sku' => 'PURCHASE-REVIEW-'.uniqid(),
            'product_name' => '選択した仕入商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-03',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $unselected = $user->products()->create([
            'internal_sku' => 'PURCHASE-REVIEW-'.uniqid(),
            'product_name' => '選択しない仕入商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-04',
            'purchase_unit_cost' => 900,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()
            ->assertSee('既存商品の仕入情報・照合待ち')
            ->assertSee('期首在庫や既存仕訳との重複を判定できません')
            ->assertSee('関連する売上仕訳全体を会計CSVの対象から保留します')
            ->assertSee('選択した仕入商品')
            ->assertDontSee('name="product_ids[]"', false)
            ->assertDontSee('existing_accounting_records_checked');

        $this->actingAs($user)->post(route('furimadeck-accounting.purchases.prepare'), [
            'year' => 2026,
            'product_ids' => [$selected->id],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AccountingEntry::query()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
        $this->assertSame(0, AccountingEntry::query()->where('product_id', $unselected->id)->count());
    }

    public function test_purchase_accounting_rejects_products_owned_by_another_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $owner = User::factory()->create();
        $foreignProduct = $owner->products()->create([
            'internal_sku' => 'PURCHASE-FOREIGN-'.uniqid(),
            'product_name' => '他人の商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-03',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.purchases.prepare'), [
            'year' => 2026,
            'product_ids' => [$foreignProduct->id],
            'existing_accounting_records_checked' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AccountingEntry::query()->count());
    }

    public function test_purchase_accounting_rejects_a_product_outside_the_selected_period(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-OUTSIDE-'.uniqid(),
            'product_name' => '対象期間外の商品',
            'condition' => 'used',
            'purchase_date' => '2025-12-31',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.purchases.prepare'), [
            'year' => 2026,
            'product_ids' => [$product->id],
            'existing_accounting_records_checked' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AccountingEntry::query()->count());
    }

    public function test_purchase_prepare_does_not_treat_user_attestation_as_reconciliation_evidence(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-CHECK-'.uniqid(),
            'product_name' => '照合が必要な仕入商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-03',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.purchases.prepare'), [
            'year' => 2026,
            'product_ids' => [$product->id],
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AccountingEntry::query()->count());
    }

    public function test_accounting_menu_groups_accounting_work_by_purpose(): void
    {
        [$user] = $this->entryFixture();

        $response = $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026]))
            ->assertOk();

        $this->assertSame('overview', $response->viewData('accountingSection'));
        $response
            ->assertSee('売上・仕入・費用データの不足を確認する')
            ->assertSee('1. 取引データを確認')
            ->assertSee('2. 会計サービスへ登録')
            ->assertSee('3. サービス設定')
            ->assertSee('明細の確認・確定')
            ->assertSee('CSV取込状況')
            ->assertSee('CSVで登録')
            ->assertSee('会計ソフト設定')
            ->assertSee('準備状況')
            ->assertDontSee('class="group relative" open', false)
            ->assertDontSee('会計データ送信')
            ->assertDontSee('freee CSVの登録');

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()
            ->assertSee('金額と領収書などを確認する')
            ->assertSee('会計CSV登録前の確認')
            ->assertSee('確認済みの明細から、出力条件を満たす会計ソフト用CSVを作成できます。')
            ->assertSee('明細を確認して確定')
            ->assertDontSee('外部送信前の確認')
            ->assertDontSee('会計データ送信');

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSV取込状況')
            ->assertSee('会計ソフトでCSVを取り込んだ後')
            ->assertDontSee('API')
            ->assertDontSee('freeeへ一括送信')
            ->assertDontSee('Money Forwardへ一括送信')
            ->assertDontSee('この明細を確定');

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertOk()
            ->assertSee('CSV出力と登録')
            ->assertDontSee('freee CSV一括出力')
            ->assertDontSee('Money Forward CSV一括出力')
            ->assertSee('freee連携が未接続または期限切れのため、一括CSVを出力できません。')
            ->assertSee('Money Forward連携が未接続または期限切れのため、一括CSVを出力できません。')
            ->assertSee('freee CSVの登録')
            ->assertSee('freeeで出力対象になる取引')
            ->assertSee('仕入CSVと別途支払った費用を含む販売CSVは、FurimaDeck 1.0では出力しません。')
            ->assertSee('販売手数料を含む明細は実取込の検証が未完了のため出力しません。販売送料が0円でない明細、または梱包費・修理費・清掃費・その他費用がある明細も対象外です。')
            ->assertSee('Money Forwardで出力対象になる取引')
            ->assertSee('FurimaDeck 1.0では仕入CSVと別途支払った費用を含む販売CSVも対象外です。')
            ->assertSee('Money Forwardが取り込めるのは、現在選択中の会計年度内の日付だけです。')
            ->assertSee('インポートは新規追加のみで、登録済み仕訳は更新されません。')
            ->assertSee('全ての金額が内税（税込）※税額自動計算あり')
            ->assertSee('税額を0円として取り込む危険があります。')
            ->assertSee('CSVの「伝票番号」をfreeeの「伝票番号」に割り当ててください。')
            ->assertSee('売上行と原価行が別々の伝票として取り込まれることがあります。')
            ->assertSee('CSVで表せない区分や取引日と合わない控除率がある場合は、誤登録防止のため出力を停止します。')
            ->assertDontSee('会計データ送信');

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'connections']))
            ->assertOk()
            ->assertSee('会計サービスをCSV用に接続する')
            ->assertSee('Money Forward')
            ->assertSee('連携する')
            ->assertDontSee('会計データ送信');
    }

    public function test_unreconciled_purchase_hides_sale_send_and_csv_actions(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('仕入・期首在庫の確認待ちで保留中です')
            ->assertDontSee('freeeへ送信')
            ->assertDontSee('Money Forwardへ送信')
            ->assertDontSee('freee 1件CSV')
            ->assertDontSee('Money Forward 1件CSV');
    }

    public function test_freee_csv_action_is_hidden_until_connection_and_setup_are_ready(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertDontSee('freee 1件CSV')
            ->assertSee('freeeの仕訳設定が不足しているため、CSVを出力できません。')
            ->assertSee('freee仕訳設定を確認');

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertOk()
            ->assertDontSee('freee CSV一括出力')
            ->assertSee('freeeの仕訳設定が不足しているため、一括CSVを出力できません。');
    }

    public function test_vendor_bulk_csv_actions_are_hidden_when_a_period_contains_an_unsupported_sale(): void
    {
        [$user, $firstEntry] = $this->entryFixture();
        $firstEntry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $firstSale = $firstEntry->sale;
        $product = $user->products()->create([
            'internal_sku' => 'WORKFLOW-BATCH-'.uniqid(),
            'product_name' => '一括CSV未対応商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $firstSale->marketplace_id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => '2026-01-11',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 2000,
        ]);
        $secondEntry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstWhere('sale_id', $sale->id);
        $this->assertNotNull($secondEntry);
        $secondEntry->update(['source_status' => 'confirmed', 'confirmed_at' => now(), 'selling_fee' => 100]);

        foreach ([$firstEntry, $secondEntry] as $entry) {
            $this->reconcilePurchaseForProvider($user, $entry, 'freee');
            $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');
        }

        $freeeConnection = $this->ensureProviderConnection($user, 'freee');
        $freeeConnection->update(['settings' => [
            'company_id' => '123',
            'account_item_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
            'tax_codes' => ['settlement' => '21', 'sales' => '22', 'fees' => '23', 'cost' => '24', 'inventory' => '25'],
        ]]);
        $moneyForwardConnection = $this->ensureProviderConnection($user, 'money_forward');
        $moneyForwardConnection->update(['settings' => [
            'account_ids' => ['settlement' => 'a1', 'sales' => 'a2', 'fees' => 'a3', 'cost' => 'a4', 'inventory' => 'a5'],
            'sub_account_ids' => ['settlement' => null, 'sales' => null, 'fees' => null, 'cost' => null, 'inventory' => null],
            'tax_ids' => ['settlement' => 't1', 'sales' => 't2', 'fees' => 't3', 'cost' => 't4', 'inventory' => 't5'],
            'invoice_kinds' => array_fill_keys(['settlement', 'sales', 'fees', 'cost', 'inventory'], 'INVOICE_KIND_QUALIFIED'),
            'account_statement_types_checked' => true,
            'business_tax_periods' => [[
                'from' => '2026-01-01',
                'to' => '2026-12-31',
                'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
            ]],
        ]]);

        $response = $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertOk()
            ->assertViewHas('freeeCsvBulkExportStatus', 'blocked')
            ->assertViewHas('moneyForwardCsvBulkExportStatus', 'blocked')
            ->assertDontSee('freee CSV一括出力')
            ->assertDontSee('Money Forward CSV一括出力')
            ->assertSee('販売手数料を含む明細は、実取込の検証が完了していないため');

        $this->assertSame('not_synced', $firstEntry->fresh()->providerSyncStatus('freee'));
        $this->assertSame('not_synced', $firstEntry->fresh()->providerSyncStatus('money_forward'));
        $this->assertSame('not_synced', $secondEntry->fresh()->providerSyncStatus('freee'));
        $this->assertSame('not_synced', $secondEntry->fresh()->providerSyncStatus('money_forward'));
    }

    public function test_provider_single_csv_actions_are_hidden_when_confirmed_sale_changes(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');

        $freeeConnection = $this->ensureProviderConnection($user, 'freee');
        $freeeConnection->update(['settings' => [
            'company_id' => '123',
            'account_item_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
            'tax_codes' => ['settlement' => '21', 'sales' => '22', 'fees' => '23', 'cost' => '24', 'inventory' => '25'],
        ]]);
        $moneyForwardConnection = $this->ensureProviderConnection($user, 'money_forward');
        $moneyForwardConnection->update(['settings' => [
            'account_ids' => ['settlement' => 'a1', 'sales' => 'a2', 'fees' => 'a3', 'cost' => 'a4', 'inventory' => 'a5'],
            'sub_account_ids' => ['settlement' => null, 'sales' => null, 'fees' => null, 'cost' => null, 'inventory' => null],
            'tax_ids' => ['settlement' => 't1', 'sales' => 't2', 'fees' => 't3', 'cost' => 't4', 'inventory' => 't5'],
            'invoice_kinds' => array_fill_keys(['settlement', 'sales', 'fees', 'cost', 'inventory'], 'INVOICE_KIND_QUALIFIED'),
            'account_statement_types_checked' => true,
            'business_tax_periods' => [[
                'from' => '2026-01-01',
                'to' => '2026-12-31',
                'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
            ]],
        ]]);

        $entry->sale->update(['sold_price' => 3100]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertDontSee('freee 1件CSV')
            ->assertDontSee('Money Forward 1件CSV')
            ->assertSee('売上記録と会計データが一致しません')
            ->assertSee(route('furimadeck-accounting.index', ['year' => 2026, 'from' => null, 'to' => null, 'section' => 'csv']))
            ->assertSee('CSVで登録へ移動して「確認用データを更新」');

        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('money_forward'));
    }

    public function test_stale_confirmed_sale_can_be_refreshed_and_reconfirmed(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $entry->sale->update(['sold_price' => 3100, 'net_profit' => 2100]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSVで登録へ移動して「確認用データを更新」');

        $this->actingAs($user)->post(route('furimadeck-accounting.sync'), ['year' => 2026])
            ->assertRedirect(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertSessionHas('success');

        $entry->refresh();
        $this->assertSame(3100, $entry->sale_amount);
        $this->assertSame('review', $entry->source_status);
        $this->assertNull($entry->confirmed_at);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()
            ->assertSee('3,100')
            ->assertSee('この明細を確定');

        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'reviewed_source' => '1',
        ])->assertSessionHas('success');

        $this->assertSame('confirmed', $entry->fresh()->source_status);
        $this->assertNotNull($entry->fresh()->confirmed_at);
    }

    public function test_api_readback_details_are_not_exposed_in_the_1_0_screen(): void
    {
        [$user, $entry] = $this->entryFixture();
        foreach (['freee' => '12345', 'money_forward' => 'mf-journal-12345'] as $provider => $externalId) {
            $connection = $this->ensureProviderConnection($user, $provider);
            $entry->providerSyncs()->create([
                'provider' => $provider,
                'status' => 'synced',
                'external_transaction_id' => $externalId,
                'request_payload' => ['readback_test' => true],
                'verified_at' => now(),
                'provider_account_scope_hash' => $provider === 'freee'
                    ? null
                    : app(FurimaDeckAccountingProviderScopeService::class)->fingerprintForConnection($connection, $provider),
            ]);
        }

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSeeText('以前の登録記録あり・確認が必要')
            ->assertDontSeeText('外部仕訳の内容一致を確認済み')
            ->assertDontSeeText('登録内容の確認済み')
            ->assertDontSeeText('登録先の確認が必要')
            ->assertDontSeeText('過去の照合日時')
            ->assertDontSeeText('利用者確認:')
            ->assertDontSeeText('12345')
            ->assertDontSeeText('mf-journal-12345');
    }

    public function test_money_forward_api_readback_mismatch_details_are_not_exposed_in_the_1_0_screen(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-journal-invoice-mismatch',
            'request_payload' => ['journal' => 'redacted-test-payload'],
            'last_error' => 'external_content_mismatch:debit_invoice,credit_invoice',
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSeeText('過去の会計サービス処理記録があります。Money Forward側で登録内容を確認してください。')
            ->assertDontSeeText('送信時とMoney Forward側のインボイス区分が一致しません')
            ->assertDontSeeText('external_content_mismatch')
            ->assertDontSeeText('redacted-test-payload')
            ->assertDontSee('INVOICE_KIND_QUALIFIED');
    }

    public function test_money_forward_provider_invoice_readback_details_are_not_exposed_in_the_1_0_screen(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = $this->ensureProviderConnection($user, 'money_forward');
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-journal-provider-default',
            'request_payload' => ['journal' => 'redacted-test-payload'],
            'last_error' => AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'money_forward'),
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSeeText('以前の登録記録あり・確認が必要')
            ->assertDontSeeText('送信していないインボイス区分')
            ->assertDontSeeText('送信時とMoney Forward側のインボイス区分が一致しません');
    }

    public function test_purchase_entry_is_excluded_from_provider_csv_actions_in_release_scope(): void
    {
        [$user] = $this->purchaseEntryFixture('freee');
        $paymentSources = ['freee' => '1', 'money_forward' => 'bank-source'];
        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        $entry->product->update(['purchase_payment_sources' => $paymentSources]);
        $entry->update(['expense_breakdown' => [...(array) $entry->expense_breakdown, 'payment_sources' => $paymentSources]]);
        $this->configurePurchaseAccountingConnections($user, true);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('freee用仕入CSVはFurimaDeck 1.0の対応対象外です。')
            ->assertSee('Money Forward用仕入CSVはFurimaDeck 1.0の対応対象外です。')
            ->assertDontSee('freee 1件CSV')
            ->assertDontSee('Money Forward 1件CSV')
            ->assertDontSee('freeeへ送信')
            ->assertDontSee('Money Forwardへ送信');

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()
            ->assertSeeText('この仕入明細はFurimaDeck 1.0の会計ソフト用CSV出力対象外です。')
            ->assertSeeText('会計データの仕入画面で手動照合してください')
            ->assertDontSeeText('確認後、対応する会計ソフト用CSVを作成できます。');
    }

    public function test_direct_provider_csv_requests_reject_purchase_entries_without_changing_sync_state(): void
    {
        foreach (['freee', 'money_forward'] as $provider) {
            [$user, $entry] = $this->purchaseEntryFixture($provider);
            $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
            $routeProvider = $provider === 'freee' ? 'freee' : 'money-forward';

            $this->actingAs($user)->post(route('furimadeck-accounting.'.$routeProvider), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
                'confirm_all_amounts_tax_inclusive' => '1',
            ])->assertRedirect()
                ->assertSessionHas('error', $providerName.'用仕入CSVはFurimaDeck 1.0の対応対象外です。仕入は会計サービス側で別途登録してください。');

            $this->assertSame('confirmed', $entry->fresh()->source_status);
            $this->assertSame('not_synced', $entry->fresh()->sync_status);
            $this->assertFalse($entry->providerSyncs()->where('provider', $provider)->exists());
        }
    }

    public function test_batch_provider_csv_exports_supported_sales_without_blocking_on_purchase_entries(): void
    {
        [$user, $saleEntry] = $this->entryFixture();
        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026]);
        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $saleEntry->id,
            'reviewed_source' => '1',
        ])->assertRedirect();
        $this->reconcilePurchaseForProvider($user, $saleEntry, 'freee');
        $purchaseEntry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();

        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldReceive('forEntries')
            ->once()
            ->withArgs(function (User $mappingUser, Collection $entries) use ($user, $saleEntry): bool {
                return $mappingUser->is($user)
                    && $entries->count() === 1
                    && $entries->first()->is($saleEntry)
                    && $entries->first()->entry_type === AccountingEntry::TYPE_SALE;
            })
            ->andReturn([
                'accounts' => [
                    'settlement' => '売掛金',
                    'sales' => '売上高',
                    'fees' => '支払手数料',
                    'cost' => '仕入高',
                    'inventory' => '商品',
                ],
                'tax_names' => [
                    'settlement' => '対象外',
                    'sales' => '課税売上10%',
                    'fees' => '対象外',
                    'cost' => '課対仕入10%',
                    'inventory' => '対象外',
                ],
            ]);
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $response = $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertOk()->assertDownload();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('会計ワークフロー商品 売上', $csv);
        $this->assertStringContainsString('会計ワークフロー商品 原価', $csv);
        $this->assertStringNotContainsString('FurimaDeck仕入', $csv);
        $this->assertSame('csv_pending', $saleEntry->fresh()->providerSyncStatus('freee'));
        $this->assertSame(AccountingEntrySync::STATUS_CSV_IMPORTED, $purchaseEntry->fresh()->providerSyncStatus('freee'));
    }

    public function test_same_day_provider_csv_entries_are_processed_in_stable_id_order(): void
    {
        [$user, $firstEntry] = $this->entryFixture();
        $firstSale = $firstEntry->sale;
        $secondProduct = $user->products()->create([
            'internal_sku' => 'WORKFLOW-'.uniqid(),
            'product_name' => '同日販売商品B',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $secondSale = $user->sales()->create([
            'product_id' => $secondProduct->id,
            'marketplace_id' => $firstSale->marketplace_id,
            'internal_sku_snapshot' => $secondProduct->internal_sku,
            'product_name_snapshot' => $secondProduct->product_name,
            'quantity' => 1,
            'sold_price' => 2500,
            'sold_at' => '2026-01-10',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 1500,
        ]);
        $entries = app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2027-01-01'),
        );
        $secondEntry = $entries->firstWhere('sale_id', $secondSale->id);
        $this->assertNotNull($secondEntry);
        $expectedEntryIds = collect([$firstEntry->id, $secondEntry->id])->sort()->values()->all();

        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026]);
        foreach ([$firstEntry, $secondEntry] as $entry) {
            $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
                'reviewed_source' => '1',
            ])->assertRedirect();
            $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        }

        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldReceive('forEntries')
            ->once()
            ->withArgs(fn (User $mappingUser, Collection $selectedEntries): bool => $mappingUser->is($user)
                && $selectedEntries->pluck('id')->all() === $expectedEntryIds)
            ->andReturn([
                'accounts' => [
                    'settlement' => '売掛金',
                    'sales' => '売上高',
                    'fees' => '支払手数料',
                    'cost' => '仕入高',
                    'inventory' => '商品',
                ],
                'tax_names' => [
                    'settlement' => '対象外',
                    'sales' => '課税売上10%',
                    'fees' => '対象外',
                    'cost' => '課対仕入10%',
                    'inventory' => '対象外',
                ],
            ]);
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $response = $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'confirm_all_amounts_tax_inclusive' => '1',
        ]);
        $response->assertDownload();
        $csv = $response->streamedContent();

        $firstPosition = strpos($csv, $firstEntry->title.' 売上');
        $secondPosition = strpos($csv, $secondEntry->title.' 売上');
        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
    }

    public function test_purchase_actions_are_hidden_until_provider_payment_sources_are_configured(): void
    {
        [$user] = $this->purchaseEntryFixture('freee');
        $this->configurePurchaseAccountingConnections($user, false);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('freee用仕入CSVはFurimaDeck 1.0の対応対象外です。')
            ->assertSee('Money Forward用仕入CSVはFurimaDeck 1.0の対応対象外です。')
            ->assertDontSee('freeeへ一括送信')
            ->assertDontSee('Money Forwardへ一括送信')
            ->assertDontSee('freeeへ送信')
            ->assertDontSee('Money Forwardへ送信')
            ->assertDontSee('freee 1件CSV')
            ->assertDontSee('Money Forward 1件CSV');
    }

    private function configurePurchaseAccountingConnections(User $user, bool $includePaymentSources): void
    {
        $roles = ['settlement', 'sales', 'fees', 'cost', 'inventory'];
        $user->accountingConnections()->create([
            'provider' => 'freee', 'access_token' => 'freee-token', 'refresh_token' => 'freee-refresh',
            'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => [
                'company_id' => '123',
                'account_item_ids' => array_combine($roles, ['1', '2', '3', '4', '5']),
                'tax_codes' => array_combine($roles, ['21', '22', '23', '24', '25']),
                'purchase_payment_accounts' => $includePaymentSources ? ['1' => ['account_id' => '1', 'name' => '普通預金']] : [],
            ],
        ]);
        $user->accountingConnections()->create([
            'provider' => 'money_forward', 'access_token' => 'mf-token', 'refresh_token' => 'mf-refresh',
            'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => [
                'account_ids' => array_combine($roles, ['a1', 'a2', 'a3', 'a4', 'a5']),
                'sub_account_ids' => array_fill_keys($roles, null),
                'tax_ids' => array_combine($roles, ['t1', 't2', 't3', 't4', 't5']),
                'account_statement_types_checked' => true,
                'invoice_kind' => 'INVOICE_KIND_QUALIFIED',
                'business_tax_periods' => [['from' => '2000-01-01', 'to' => '2100-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
                'invoice_kinds' => array_fill_keys($roles, 'INVOICE_KIND_QUALIFIED'),
                'purchase_payment_accounts' => $includePaymentSources ? ['bank-source' => ['account_id' => 'bank-account', 'sub_account_id' => 'bank-source', 'name' => '普通預金']] : [],
            ],
        ]);
    }

    public function test_vendor_csv_is_not_created_for_an_unreconciled_product(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        Http::fake();

        foreach ([
            'freee' => 'furimadeck-accounting.freee',
            'money_forward' => 'furimadeck-accounting.money-forward',
        ] as $provider => $route) {
            $this->actingAs($user)->post(route($route), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
            ])->assertRedirect()->assertSessionHas('error', fn (string $message): bool => str_contains($message, '仕入れ・期首在庫は未照合'));

            $this->assertDatabaseMissing('accounting_entry_syncs', [
                'accounting_entry_id' => $entry->id,
                'provider' => $provider,
            ], 'furimadeck');
        }

        Http::assertNothingSent();
    }

    public function test_accounting_navigation_follows_analysis(): void
    {
        [$user] = $this->entryFixture();

        $content = $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026]))
            ->assertOk()
            ->getContent();

        $analysisPosition = strpos($content, '>分析 <span');
        $accountingPosition = strpos($content, '>会計データ <span');

        $this->assertNotFalse($analysisPosition);
        $this->assertNotFalse($accountingPosition);
        $this->assertTrue($analysisPosition < $accountingPosition, '会計データは分析の直後に配置します。');
    }

    public function test_accounting_page_shows_cost_breakdown_and_marks_legacy_breakdown_unknown(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update([
            'selling_fee' => 100,
            'shipping_cost' => 200,
            'other_cost' => 50,
            'expense_breakdown' => [
                'purchase_shipping_cost' => 10,
                'packing_cost' => 20,
                'repair_cost' => 5,
                'cleaning_cost' => 5,
                'other_expense' => 10,
            ],
            'furimadeck_actual_profit' => 1650,
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()
            ->assertSee('売上 3,000円')
            ->assertSee('原価 1,000円 / 手数料 100円')
            ->assertSee('送料 200円 / その他 50円')
            ->assertSee('実利益 1,650円')
            ->assertSee('仕入送料')
            ->assertSee('梱包費')
            ->assertSee('修理費')
            ->assertSee('クリーニング費')
            ->assertSee('その他経費');

        $entry->update(['expense_breakdown' => null]);
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()
            ->assertSee('旧データのため内訳は未記録です。');
    }

    public function test_connection_page_lists_provider_setup_gaps_without_requiring_purchase_candidates_for_release_csv(): void
    {
        [$user] = $this->entryFixture();
        foreach (['freee', 'money_forward'] as $provider) {
            $user->accountingConnections()->create([
                'provider' => $provider,
                'access_token' => $provider.'-token',
                'refresh_token' => $provider.'-refresh',
                'expires_at' => now()->addHour(),
                'status' => 'connected',
                'settings' => [],
            ]);
        }

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'connections']))
            ->assertOk()
            ->assertSee('CSV出力前に次の設定が必要です。')
            ->assertSee('freeeの事業所')
            ->assertSee('Money Forward')
            ->assertSee('勘定科目が貸借のどちら側で使われるか確認')
            ->assertSee('取引日を含む課税・免税の対象期間')
            ->assertSee('FurimaDeck 1.0では仕入CSVを出力しないため、この候補設定は売上CSVに不要です。');
    }

    public function test_sync_period_respects_japan_year_boundaries(): void
    {
        [$user, $entry] = $this->entryFixture();
        $baseSale = $user->sales()->firstOrFail();
        $baseSale->update(['sold_at' => '2025-12-31', 'sold_price' => 10000, 'net_profit' => 9000]);

        foreach ([
            ['date' => '2026-01-01', 'amount' => 20000],
            ['date' => '2026-06-15', 'amount' => 30000],
            ['date' => '2026-12-31', 'amount' => 40000],
            ['date' => '2027-01-01', 'amount' => 50000],
        ] as $boundary) {
            $sale = $baseSale->replicate();
            $sale->sold_at = $boundary['date'];
            $sale->sold_price = $boundary['amount'];
            $sale->net_profit = $boundary['amount'] - 1000;
            $sale->save();
        }

        $entries = app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-01-01', config('app.timezone')),
            CarbonImmutable::parse('2027-01-01', config('app.timezone')),
        );

        $this->assertCount(3, $entries);
        $this->assertSame(90000, (int) $entries->sum('sale_amount'));
        $this->assertSame(['2026-01-01', '2026-06-15', '2026-12-31'], $entries->pluck('transaction_date')->map(fn ($date): string => $date->toDateString())->all());
        $this->assertDatabaseMissing('accounting_entries', ['transaction_date' => '2025-12-31']);
        $this->assertDatabaseMissing('accounting_entries', ['transaction_date' => '2027-01-01']);
    }

    public function test_sync_period_preserves_review_and_external_sync_state_for_existing_entries(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update([
            'source_status' => 'confirmed',
            'sync_status' => 'synced',
            'external_transaction_id' => 'freee-123',
            'synced_at' => now(),
        ]);

        app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-01-01', config('app.timezone')),
            CarbonImmutable::parse('2027-01-01', config('app.timezone')),
        );

        $this->assertSame('confirmed', $entry->fresh()->source_status);
        $this->assertSame('synced', $entry->fresh()->sync_status);
        $this->assertSame('freee-123', $entry->fresh()->external_transaction_id);
    }

    public function test_review_is_user_scoped(): void
    {
        [$owner, $entry] = $this->entryFixture();
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);

        $this->actingAs($otherUser)->post(route('furimadeck-accounting.review'), ['year' => 2026])->assertRedirect();
        $this->assertSame('ready', $entry->fresh()->source_status);
    }

    public function test_sent_sale_cancelled_later_is_shown_as_reconciliation_required(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['sync_status' => 'synced', 'source_status' => 'confirmed']);
        $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'synced',
            'external_transaction_id' => '123',
            'request_payload' => ['company_id' => 12],
        ]);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        $user->sales()->firstOrFail()->update(['status' => 'cancelled']);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('過去の会計サービス処理記録があります。登録内容を確認してください。')
            ->assertSee($entry->management_id)
            ->assertSee('取消済み')
            ->assertSee(route('products.edit', $entry->sale->product), false)
            ->assertDontSee(route('furimadeck-accounting.sync.verify', [$entry, 'freee']), false)
            ->assertDontSee('freeeの仕訳を再照合')
            ->assertDontSee('freeeへ送信');
    }

    public function test_cancelled_sale_with_unknown_send_result_stays_visible_without_a_resend_action(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $entry->sale->update(['status' => 'returned', 'returned_at' => now()]);
        $connection = $this->ensureProviderConnection($user, 'money_forward');
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'sync_unknown',
            'last_error' => 'network_error',
            'request_payload' => ['transaction_date' => '2026-01-10', 'branches' => []],
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'money_forward'),
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('取消・返品に伴う会計登録状況')
            ->assertSee('過去の登録結果を確認できないため')
            ->assertDontSee('未登録として記録（再送なし）')
            ->assertDontSee('Money Forwardへ送信')
            ->assertDontSee('freeeへ送信')
            ->assertDontSee('旧記録を手動照合');
    }

    public function test_confirmed_absence_for_purchase_entry_does_not_use_inactive_sale_rule(): void
    {
        [$user, $entry] = $this->purchaseEntryFixture('freee');
        $connection = $this->ensureProviderConnection($user, 'freee');
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'sync_unknown',
            'last_error' => 'network_error',
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'freee'),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'retry',
            'verified_absent' => '1',
        ])->assertSessionHas('success', '仕訳がないことを記録しました。API送信は行っていません。仕入データはFurimaDeck 1.0の会計CSV出力対象外です。');

        $this->assertSame('not_synced', $sync->fresh()->status);
        $this->assertNull($sync->fresh()->last_error);
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
    }

    public function test_unknown_send_cannot_be_marked_absent_after_provider_account_changes(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $connection = $this->ensureProviderConnection($user, 'freee');
        $scopeHash = app(FurimaDeckAccountingProviderScopeService::class)
            ->fingerprintForConnection($connection, 'freee');
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'sync_unknown',
            'last_error' => 'network_error',
            'provider_account_scope_hash' => $scopeHash,
        ]);
        $this->changeProviderAccountScope($connection, 'freee');

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'retry',
            'verified_absent' => '1',
        ])->assertSessionHas('error', fn (string $message): bool => str_contains($message, '送信時と同じ会計事業者'));

        $this->assertSame('sync_unknown', $sync->fresh()->status);
        $this->assertSame($scopeHash, $sync->fresh()->provider_account_scope_hash);
    }

    public function test_legacy_unknown_without_provider_scope_cannot_be_marked_absent(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->ensureProviderConnection($user, 'freee');
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'legacy_unknown',
            'last_error' => 'legacy_send_unknown',
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'retry',
            'verified_absent' => '1',
        ])->assertSessionHas('error', fn (string $message): bool => str_contains($message, '送信時と同じ会計事業者'));

        $this->assertSame('legacy_unknown', $sync->fresh()->status);
        $this->assertNull($sync->fresh()->provider_account_scope_hash);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('過去の登録結果を確認できないため')
            ->assertDontSee('仕訳なしを記録（API送信なし）');
    }

    public function test_csv_pending_without_provider_scope_does_not_offer_manual_resolution(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'csv_pending',
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSV出力時の会計事業者を特定する記録がないため、取込済み・未登録のどちらにも確定できません。')
            ->assertDontSee('CSV取込済みを記録');
    }

    public function test_sent_sale_amount_change_is_visible_only_to_its_owner(): void
    {
        [$owner, $entry] = $this->entryFixture();
        $entry->update(['sync_status' => 'synced', 'source_status' => 'confirmed']);
        $owner->sales()->firstOrFail()->update(['sold_price' => 3100, 'net_profit' => 2100]);
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($owner)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee($entry->management_id)
            ->assertSee('3,000円')
            ->assertSee('3,100円');
        $this->actingAs($otherUser)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertDontSee($entry->management_id)
            ->assertDontSee('過去の会計サービス処理記録があります。');
    }

    public function test_sale_correction_recalculates_every_yen_and_requires_accounting_review(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed']);
        $sale = $entry->sale;
        $this->actingAs($user)->get(route('furimadeck-sales.edit', $sale))
            ->assertOk()
            ->assertSee($sale->marketplace->name)
            ->assertSee('name="marketplace_id"', false)
            ->assertSee('name="sold_at"', false)
            ->assertSee('id="sales-fee-rate" readonly', false)
            ->assertSee('販売手数料（自動計算）')
            ->assertSee('readonly', false)
            ->assertDontSee('会計データの金額・証憑を確認')
            ->assertDontSee('仕入原価')
            ->assertDontSee('name="cost_basis"', false)
            ->assertDontSee('name="purchase_shipping_cost"', false)
            ->assertDontSee('name="packing_cost"', false)
            ->assertDontSee('name="repair_cost"', false)
            ->assertDontSee('name="cleaning_cost"', false)
            ->assertDontSee('name="other_expense"', false)
            ->assertDontSee('証憑の税区分');

        $this->actingAs($user)->get(route('furimadeck-accounting.sales.expenses.edit', $sale))
            ->assertOk()
            ->assertSee('name="cost_basis"', false)
            ->assertSee('name="purchase_shipping_cost"', false)
            ->assertSee('name="packing_cost"', false)
            ->assertSee('name="repair_cost"', false)
            ->assertSee('name="cleaning_cost"', false)
            ->assertSee('name="other_expense"', false);
        $accountingReview = $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk();
        $this->assertStringContainsString(route('furimadeck-accounting.sales.expenses.edit', $sale), $accountingReview->getContent());

        $expenseEvidence = [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'qualified',
            'date_matches_entry_confirmed' => '1',
        ];
        $sale->marketplace->update(['default_fee_rate' => 10]);

        $response = $this->actingAs($user)->put(route('furimadeck-sales.update', $sale), $this->correction($sale, [
            'sold_price' => 10000,
            'sales_fee' => 9999,
            'shipping_fee' => 750,
            'cost_basis' => 9999,
            'purchase_shipping_cost' => 9999,
            'packing_cost' => 9999,
            'repair_cost' => 9999,
            'cleaning_cost' => 9999,
            'other_expense' => 9999,
            'accounting_expense_details' => array_fill_keys(['packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense'], $expenseEvidence),
        ]));

        $response->assertRedirect(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']));

        $sale = $sale->fresh();
        $this->assertSame(1000, $sale->cost_basis);
        $this->assertSame(0, $sale->purchase_shipping_cost);
        $this->assertSame(1000, $sale->sales_fee);
        $this->assertSame('10.00', (string) $sale->sales_fee_rate);
        $this->assertSame(0, $sale->packing_cost);
        $this->actingAs($user)->put(route('furimadeck-accounting.sales.amounts.update', $sale), [
            'record_version' => $sale->recordVersionToken(),
            'cost_basis' => 3000,
            'purchase_shipping_cost' => 500,
            'packing_cost' => 200,
            'repair_cost' => 100,
            'cleaning_cost' => 50,
            'other_expense' => 20,
        ])->assertRedirect(route('furimadeck-accounting.sales.expenses.edit', $sale));

        $this->assertSame(4380, $sale->fresh()->net_profit);
        $this->assertSame(1, $sale->fresh()->quantity);
        $this->assertNull(data_get($sale->fresh()->accounting_expense_details, 'packing_cost.tax_category'));
        $this->assertSame('review', $entry->fresh()->source_status);
        $this->assertNull($entry->fresh()->confirmed_at);
        $this->assertSame(10000, $entry->fresh()->sale_amount);
        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), ['year' => 2026]);
        $this->assertSame('review', $entry->fresh()->source_status);

        app(FurimaDeckAccountingEntryService::class)->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));
        $this->assertSame(10000, $entry->fresh()->sale_amount);
        $this->assertSame(870, $entry->fresh()->other_cost);
        $this->assertSame(4380, $entry->fresh()->furimadeck_actual_profit);
    }

    public function test_sale_record_ignores_accounting_amounts_and_requires_accounting_review(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-EXPENSE-'.uniqid(),
            'product_name' => '費目別販売テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-expense-'.uniqid(),
            'name' => '費目別販売テスト先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);
        $unknownEvidence = [
            'document_type' => 'unknown',
            'tax_category' => 'unknown',
            'invoice_issuer' => 'unknown',
        ];

        $productCreate = $this->actingAs($user)->get(route('products.create'))->assertOk();
        $productEdit = $this->actingAs($user)->get(route('products.edit', $product))->assertOk();
        $purchaseInfo = $this->actingAs($user)->get(route('furimadeck-accounting.products.purchase-info.edit', $product))->assertOk();
        foreach (['purchase_date', 'purchase_unit_cost', 'purchase_shipping_cost', 'other_purchase_expense', 'supplier_id'] as $field) {
            $productCreate->assertDontSee('name="'.$field.'"', false);
            $productEdit->assertDontSee('name="'.$field.'"', false);
            $purchaseInfo->assertSee('name="'.$field.'"', false);
        }

        $supplier = $user->suppliers()->create([
            'name' => '会計画面だけで選べる仕入先 '.uniqid(),
            'type' => 'online_shop',
        ]);
        $forgedSku = 'ACCOUNTING-ONLY-'.uniqid();
        $this->actingAs($user)->post(route('products.store'), [
            'internal_sku' => $forgedSku,
            'product_name' => '会計項目を含む商品作成リクエスト',
            'condition' => 'used',
            'purchase_date' => '2026-10-01',
            'purchase_unit_cost' => 9999,
            'purchase_quantity' => 9,
            'quantity_available' => 9,
            'purchase_shipping_cost' => 888,
            'other_purchase_expense' => 777,
            'supplier_id' => $supplier->id,
        ])->assertRedirect(route('products.index'));

        $createdProduct = $user->products()->where('internal_sku', $forgedSku)->firstOrFail();
        $this->assertNull($createdProduct->purchase_date);
        $this->assertSame(0, $createdProduct->purchase_unit_cost);
        $this->assertSame(1, $createdProduct->purchase_quantity);
        $this->assertSame(1, $createdProduct->quantity_available);
        $this->assertSame(0, $createdProduct->purchase_shipping_cost);
        $this->assertSame(0, $createdProduct->other_purchase_expense);
        $this->assertNull($createdProduct->supplier_id);

        $this->actingAs($user)->put(route('products.update', $product), [
            'internal_sku' => $product->internal_sku,
            'product_name' => $product->product_name,
            'condition' => $product->condition,
            'purchase_date' => '2026-10-02',
            'purchase_unit_cost' => 9999,
            'purchase_quantity' => 8,
            'quantity_available' => 8,
            'purchase_shipping_cost' => 888,
            'other_purchase_expense' => 777,
            'supplier_id' => $supplier->id,
        ])->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertNull($product->purchase_date);
        $this->assertSame(1000, $product->purchase_unit_cost);
        $this->assertSame(1, $product->purchase_quantity);
        $this->assertSame(1, $product->quantity_available);
        $this->assertSame(0, $product->purchase_shipping_cost);
        $this->assertSame(0, $product->other_purchase_expense);
        $this->assertNull($product->supplier_id);

        $saleCreate = $this->actingAs($user)->get(route('furimadeck-sales.create', ['product_id' => $product->id]))
            ->assertOk();
        $saleCreate
            ->assertSee('name="marketplace_id"', false)
            ->assertSee('name="sold_at"', false)
            ->assertSee('name="sales_fee_rate" min="0" max="100" step="0.01" type="number" value="0" readonly', false)
            ->assertSee('販売手数料（自動計算）')
            ->assertDontSee('仕入原価')
            ->assertDontSee('会計データで管理します')
            ->assertDontSee('name="cost_basis"', false)
            ->assertDontSee('name="purchase_shipping_cost"', false)
            ->assertDontSee('name="packing_cost"', false)
            ->assertDontSee('name="repair_cost"', false)
            ->assertDontSee('name="cleaning_cost"', false)
            ->assertDontSee('name="other_expense"', false)
            ->assertDontSee('販売時の別途費用')
            ->assertDontSee('証憑の税区分')
            ->assertDontSee('適格請求書発行事業者');
        $this->actingAs($user)->post(route('furimadeck-sales.store'), [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'sold_price' => 5000,
            'sold_at' => '2026-10-18',
            'sales_fee_rate' => 99,
            'cost_basis' => 9999,
            'shipping_fee' => 300,
            'purchase_shipping_cost' => 444,
            'packing_cost' => 100,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
            'accounting_expense_details' => ['packing_cost' => $unknownEvidence],
        ])->assertRedirect(route('products.index'));

        $sale = $user->sales()->firstOrFail();
        $this->assertSame(3200, $sale->net_profit);
        $this->assertSame(500, $sale->sales_fee);
        $this->assertSame('10.00', (string) $sale->sales_fee_rate);
        $this->assertSame(1000, $sale->cost_basis);
        $this->assertSame(0, $sale->purchase_shipping_cost);
        $this->assertSame(0, $sale->packing_cost, 'Accounting-only amounts posted to the sales form must be ignored.');
        $this->assertNull(data_get($sale->accounting_expense_details, 'packing_cost.tax_category'));

        $accountingEntry = $user->accountingEntries()->where('sale_id', $sale->id)->firstOrFail();
        $this->assertSame(AccountingEntry::TYPE_SALE, $accountingEntry->entry_type);
        $this->assertSame('ready', $accountingEntry->source_status);
        $this->assertSame('not_synced', $accountingEntry->sync_status);
        $this->assertSame(5000, $accountingEntry->sale_amount);
        $this->assertSame(1000, $accountingEntry->purchase_cost);
        $this->assertSame(500, $accountingEntry->selling_fee);
        $this->assertSame(300, $accountingEntry->shipping_cost);
        $this->assertSame(3200, $accountingEntry->furimadeck_actual_profit);

        $saleEdit = $this->actingAs($user)->get(route('furimadeck-sales.edit', $sale))->assertOk();
        foreach (['cost_basis', 'purchase_shipping_cost', 'packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense'] as $field) {
            $saleCreate->assertDontSee('name="'.$field.'"', false);
            $saleEdit->assertDontSee('name="'.$field.'"', false);
        }

        $this->actingAs($user)->get(route('products.index'))
            ->assertOk()
            ->assertSee('¥5,000')
            ->assertDontSee('仕入原価')
            ->assertDontSee('利益合計');
        $this->actingAs($user)->get(route('furimadeck-sales.index'))
            ->assertOk()
            ->assertSee('¥5,000')
            ->assertDontSee('利益');

        $this->actingAs($user)->put(route('furimadeck-accounting.sales.amounts.update', $sale), [
            'record_version' => $sale->recordVersionToken(),
            'cost_basis' => 1000,
            'purchase_shipping_cost' => 0,
            'packing_cost' => 100,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
        ])->assertRedirect(route('furimadeck-accounting.sales.expenses.edit', $sale));
        $sale->refresh();
        $this->assertSame(3100, $sale->net_profit);
        $this->actingAs($user)->get(route('furimadeck-accounting.sales.expenses.edit', $sale))
            ->assertOk()
            ->assertSee('name="cost_basis"', false)
            ->assertSee('name="purchase_shipping_cost"', false)
            ->assertSee('name="packing_cost"', false)
            ->assertSee('name="repair_cost"', false)
            ->assertSee('name="cleaning_cost"', false)
            ->assertSee('name="other_expense"', false)
            ->assertSee('証憑の税区分')
            ->assertSee('適格請求書発行事業者');
        $this->actingAs($user)->put(route('furimadeck-accounting.sales.expenses.update', $sale), [
            'accounting_expense_details' => ['packing_cost' => $unknownEvidence],
        ])->assertRedirect(route('furimadeck-accounting.sales.expenses.edit', $sale));
        $this->assertSame('unknown', data_get($sale->fresh()->accounting_expense_details, 'packing_cost.tax_category'));
        $this->assertNull(data_get($sale->fresh()->accounting_expense_details, 'packing_cost.reviewed_at'));

        $entry = app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-11-01'),
        )->firstOrFail();
        $this->assertSame($accountingEntry->id, $entry->id, 'Refreshing the period must reuse the automatically created entry.');
        $this->assertSame(1, $user->accountingEntries()->where('sale_id', $sale->id)->count());
        $sourceStatus = $entry->source_status;
        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'reviewed_source' => '1',
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame($sourceStatus, $entry->fresh()->source_status);
        $this->assertNull($entry->fresh()->confirmed_at);
    }

    public function test_sale_cost_total_above_accounting_column_range_is_rejected_without_saving(): void
    {
        [$user, $entry] = $this->entryFixture();
        $sale = $entry->sale;
        $maximumAmount = SaleProfitCalculator::MAX_SIGNED_PROFIT;
        $sale->update([
            'sold_price' => $maximumAmount,
            'cost_basis' => 0,
            'net_profit' => $maximumAmount,
        ]);
        $entry = app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2027-01-01'),
        )->firstOrFail();
        $sale->refresh();

        $this->actingAs($user)->put(route('furimadeck-accounting.sales.amounts.update', $sale), [
            'record_version' => $sale->recordVersionToken(),
            'cost_basis' => 0,
            'purchase_shipping_cost' => 2_500_000_000,
            'packing_cost' => 2_500_000_000,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
        ])->assertRedirect()->assertSessionHasErrors('accounting_amounts');

        $this->assertSame($maximumAmount, $sale->fresh()->sold_price);
        $this->assertSame(0, $sale->fresh()->purchase_shipping_cost);
        $this->assertSame(0, $sale->fresh()->packing_cost);
        $this->assertSame($maximumAmount, $entry->fresh()->sale_amount);
        $this->assertSame(0, $entry->fresh()->other_cost);
    }

    public function test_sale_correction_recalculates_fee_from_selected_marketplace_and_ignores_client_fee(): void
    {
        [$user, $entry] = $this->entryFixture();
        $sale = $entry->sale;
        $previousListing = $user->listings()->create([
            'product_id' => $sale->product_id,
            'marketplace_id' => $sale->marketplace_id,
            'listing_title' => '変更前の出品先',
            'status' => 'sold',
        ]);
        $sale->update(['listing_id' => $previousListing->id]);
        $newMarketplace = Marketplace::query()->create([
            'code' => 'sale-correction-'.uniqid(),
            'name' => '販売記録編集テスト先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 8,
            'is_active' => true,
        ]);

        $this->actingAs($user)->put(route('furimadeck-sales.update', $sale), $this->correction($sale, [
            'marketplace_id' => $newMarketplace->id,
            'sold_price' => 5000,
            'sales_fee' => 1,
        ]))->assertRedirect(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']));

        $sale->refresh();
        $this->assertSame($newMarketplace->id, $sale->marketplace_id);
        $this->assertNull($sale->listing_id, 'A sale must not keep an analytics link to a listing on a different marketplace.');
        $this->assertSame('8.00', (string) $sale->sales_fee_rate);
        $this->assertSame(400, $sale->sales_fee);
        $this->assertSame(3600, $sale->net_profit);
    }

    public function test_unsent_provider_payment_source_can_be_saved_but_sent_mapping_is_immutable(): void
    {
        [$user, $entry] = $this->entryFixture();
        $sale = $entry->sale;
        $sale->update([
            'packing_cost' => 100,
            'net_profit' => 1900,
            'accounting_expense_details' => [
                'packing_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'qualified',
                    'date_matches_entry_confirmed' => true,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ],
        ]);
        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'freee-access-token',
            'refresh_token' => 'freee-refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => [
                'purchase_payment_accounts' => [
                    '8' => ['account_id' => '8', 'name' => '事業用口座'],
                    '9' => ['account_id' => '9', 'name' => '別の事業用口座'],
                ],
            ],
        ]);
        $entry = app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2027-01-01'),
        )->firstOrFail();
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'verified-mf-journal',
        ]);

        $this->actingAs($user)->get(route('furimadeck-sales.edit', $sale))
            ->assertOk()
            ->assertSee('この販売記録は外部へ反映済み')
            ->assertSee($sale->marketplace->name)
            ->assertDontSee('会計データを確認')
            ->assertDontSee('freeeの支払元を保存');
        $this->actingAs($user)->get(route('furimadeck-accounting.sales.expenses.edit', $sale))
            ->assertOk()
            ->assertSee('証憑情報は変更できません')
            ->assertSee('freeeの支払元を保存');
        $this->actingAs($user)->put(route('furimadeck-accounting.sales.expense-sources.update', [$sale, 'freee']), [
            'payment_sources' => ['packing_cost' => '8'],
        ])->assertRedirect(route('furimadeck-accounting.sales.expenses.edit', $sale));
        $this->assertSame('8', data_get($sale->fresh()->accounting_expense_details, 'packing_cost.payment_sources.freee'));

        $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'synced',
            'external_transaction_id' => 'verified-freee-journal',
        ]);
        $this->actingAs($user)->put(route('furimadeck-accounting.sales.expense-sources.update', [$sale, 'freee']), [
            'payment_sources' => ['packing_cost' => '9'],
        ])->assertRedirect()->assertSessionHasErrors('payment_sources');
        $this->assertSame('8', data_get($sale->fresh()->accounting_expense_details, 'packing_cost.payment_sources.freee'));
    }

    public function test_sent_accounting_record_blocks_direct_sale_amount_correction(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'sync_status' => 'synced', 'external_transaction_id' => 'external-test-id']);
        $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'synced',
            'external_transaction_id' => 'external-test-id',
            'request_payload' => ['manual_journal_id' => 'external-test-id'],
        ]);
        $sale = $entry->sale;

        $this->actingAs($user)->get(route('furimadeck-sales.edit', $sale))
            ->assertOk()
            ->assertSee('この販売記録は外部へ反映済み')
            ->assertDontSee('name="sold_price"');

        $this->actingAs($user)->put(route('furimadeck-sales.update', $sale), $this->correction($sale, ['sold_price' => 3001]))
            ->assertRedirect()
            ->assertSessionHasErrors('sold_price');

        $this->actingAs($user)->put(route('furimadeck-accounting.sales.amounts.update', $sale), [
            'record_version' => $sale->recordVersionToken(),
            'cost_basis' => $sale->cost_basis,
            'purchase_shipping_cost' => $sale->purchase_shipping_cost,
            'packing_cost' => $sale->packing_cost,
            'repair_cost' => $sale->repair_cost,
            'cleaning_cost' => $sale->cleaning_cost,
            'other_expense' => $sale->other_expense,
        ])->assertRedirect()->assertSessionHasErrors('accounting_amounts');

        $this->assertSame(3000, $sale->fresh()->sold_price);
        $this->assertSame(2000, $sale->fresh()->net_profit);
        $this->assertSame(3000, $entry->fresh()->sale_amount);
        $this->assertSame('synced', $entry->fresh()->sync_status);
        $this->assertSame('external-test-id', $entry->fresh()->external_transaction_id);
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026]))
            ->assertOk()->assertDontSee('外部会計ソフトとの照合が必要: 1件');
    }

    public function test_uncertain_and_imported_accounting_records_also_block_sale_amount_correction(): void
    {
        foreach ([
            ['status' => 'sync_unknown', 'entry_status' => 'sync_unknown', 'external_transaction_id' => null, 'external_import_id' => null],
            ['status' => 'legacy_unknown', 'entry_status' => 'sync_unknown', 'external_transaction_id' => null, 'external_import_id' => null],
            ['status' => 'csv_imported', 'entry_status' => 'sync_unknown', 'external_transaction_id' => null, 'external_import_id' => 'import-123'],
            ['status' => 'manually_reconciled', 'entry_status' => 'sync_unknown', 'external_transaction_id' => 'manual-123', 'external_import_id' => null],
        ] as $providerState) {
            [$user, $entry] = $this->entryFixture();
            $entry->update(['source_status' => 'confirmed', 'sync_status' => $providerState['entry_status']]);
            $entry->providerSyncs()->create([
                'provider' => 'freee',
                'status' => $providerState['status'],
                'external_transaction_id' => $providerState['external_transaction_id'],
                'external_import_id' => $providerState['external_import_id'],
            ]);
            $sale = $entry->sale;

            $this->actingAs($user)->put(route('furimadeck-sales.update', $sale), $this->correction($sale, ['sold_price' => 3001]))
                ->assertRedirect()
                ->assertSessionHasErrors('sold_price');

            $this->assertSame(3000, $sale->fresh()->sold_price, 'Provider state '.$providerState['status'].' must preserve the sale snapshot.');
            $this->assertSame('3000', (string) $entry->fresh()->sale_amount);
        }
    }

    public function test_sale_correction_rejects_other_users_cancelled_sales_and_stale_versions(): void
    {
        [$owner, $entry] = $this->entryFixture();
        $sale = $entry->sale;
        $other = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $this->actingAs($other)->get(route('furimadeck-sales.edit', $sale))->assertNotFound();
        $this->actingAs($other)->put(route('furimadeck-sales.update', $sale), $this->correction($sale))->assertNotFound();

        $this->actingAs($owner)->put(route('furimadeck-sales.update', $sale), $this->correction($sale, ['record_version' => '2000-01-01 00:00:00']))
            ->assertSessionHasErrors('sold_price');
        $this->assertSame(3000, $sale->fresh()->sold_price);

        $sale->update(['status' => 'cancelled']);
        $this->actingAs($owner)->get(route('furimadeck-sales.edit', $sale))->assertNotFound();
        $this->actingAs($owner)->put(route('furimadeck-sales.update', $sale), $this->correction($sale))
            ->assertSessionHasErrors('sold_price');
        $this->assertSame(3000, $sale->fresh()->sold_price);
    }

    public function test_vendor_csv_refuses_unreviewed_entries_even_if_legacy_export_flag_is_false(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.vendor_journal_export_enabled', false);
        config()->set('furimadeck.accounting.export_accounts', [
            'settlement' => 'settlement', 'sales' => 'sales', 'fees' => 'fees',
            'cost' => 'cost', 'inventory' => 'inventory',
        ]);
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => null]);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), ['year' => 2026])
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), ['year' => 2026])
            ->assertRedirect()->assertSessionHas('error');

        $formulaLikeTitle = " \t=1+1";
        $entry->sale->update(['product_name_snapshot' => $formulaLikeTitle]);
        $entry = app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2027-01-01'),
        )->firstOrFail();

        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026]);
        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026, 'accounting_entry_id' => $entry->id, 'reviewed_source' => '1',
        ])->assertRedirect();
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        $entry->providerSyncs()->create([
            'provider' => 'freee', 'status' => 'failed', 'last_error' => 'http_400_invalid_request_body_value',
        ]);

        config()->set('furimadeck.accounting.oauth.freee.api_base', 'https://api.freee.test');
        config()->set('furimadeck.accounting.freee_sync.api_base', 'https://api.freee.test');
        AccountingConnection::query()->updateOrCreate([
            'user_id' => $user->id,
            'provider' => 'freee',
        ], [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => [
                'company_id' => '123',
                'account_item_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
                'tax_codes' => ['settlement' => '21', 'sales' => '22', 'fees' => '23', 'cost' => '24', 'inventory' => '25'],
            ],
        ]);
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => 'テスト事業所']]]),
            'https://api.freee.test/api/1/account_items/1?company_id=123' => Http::response(['account_item' => ['id' => 1, 'name' => '売掛金', 'categories' => ['資産', '流動資産', '売掛金']]]),
            'https://api.freee.test/api/1/account_items/2?company_id=123' => Http::response(['account_item' => ['id' => 2, 'name' => '売上高', 'categories' => ['損益', '収入金額']]]),
            'https://api.freee.test/api/1/account_items/3?company_id=123' => Http::response(['account_item' => ['id' => 3, 'name' => '支払手数料', 'categories' => ['損益', '経費']]]),
            'https://api.freee.test/api/1/account_items/4?company_id=123' => Http::response(['account_item' => ['id' => 4, 'name' => '売上原価', 'categories' => ['損益', '売上原価']]]),
            'https://api.freee.test/api/1/account_items/5?company_id=123' => Http::response(['account_item' => ['id' => 5, 'name' => '商品', 'categories' => ['資産', '流動資産', '棚卸資産']]]),
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => [
                ['id' => 1, 'name' => '売掛金', 'default_tax_code' => 21],
                ['id' => 2, 'name' => '売上高', 'default_tax_code' => 22],
                ['id' => 3, 'name' => '支払手数料', 'default_tax_code' => 23],
                ['id' => 4, 'name' => '売上原価', 'default_tax_code' => 24],
                ['id' => 5, 'name' => '商品', 'default_tax_code' => 25],
            ]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [
                ['code' => '21', 'name' => 'non_taxable', 'name_ja' => '対象外'], ['code' => '22', 'name' => 'sales_with_tax_10', 'name_ja' => '課税売上10%'],
                ['code' => '23', 'name' => 'purchase_with_tax_10', 'name_ja' => '課対仕入10%'], ['code' => '24', 'name' => 'purchase_with_tax_10', 'name_ja' => '課対仕入10%'],
                ['code' => '25', 'name' => 'non_taxable', 'name_ja' => '対象外'],
            ]]),
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('freee 1件CSV')
            ->assertSee('confirm_all_amounts_tax_inclusive')
            ->assertSee('原資料どおり税込');
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertOk()
            ->assertSee('freee CSV一括出力')
            ->assertSee('confirm_all_amounts_tax_inclusive');

        $csvResponse = $this->actingAs($user)->post(route('furimadeck-accounting.freee'), ['year' => 2026, 'accounting_entry_id' => $entry->id, 'confirm_all_amounts_tax_inclusive' => '1'])
            ->assertOk()
            ->assertDownload()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString('売掛金', $csv);
        $this->assertStringContainsString('課税売上10%', $csv);
        $this->assertStringNotContainsString('settlement,', $csv);
        $freeeLimits = config('furimadeck.accounting.csv_limits.freee');
        $freeeTransactionNumber = $freeeLimits['transaction_number_namespace']
            .str_pad((string) $entry->id, $freeeLimits['transaction_number_width'] - strlen($freeeLimits['transaction_number_namespace']), '0', STR_PAD_LEFT);
        $csvLines = preg_split('/\r\n|\n|\r/', trim(substr($csv, 3)));
        $csvRows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $csvLines);
        $this->assertSame(app(FurimaDeckAccountingVendorCsvService::class)->freeeHeaders(), $csvRows[0]);
        $this->assertSame([
            '[明細行]', '2026/01/10', $freeeTransactionNumber, '売掛金', '3000', '対象外',
            '売上高', '3000', '課税売上10%', "'".$formulaLikeTitle.' 売上',
        ], $csvRows[1]);
        $this->assertSame([
            '[明細行]', '2026/01/10', $freeeTransactionNumber, '売上原価', '1000', '課対仕入10%',
            '商品', '1000', '対象外', "'".$formulaLikeTitle.' 原価',
        ], $csvRows[2]);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/1/manual_journals'));
        $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('freee'));
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('登録なしを記録');
        $freeeConnection = AccountingConnection::query()->where('user_id', $user->id)->where('provider', 'freee')->firstOrFail();
        $this->assertSame(
            app(FurimaDeckAccountingProviderScopeService::class)->fingerprintForConnection($freeeConnection, 'freee'),
            $entry->fresh()->providerSyncs()->where('provider', 'freee')->value('provider_account_scope_hash'),
        );
        $this->assertSame('sync_unknown', $entry->fresh()->sync_status);
        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('money_forward'));
        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), ['year' => 2026, 'accounting_entry_id' => $entry->id])
            ->assertRedirect()->assertSessionHas('error');
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'retry',
        ])->assertSessionHasErrors('verified_absent');
        $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('freee'));
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'retry', 'verified_absent' => '1',
        ])->assertSessionHas('success', '仕訳がないことを記録しました。API送信は行っていません。必要なら会計ソフト用CSVを再出力してください。');
        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('freee'));
        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])
            ->assertOk();
        $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('freee'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/1/manual_journals'));

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), ['year' => 2026, 'accounting_entry_id' => 999999])
            ->assertRedirect()->assertSessionHas('error');
        [, $otherEntry] = $this->entryFixture();
        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), ['year' => 2026, 'accounting_entry_id' => $otherEntry->id])
            ->assertRedirect()->assertSessionHas('error');

        $entry->providerSyncs()->where('provider', 'freee')->update([
            'status' => 'synced', 'external_transaction_id' => 'external-123',
        ]);
        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), ['year' => 2026])
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_freee_csv_requires_tax_inclusive_confirmation_before_export_or_pending_state(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldNotReceive('forEntries');
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertOk()
            ->assertDontSee('freee CSV一括出力')
            ->assertSee('freeeの仕訳設定が不足しているため、一括CSVを出力できません。');

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
        ])->assertRedirect()->assertSessionHas('error', fn (string $message): bool => str_contains($message, '税込') && str_contains($message, 'CSVは出力していません'));

        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertDatabaseMissing('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
        ], 'furimadeck');
    }

    public function test_provider_csv_mapping_failure_does_not_mark_entry_pending_or_send_api_request(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        Http::fake();

        foreach ([
            [
                'provider' => 'freee',
                'route' => 'furimadeck-accounting.freee',
                'setup' => FurimaDeckFreeeAccountingSetupService::class,
                'message' => 'freeeの仕訳設定が不足しています: settlement',
                'confirmation' => ['confirm_all_amounts_tax_inclusive' => '1'],
            ],
            [
                'provider' => 'money_forward',
                'route' => 'furimadeck-accounting.money-forward',
                'setup' => FurimaDeckMoneyForwardAccountingSetupService::class,
                'message' => 'Money Forwardの仕訳設定が不足しています: settlement',
                'confirmation' => [],
            ],
        ] as $case) {
            $this->reconcilePurchaseForProvider($user, $entry, $case['provider']);
            $connection = $user->accountingConnections()->where('provider', $case['provider'])->firstOrFail();

            $setup = Mockery::mock($case['setup']);
            if ($case['provider'] === 'freee') {
                $setup->shouldReceive('options')->once()->andReturn([
                    'connection' => $connection,
                    'account_items' => [],
                    'tax_codes' => [],
                ]);
                $setup->shouldReceive('accountItemDetails')->once()->andReturn([]);
            } else {
                $connection->update(['settings' => [
                    'business_tax_periods' => [[
                        'from' => '2026-01-01',
                        'to' => '2026-12-31',
                        'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
                    ]],
                    'account_ids' => [],
                    'sub_account_ids' => [],
                    'tax_ids' => [],
                ]]);
                $setup->shouldReceive('connection')->once()->with($user)->andReturn($connection);
                $setup->shouldReceive('assertCurrentConfiguration')->once()->andReturn([]);
                $setup->shouldReceive('options')->once()->andReturn([
                    'accounts' => ['accounts' => []],
                    'taxes' => ['taxes' => []],
                ]);
            }
            $this->app->instance($case['setup'], $setup);

            $this->actingAs($user)->post(route($case['route']), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
                ...$case['confirmation'],
            ])->assertRedirect()->assertSessionHas('error', $case['message']);

            $this->assertDatabaseMissing('accounting_entry_syncs', [
                'accounting_entry_id' => $entry->id,
                'provider' => $case['provider'],
            ], 'furimadeck');
            $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus($case['provider']));
        }

        Http::assertNothingSent();
    }

    public function test_provider_master_connection_failure_does_not_mark_entry_pending_or_expose_transport_error(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        Http::fake();

        foreach ([
            [
                'provider' => 'freee',
                'route' => 'furimadeck-accounting.freee',
                'mapping' => FurimaDeckFreeeCsvMappingService::class,
                'method' => 'forEntries',
                'confirmation' => ['confirm_all_amounts_tax_inclusive' => '1'],
            ],
            [
                'provider' => 'money_forward',
                'route' => 'furimadeck-accounting.money-forward',
                'mapping' => FurimaDeckMoneyForwardCsvMappingService::class,
                'method' => 'forUser',
                'confirmation' => [],
            ],
        ] as $case) {
            $this->reconcilePurchaseForProvider($user, $entry, $case['provider']);

            $mapping = Mockery::mock($case['mapping']);
            $mapping->shouldReceive($case['method'])
                ->once()
                ->andThrow(new ConnectionException('private transport diagnostic'));
            $this->app->instance($case['mapping'], $mapping);

            $this->actingAs($user)->post(route($case['route']), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
                ...$case['confirmation'],
            ])->assertRedirect()->assertSessionHas(
                'error',
                '会計ソフトと通信できませんでした。仕訳CSVは出力していません。',
            );

            $this->assertDatabaseMissing('accounting_entry_syncs', [
                'accounting_entry_id' => $entry->id,
                'provider' => $case['provider'],
            ], 'furimadeck');
            $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus($case['provider']));
        }

        Http::assertNothingSent();
    }

    public function test_csv_pending_cannot_be_released_after_provider_account_changes(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $connection = $this->ensureProviderConnection($user, 'freee');
        $scopeHash = app(FurimaDeckAccountingProviderScopeService::class)
            ->fingerprintForConnection($connection, 'freee');
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'csv_pending',
            'provider_account_scope_hash' => $scopeHash,
        ]);
        $this->changeProviderAccountScope($connection, 'freee');

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'retry',
            'verified_absent' => '1',
        ])->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'CSV出力時と同じ会計事業者'));

        $this->assertSame('csv_pending', $sync->fresh()->status);
        $this->assertSame($scopeHash, $sync->fresh()->provider_account_scope_hash);
    }

    public function test_csv_pending_can_be_released_after_absence_is_confirmed_for_the_same_provider_account(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        Http::fake();

        foreach (['freee', 'money_forward'] as $provider) {
            $connection = $this->ensureProviderConnection($user, $provider);
            $scopeHash = app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, $provider);
            $sync = $entry->providerSyncs()->create([
                'provider' => $provider,
                'status' => 'csv_pending',
                'provider_account_scope_hash' => $scopeHash,
            ]);

            $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
                ->assertOk()
                ->assertSee('CSVを受け取れたか、取込が完了したか不明でも、すぐ再出力しないでください。')
                ->assertSee('登録済みなら「CSV取込済みを記録」');

            $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, $provider]), [
                'action' => 'retry',
                'verified_absent' => '1',
            ])->assertSessionHas(
                'success',
                '仕訳がないことを記録しました。API送信は行っていません。必要なら会計ソフト用CSVを再出力してください。',
            );

            $this->assertSame('not_synced', $sync->fresh()->status);
            $this->assertSame($scopeHash, $sync->fresh()->provider_account_scope_hash);
            $this->assertNull($sync->fresh()->external_transaction_id);
            $this->assertNull($sync->fresh()->external_import_id);
        }

        Http::assertNothingSent();
    }

    public function test_vendor_csv_rechecks_sale_status_after_mapping_before_marking_csv_pending(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        $sale = $entry->sale;
        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldReceive('forEntries')->once()->andReturnUsing(
            function (User $mappingUser, Collection $entries) use ($sale): array {
                $this->assertCount(1, $entries);
                app(SaleLifecycleService::class)->cancel($mappingUser, $sale, 'CSV出力中の競合テスト');

                return [
                    'accounts' => [
                        'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '支払手数料',
                        'cost' => '売上原価', 'inventory' => '商品', 'shipping' => '荷造運賃', 'other' => '雑費',
                    ],
                    'tax_names' => [
                        'settlement' => '対象外', 'sales' => '課税売上10%', 'fees' => '課対仕入10%',
                        'cost' => '課対仕入10%', 'inventory' => '対象外',
                    ],
                ];
            },
        );
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertNull($entry->providerSyncs()->where('provider', 'freee')->first());
    }

    public function test_vendor_csv_stops_if_accounting_mapping_settings_change_while_mapping_is_loaded(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        $connection = $user->accountingConnections()->where('provider', 'freee')->firstOrFail();
        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldReceive('forEntries')->once()->andReturnUsing(
            function (User $mappingUser, Collection $entries) use ($connection): array {
                $this->assertCount(1, $entries);
                $settings = (array) $connection->fresh()->settings;
                $settings['tax_codes'] = ['sales' => 'changed-during-export'];
                $connection->update(['settings' => $settings]);

                return [
                    'accounts' => [
                        'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '支払手数料',
                        'cost' => '売上原価', 'inventory' => '商品', 'shipping' => '荷造運賃', 'other' => '雑費',
                    ],
                    'tax_names' => [
                        'settlement' => '対象外', 'sales' => '課税売上10%', 'fees' => '課対仕入10%',
                        'cost' => '課対仕入10%', 'inventory' => '対象外',
                    ],
                ];
            },
        );
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertRedirect()->assertSessionHas('error', fn (string $error): bool => str_contains($error, '勘定科目・税区分設定が変更'));

        $this->assertNull($entry->providerSyncs()->where('provider', 'freee')->first());
    }

    public function test_freee_csv_row_limit_is_checked_before_marking_entry_pending(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        config()->set('furimadeck.accounting.csv_limits.freee.max_rows', 2);
        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldReceive('forEntries')->once()->andReturn([
            'accounts' => [
                'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '支払手数料',
                'cost' => '売上原価', 'inventory' => '商品', 'shipping' => '荷造運賃', 'other' => '雑費',
            ],
            'tax_names' => [
                'settlement' => '対象外', 'sales' => '課税売上10%', 'fees' => '課対仕入10%',
                'cost' => '課対仕入10%', 'inventory' => '対象外',
            ],
        ]);
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertRedirect()->assertSessionHas('error', fn (string $error): bool => str_contains($error, '行数上限'));

        $this->assertNull($entry->providerSyncs()->where('provider', 'freee')->first());
    }

    public function test_freee_csv_file_size_limit_is_checked_before_marking_entry_pending(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        config()->set('furimadeck.accounting.csv_limits.freee.max_bytes', 1);
        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldReceive('forEntries')->once()->andReturn([
            'accounts' => [
                'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '支払手数料',
                'cost' => '売上原価', 'inventory' => '商品', 'shipping' => '荷造運賃', 'other' => '雑費',
            ],
            'tax_names' => [
                'settlement' => '対象外', 'sales' => '課税売上10%', 'fees' => '課対仕入10%',
                'cost' => '課対仕入10%', 'inventory' => '対象外',
            ],
        ]);
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertRedirect()->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'ファイルサイズ上限'));

        $this->assertNull($entry->providerSyncs()->where('provider', 'freee')->first());
    }

    public function test_freee_csv_formula_sanitization_limit_failure_does_not_mark_entry_pending(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        config()->set('furimadeck.accounting.csv_limits.freee.max_description_characters', 12);
        $formulaLikeTitle = '='.str_repeat('a', 8);
        $entry->sale->update(['product_name_snapshot' => $formulaLikeTitle]);
        $entry->update(['title' => $formulaLikeTitle]);

        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldReceive('forEntries')->once()->andReturn([
            'accounts' => [
                'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '支払手数料',
                'cost' => '売上原価', 'inventory' => '商品', 'shipping' => '荷造運賃', 'other' => '雑費',
            ],
            'tax_names' => [
                'settlement' => '対象外', 'sales' => '課税売上10%', 'fees' => '課対仕入10%',
                'cost' => '課対仕入10%', 'inventory' => '対象外',
            ],
        ]);
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertRedirect()->assertSessionHas('error', fn (string $error): bool => str_contains($error, '数式対策後'));

        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertDatabaseMissing('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
        ], 'furimadeck');
    }

    public function test_freee_csv_formula_sanitization_limit_failure_does_not_mark_any_period_entry_pending(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->sale->update(['product_name_snapshot' => 'OK']);
        $entry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstWhere('sale_id', $entry->sale_id);
        $this->assertNotNull($entry);
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');

        $secondProduct = $user->products()->create([
            'internal_sku' => 'WORKFLOW-'.uniqid(),
            'product_name' => '2件目の会計ワークフロー商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $secondSale = $user->sales()->create([
            'product_id' => $secondProduct->id,
            'marketplace_id' => $entry->sale->marketplace_id,
            'internal_sku_snapshot' => $secondProduct->internal_sku,
            'product_name_snapshot' => $secondProduct->product_name,
            'quantity' => 1,
            'sold_price' => 3200,
            'sold_at' => '2026-01-11',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 2200,
        ]);
        $secondEntry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstWhere('sale_id', $secondSale->id);
        $this->assertNotNull($secondEntry);
        $secondEntry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $secondEntry, 'freee');

        config()->set('furimadeck.accounting.csv_limits.freee.max_description_characters', 12);
        $formulaLikeTitle = '='.str_repeat('a', 8);
        $secondSale->update(['product_name_snapshot' => $formulaLikeTitle]);
        $secondEntry->update(['title' => $formulaLikeTitle]);

        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldReceive('forEntries')->once()->andReturn([
            'accounts' => [
                'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '支払手数料',
                'cost' => '売上原価', 'inventory' => '商品', 'shipping' => '荷造運賃', 'other' => '雑費',
            ],
            'tax_names' => [
                'settlement' => '対象外', 'sales' => '課税売上10%', 'fees' => '課対仕入10%',
                'cost' => '課対仕入10%', 'inventory' => '対象外',
            ],
        ]);
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertRedirect()->assertSessionHas('error', function (string $error): bool {
            $this->assertStringContainsString('数式対策後', $error);

            return true;
        });

        foreach ([$entry, $secondEntry] as $saleEntry) {
            $this->assertSame('not_synced', $saleEntry->fresh()->providerSyncStatus('freee'));
            $this->assertDatabaseMissing('accounting_entry_syncs', [
                'accounting_entry_id' => $saleEntry->id,
                'provider' => 'freee',
            ], 'furimadeck');
        }
    }

    public function test_money_forward_csv_formula_sanitization_limit_failure_does_not_mark_any_period_entry_pending(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->sale->update(['product_name_snapshot' => 'OK']);
        $entry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstWhere('sale_id', $entry->sale_id);
        $this->assertNotNull($entry);
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');

        $secondProduct = $user->products()->create([
            'internal_sku' => 'WORKFLOW-'.uniqid(),
            'product_name' => '2件目の会計ワークフロー商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $secondSale = $user->sales()->create([
            'product_id' => $secondProduct->id,
            'marketplace_id' => $entry->sale->marketplace_id,
            'internal_sku_snapshot' => $secondProduct->internal_sku,
            'product_name_snapshot' => $secondProduct->product_name,
            'quantity' => 1,
            'sold_price' => 3200,
            'sold_at' => '2026-01-11',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 2200,
        ]);
        $secondEntry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstWhere('sale_id', $secondSale->id);
        $this->assertNotNull($secondEntry);
        $secondEntry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $secondEntry, 'money_forward');

        config()->set('furimadeck.accounting.csv_limits.money_forward.max_description_characters', 12);
        $formulaLikeTitle = '='.str_repeat('a', 8);
        $secondSale->update(['product_name_snapshot' => $formulaLikeTitle]);
        $secondEntry->update(['title' => $formulaLikeTitle]);

        $mapping = Mockery::mock(FurimaDeckMoneyForwardCsvMappingService::class);
        $mapping->shouldReceive('forUser')->once()->andReturn([
            'accounts' => [
                'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '支払手数料',
                'cost' => '売上原価', 'inventory' => '商品',
            ],
            'sub_accounts' => [],
            'tax_names' => [
                'settlement' => '対象外', 'sales' => '課税売上10%', 'fees' => '課対仕入10%',
                'cost' => '課対仕入10%', 'inventory' => '対象外',
            ],
            'invoice_kinds' => array_fill_keys(
                ['settlement', 'sales', 'fees', 'cost', 'inventory'],
                FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED,
            ),
            'business_status_by_date' => [
                '2026/01/10' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
                '2026/01/11' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
            ],
        ]);
        $this->app->instance(FurimaDeckMoneyForwardCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), [
            'year' => 2026,
        ])->assertRedirect()->assertSessionHas('error', function (string $error): bool {
            $this->assertStringContainsString('数式対策後', $error);

            return true;
        });

        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('money_forward'));
        $this->assertSame('not_synced', $secondEntry->fresh()->providerSyncStatus('money_forward'));
        $this->assertDatabaseMissing('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'money_forward',
        ], 'furimadeck');
        $this->assertDatabaseMissing('accounting_entry_syncs', [
            'accounting_entry_id' => $secondEntry->id,
            'provider' => 'money_forward',
        ], 'furimadeck');
    }

    public function test_freee_purchase_csv_is_blocked_by_release_scope_before_mapping_or_state_change(): void
    {
        [$user, $entry] = $this->purchaseEntryFixture('freee');
        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldNotReceive('forEntries');
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertRedirect()->assertSessionHas('error', fn (string $error): bool => str_contains($error, '仕入CSVはFurimaDeck 1.0の対応対象外'));

        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('freee'));
    }

    public function test_money_forward_purchase_csv_is_blocked_by_release_scope_before_mapping_or_state_change(): void
    {
        [$user, $entry] = $this->purchaseEntryFixture('money_forward');
        $mapping = Mockery::mock(FurimaDeckMoneyForwardCsvMappingService::class);
        $mapping->shouldNotReceive('forUser');
        $this->app->instance(FurimaDeckMoneyForwardCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
        ])->assertRedirect()->assertSessionHas('error', fn (string $error): bool => str_contains($error, '仕入CSVはFurimaDeck 1.0の対応対象外'));

        $this->assertNull($entry->providerSyncs()->where('provider', 'money_forward')->first());
    }

    public function test_provider_csv_is_blocked_for_separately_paid_sale_expenses_before_mapping_or_state_change(): void
    {
        foreach (['freee', 'money_forward'] as $provider) {
            [$user, $entry] = $this->entryFixture();
            $entry->update(['other_cost' => 200, 'expense_breakdown' => ['packing_cost' => 200]]);
            $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
            $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
                ->assertOk()
                ->assertSee($providerName.'用CSVで別途支払った販売費用を含む明細はFurimaDeck 1.0の対応対象外です。会計サービス側で別途登録してください。');
            $mapping = $provider === 'freee'
                ? Mockery::mock(FurimaDeckFreeeCsvMappingService::class)
                : Mockery::mock(FurimaDeckMoneyForwardCsvMappingService::class);
            $mapping->shouldNotReceive($provider === 'freee' ? 'forEntries' : 'forUser');
            $mappingClass = $provider === 'freee' ? FurimaDeckFreeeCsvMappingService::class : FurimaDeckMoneyForwardCsvMappingService::class;
            $this->app->instance($mappingClass, $mapping);
            $route = $provider === 'freee' ? 'furimadeck-accounting.freee' : 'furimadeck-accounting.money-forward';
            $payload = ['year' => 2026, 'accounting_entry_id' => $entry->id];
            if ($provider === 'freee') {
                $payload['confirm_all_amounts_tax_inclusive'] = '1';
            }

            $this->actingAs($user)->post(route($route), $payload)
                ->assertRedirect()
                ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '別途支払った販売費用を含む明細'));

            $this->assertNull($entry->providerSyncs()->where('provider', $provider)->first());
        }
    }

    public function test_provider_csv_blocks_unverified_marketplace_fees_before_mapping_or_state_change(): void
    {
        foreach (['freee', 'money_forward'] as $provider) {
            [$user, $entry] = $this->entryFixture();
            $entry->update(['selling_fee' => 150]);
            $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
            $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
                ->assertOk()
                ->assertSee($providerName.'用CSVで販売手数料を含む明細は、実取込の検証が完了していないためFurimaDeck 1.0では出力対象外です。会計サービス側で別途登録してください。')
                ->assertDontSee('freee 1件CSV')
                ->assertDontSee('Money Forward 1件CSV');
            $mapping = $provider === 'freee'
                ? Mockery::mock(FurimaDeckFreeeCsvMappingService::class)
                : Mockery::mock(FurimaDeckMoneyForwardCsvMappingService::class);
            $mapping->shouldNotReceive($provider === 'freee' ? 'forEntries' : 'forUser');
            $mappingClass = $provider === 'freee' ? FurimaDeckFreeeCsvMappingService::class : FurimaDeckMoneyForwardCsvMappingService::class;
            $this->app->instance($mappingClass, $mapping);
            $route = $provider === 'freee' ? 'furimadeck-accounting.freee' : 'furimadeck-accounting.money-forward';
            $payload = ['year' => 2026, 'accounting_entry_id' => $entry->id];
            if ($provider === 'freee') {
                $payload['confirm_all_amounts_tax_inclusive'] = '1';
            }

            $this->actingAs($user)->post(route($route), $payload)
                ->assertRedirect()
                ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '販売手数料を含む明細は、実取込の検証が完了していない'));

            $this->assertNull($entry->providerSyncs()->where('provider', $provider)->first());
        }
    }

    public function test_period_csv_rejects_all_rows_when_a_later_sale_has_unverified_marketplace_fees(): void
    {
        foreach (['freee', 'money_forward'] as $provider) {
            [$user, $firstEntry] = $this->entryFixture();
            $firstEntry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

            $product = $user->products()->create([
                'internal_sku' => 'WORKFLOW-BULK-'.uniqid(),
                'product_name' => '一括出力2件目の商品',
                'condition' => 'used',
                'purchase_unit_cost' => 1000,
                'purchase_quantity' => 1,
                'quantity_available' => 0,
                'inventory_status' => 'out_of_stock',
            ]);
            $secondSale = $user->sales()->create([
                'product_id' => $product->id,
                'marketplace_id' => $firstEntry->sale->marketplace_id,
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => 1,
                'sold_price' => 3000,
                'sold_at' => '2026-02-10',
                'status' => 'completed',
                'cost_basis' => 1000,
                'sales_fee' => 150,
                'net_profit' => 1850,
            ]);
            $secondEntry = app(FurimaDeckAccountingEntryService::class)
                ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
                ->firstWhere('sale_id', $secondSale->id);
            $this->assertNotNull($secondEntry);
            $secondEntry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

            $mapping = $provider === 'freee'
                ? Mockery::mock(FurimaDeckFreeeCsvMappingService::class)
                : Mockery::mock(FurimaDeckMoneyForwardCsvMappingService::class);
            $mapping->shouldNotReceive($provider === 'freee' ? 'forEntries' : 'forUser');
            $mappingClass = $provider === 'freee' ? FurimaDeckFreeeCsvMappingService::class : FurimaDeckMoneyForwardCsvMappingService::class;
            $this->app->instance($mappingClass, $mapping);
            $route = $provider === 'freee' ? 'furimadeck-accounting.freee' : 'furimadeck-accounting.money-forward';
            $payload = ['year' => 2026];
            if ($provider === 'freee') {
                $payload['confirm_all_amounts_tax_inclusive'] = '1';
            }

            $this->actingAs($user)->post(route($route), $payload)
                ->assertRedirect()
                ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '販売手数料を含む明細は、実取込の検証が完了していない'));

            foreach ([$firstEntry, $secondEntry] as $entry) {
                $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus($provider));
                $this->assertNull($entry->providerSyncs()->where('provider', $provider)->first());
            }
        }
    }

    public function test_provider_csv_is_blocked_for_sale_shipping_before_mapping_or_state_change(): void
    {
        foreach (['freee', 'money_forward'] as $provider) {
            [$user, $entry] = $this->entryFixture();
            $entry->update(['shipping_cost' => 300, 'source_status' => 'confirmed', 'confirmed_at' => now()]);
            $this->reconcilePurchaseForProvider($user, $entry, 'freee');
            $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');
            $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
            $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
                ->assertOk()
                ->assertSee('販売送料の支払元が未確認です。売上入金から差し引かれたか別途支払ったかを確認できないため、会計ソフト用CSVは出力していません。')
                ->assertDontSee('販売手数料を含む明細は実取込の検証が未完了です。または販売送料・別途支払費用があるため、'.$providerName.'用CSVの対象外です。'.$providerName.'側で別途登録してください。')
                ->assertDontSee('freee 1件CSV')
                ->assertDontSee('Money Forward 1件CSV');
            $mapping = $provider === 'freee'
                ? Mockery::mock(FurimaDeckFreeeCsvMappingService::class)
                : Mockery::mock(FurimaDeckMoneyForwardCsvMappingService::class);
            $mapping->shouldNotReceive($provider === 'freee' ? 'forEntries' : 'forUser');
            $mappingClass = $provider === 'freee' ? FurimaDeckFreeeCsvMappingService::class : FurimaDeckMoneyForwardCsvMappingService::class;
            $this->app->instance($mappingClass, $mapping);
            $route = $provider === 'freee' ? 'furimadeck-accounting.freee' : 'furimadeck-accounting.money-forward';
            $payload = ['year' => 2026, 'accounting_entry_id' => $entry->id];
            if ($provider === 'freee') {
                $payload['confirm_all_amounts_tax_inclusive'] = '1';
            }

            $this->actingAs($user)->post(route($route), $payload)
                ->assertRedirect()
                ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '販売送料の支払元が未確認'));

            $this->assertNull($entry->providerSyncs()->where('provider', $provider)->first());
        }
    }

    public function test_provider_purchase_csv_is_rejected_before_mapping_or_state_change(): void
    {
        [$user, $entry] = $this->purchaseEntryFixture('freee');
        $mapping = Mockery::mock(FurimaDeckFreeeCsvMappingService::class);
        $mapping->shouldNotReceive('forEntries');
        $this->app->instance(FurimaDeckFreeeCsvMappingService::class, $mapping);

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ])->assertRedirect()->assertSessionHas('error', fn (string $message): bool => str_contains($message, '仕入CSVはFurimaDeck 1.0の対応対象外'));

        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertNull($entry->providerSyncs()->where('provider', 'freee')->first());
    }

    public function test_freee_purchase_csv_mapping_uses_dated_tax_codes_and_product_payment_source(): void
    {
        [$user, $entry] = $this->purchaseEntryFixture('freee');
        config()->set('furimadeck.accounting.oauth.freee.api_base', 'https://api.freee.test');
        config()->set('furimadeck.accounting.freee_sync.api_base', 'https://api.freee.test');
        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => [
                'company_id' => '123',
                'account_item_ids' => ['settlement' => '6', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
                'tax_codes' => ['settlement' => '21', 'sales' => '22', 'fees' => '23', 'cost' => '24', 'inventory' => '25'],
                'purchase_payment_accounts' => ['1' => ['account_id' => '1', 'name' => '普通預金', 'account_category' => '資産']],
            ],
        ]);
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => 'Test', 'display_name' => 'Test']]]),
            'https://api.freee.test/api/1/account_items/1?company_id=123' => Http::response(['account_item' => ['id' => 1, 'name' => '普通預金', 'categories' => ['資産', '流動資産', '現金・預金']]]),
            'https://api.freee.test/api/1/account_items/6?company_id=123' => Http::response(['account_item' => ['id' => 6, 'name' => '売掛金', 'categories' => ['資産', '流動資産', '売掛金']]]),
            'https://api.freee.test/api/1/account_items/2?company_id=123' => Http::response(['account_item' => ['id' => 2, 'name' => '売上高', 'categories' => ['損益', '売上高']]]),
            'https://api.freee.test/api/1/account_items/3?company_id=123' => Http::response(['account_item' => ['id' => 3, 'name' => '支払手数料', 'categories' => ['損益', '販売管理費']]]),
            'https://api.freee.test/api/1/account_items/4?company_id=123' => Http::response(['account_item' => ['id' => 4, 'name' => '売上原価', 'categories' => ['損益', '売上原価']]]),
            'https://api.freee.test/api/1/account_items/5?company_id=123' => Http::response(['account_item' => ['id' => 5, 'name' => '商品', 'categories' => ['資産', '流動資産', '棚卸資産']]]),
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => [
                ['id' => 1, 'name' => '普通預金', 'available' => true, 'default_tax_code' => 21, 'categories' => ['資産', '流動資産']],
                ['id' => 6, 'name' => '売掛金', 'available' => true, 'default_tax_code' => 21, 'categories' => ['資産', '流動資産', '売掛金']],
                ['id' => 2, 'name' => '売上高', 'available' => true, 'default_tax_code' => 22, 'categories' => ['収益']],
                ['id' => 3, 'name' => '支払手数料', 'available' => true, 'default_tax_code' => 23, 'categories' => ['費用']],
                ['id' => 4, 'name' => '売上原価', 'available' => true, 'default_tax_code' => 24, 'categories' => ['費用']],
                ['id' => 5, 'name' => '商品', 'available' => true, 'default_tax_code' => 25, 'categories' => ['資産', '流動資産']],
            ]]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => [
                ['code' => '21', 'name' => 'non_taxable', 'name_ja' => '対象外'],
                ['code' => '22', 'name' => 'sales_with_tax_10', 'name_ja' => '課税売上10%'],
                ['code' => '23', 'name' => 'purchase_with_common_10', 'name_ja' => '共通仕入10%'],
                ['code' => '24', 'name' => 'purchase_with_no_tax_10', 'name_ja' => '非課仕入10%'],
                ['code' => '25', 'name' => 'purchase_with_tax_10', 'name_ja' => '課対仕入10%'],
            ]]),
        ]);

        $mapping = app(FurimaDeckFreeeCsvMappingService::class)->forEntries($user, collect([$entry->fresh()]));

        $this->assertSame('普通預金', $mapping['accounts']['purchase_payment_1']);
        $this->assertSame('課対仕入10%', $mapping['tax_names']['purchase_tax_'.$entry->id.'_purchase_unit_cost']);
        $this->assertSame('対象外', $mapping['tax_names']['purchase_payment_tax_'.$entry->id.'_purchase_unit_cost']);
    }

    public function test_money_forward_purchase_csv_is_blocked_when_payment_side_invoice_kind_is_unrepresentable(): void
    {
        [$user, $entry] = $this->purchaseEntryFixture('money_forward');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('支払元側インボイス区分を正確に表現できません');

        app(FurimaDeckMoneyForwardCsvMappingService::class)->forUser($user, collect([$entry->fresh()]));
    }

    public function test_money_forward_vendor_csv_keeps_sync_state_when_business_status_is_unconfirmed(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'failed',
            'last_error' => 'previous_failure',
        ]);
        AccountingConnection::query()->updateOrCreate([
            'user_id' => $user->id,
            'provider' => 'money_forward',
        ], [
            'external_account_id' => 'tenant-workflow',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => ['business_tax_periods' => [['from' => '2000-01-01', 'to' => '2100-12-31', 'invoice_kind' => 'BUSINESS_TAX_STATUS_UNCONFIRMED']]],
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertDontSee('Money Forward 1件CSV')
            ->assertSee('この取引日に適用するMoney Forward事業者の課税・免税状態が未確認のため、CSVを出力していません。');
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertOk()
            ->assertDontSee('Money Forward CSV一括出力')
            ->assertSee('対象期間の販売データに課税・免税状態が未確認の日付があります。一括CSVを出力できません。');

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.money-forward'), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', '取引日に適用するMoney Forward事業者の課税・免税状態が未確認のため、仕訳CSVを出力していません。');

        $this->assertSame('failed', $sync->fresh()->status);
        $this->assertSame('previous_failure', $sync->fresh()->last_error);
        $this->assertNull($sync->fresh()->attempted_at);
    }

    public function test_money_forward_csv_allows_confirmed_exempt_and_taxable_transactions_together(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'money_forward', '2024-01-01');

        $previousSale = $entry->sale->replicate();
        $previousSale->sold_at = '2025-06-15';
        $previousSale->save();
        $previousEntry = app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2025-01-01'),
            CarbonImmutable::parse('2026-01-01'),
        )->firstOrFail();
        $previousEntry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $roles = ['settlement', 'sales', 'fees', 'cost', 'inventory'];
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $connection->update(['settings' => [
            'account_ids' => ['settlement' => 'a1', 'sales' => 'a2', 'fees' => 'a3', 'cost' => 'a4', 'inventory' => 'a5'],
            'sub_account_ids' => ['settlement' => null, 'sales' => 'sub-sale', 'fees' => null, 'cost' => null, 'inventory' => null],
            'tax_ids' => ['settlement' => 't1', 'sales' => 't2', 'fees' => 't3', 'cost' => 't3', 'inventory' => 't1'],
            'account_statement_types_checked' => true,
            'business_tax_periods' => [
                ['from' => '2025-01-01', 'to' => '2025-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT],
                ['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
            ],
            'invoice_kinds' => array_fill_keys($roles, FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED),
        ]]);

        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => [
                ['id' => 't1', 'name' => '対象外'],
                ['id' => 't2', 'name' => '課税売上10%'],
                ['id' => 't3', 'name' => '課対仕入10%'],
            ]]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'a1', 'name' => '売掛金', 'tax_id' => 't1', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'a2', 'name' => '売上高', 'tax_id' => 't1', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS', 'sub_accounts' => [['id' => 'sub-sale', 'name' => '販売売上', 'tax_id' => 't2']]],
                ['id' => 'a3', 'name' => '支払手数料', 'tax_id' => 't3', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a4', 'name' => '売上原価', 'tax_id' => 't3', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a5', 'name' => '商品', 'tax_id' => 't1', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', [
            'section' => 'csv', 'from' => '2025-01-01', 'to' => '2026-12-31',
        ]))->assertOk()->assertViewHas('moneyForwardCsvBulkExportStatus', 'ready')->assertSee('Money Forward CSV一括出力');

        $csv = $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), [
            'from' => '2025-01-01',
            'to' => '2026-12-31',
        ])->assertOk()->assertDownload()->streamedContent();
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $rows = array_values(array_filter(array_map('str_getcsv', array_slice($lines, 1)), static fn (array $row): bool => count($row) >= 18));
        $exemptRows = array_values(array_filter($rows, static fn (array $row): bool => ($row[1] ?? '') === '2025/06/15'));
        $this->assertCount(2, $exemptRows);
        foreach ($exemptRows as $row) {
            $this->assertSame(['', '', '', ''], [$row[6], $row[7], $row[14], $row[15]]);
        }

        $taxableDate = $entry->fresh()->transaction_date->format('Y/m/d');
        $taxableRows = array_values(array_filter($rows, static fn (array $row): bool => ($row[1] ?? '') === $taxableDate));
        $this->assertCount(2, $taxableRows);
        $this->assertContains('適格', array_column($taxableRows, 7));
        $this->assertContains('適格', array_column($taxableRows, 15));
        $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('money_forward'));
        $this->assertSame('csv_pending', $previousEntry->fresh()->providerSyncStatus('money_forward'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/v3/journals'));
    }

    public function test_money_forward_csv_uses_connected_account_and_tax_names_without_guessing_invoice_classification(): void
    {
        [$user, $entry] = $this->entryFixture();
        $formulaLikeTitle = " \t=1+1";
        $entry->sale->update(['product_name_snapshot' => $formulaLikeTitle]);
        $entry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstWhere('sale_id', $entry->sale_id);
        $this->assertNotNull($entry);
        config()->set('furimadeck.accounting.vendor_journal_export_enabled', false);
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
        $this->actingAs($user)->post(route('furimadeck-accounting.review'), ['year' => 2026]);
        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026, 'accounting_entry_id' => $entry->id, 'reviewed_source' => '1',
        ])->assertRedirect();
        $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');
        $entry->providerSyncs()->create([
            'provider' => 'money_forward', 'status' => 'failed', 'last_error' => 'http_400_invalid_request_body_value',
        ]);

        $connection = AccountingConnection::query()->updateOrCreate([
            'user_id' => $user->id,
            'provider' => 'money_forward',
        ], [
            'external_account_id' => 'tenant-workflow', 'access_token' => 'access-token',
            'refresh_token' => 'refresh-token', 'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => [
                'account_ids' => [
                    'settlement' => 'a1', 'sales' => 'a2', 'fees' => 'a3', 'cost' => 'a4', 'inventory' => 'a5',
                    'shipping' => 'a6', 'other' => 'a7',
                ],
                'sub_account_ids' => [
                    'settlement' => null, 'sales' => 'sub-sale', 'fees' => null, 'cost' => null, 'inventory' => null,
                    'shipping' => null, 'other' => null,
                ],
                'tax_ids' => [
                    'settlement' => 't1', 'sales' => 't2', 'fees' => 't3', 'cost' => 't3', 'inventory' => 't1',
                    'shipping' => 't3', 'other' => 't3',
                ],
                'invoice_kind' => 'INVOICE_KIND_QUALIFIED',
                'business_tax_periods' => [
                    ['from' => '2025-01-01', 'to' => '2025-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED],
                    ['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
                ],
                'invoice_kinds' => array_fill_keys(['settlement', 'sales', 'fees', 'cost', 'inventory'], 'INVOICE_KIND_QUALIFIED'),
                'account_statement_types_checked' => true,
            ],
        ]);
        $this->assertSame(
            FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
            app(FurimaDeckMoneyForwardBusinessPeriodService::class)->statusForDate(
                (array) ($connection->fresh()->settings ?? []),
                '2026-01-10',
            ),
        );
        $this->assertSame(
            ['2026-01-10'],
            $user->accountingEntries()->eligibleForAccounting()
                ->where('entry_type', AccountingEntry::TYPE_SALE)
                ->where('transaction_date', '>=', '2026-01-01')
                ->where('transaction_date', '<', '2027-01-01')
                ->selectRaw('DATE(transaction_date) as transaction_date')
                ->distinct()
                ->orderBy('transaction_date')
                ->toBase()
                ->pluck('transaction_date')
                ->map(static fn (mixed $date): string => (string) $date)
                ->all(),
        );
        $accountMasterReads = 0;
        $taxMasterReads = 0;
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => function () use (&$taxMasterReads) {
                $taxMasterReads++;

                return Http::response(['taxes' => [
                    ['id' => 't1', 'name' => '対象外'], ['id' => 't2', 'name' => '課税売上10%'], ['id' => 't3', 'name' => '課対仕入10%'],
                ]]);
            },
            'https://api-accounting.test/api/v3/accounts*' => function () use (&$accountMasterReads) {
                $accountMasterReads++;

                return Http::response(['accounts' => [
                    ['id' => 'a1', 'name' => '売掛金', 'tax_id' => 't1', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                    ['id' => 'a2', 'name' => '売上高', 'tax_id' => 't1', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS', 'sub_accounts' => [['id' => 'sub-sale', 'name' => '販売売上', 'tax_id' => 't2']]],
                    ['id' => 'a3', 'name' => '支払手数料', 'tax_id' => 't3', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'a4', 'name' => '仕入高', 'tax_id' => 't3', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'a5', 'name' => '商品', 'tax_id' => 't1', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                    ['id' => 'a6', 'name' => '荷造運賃', 'tax_id' => 't3', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'a7', 'name' => '雑費', 'tax_id' => 't3', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ]]);
            },
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('Money Forward 1件CSV');
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertOk()
            ->assertViewHas('moneyForwardCsvBulkExportStatus', 'ready')
            ->assertSee('Money Forward CSV一括出力')
            ->assertSee('「取引入力」→「振替伝票」→「インポート」→「他社会計ソフト仕訳のインポート」')
            ->assertSee('「会計帳簿」→「仕訳帳」→「インポート」→「仕訳帳」')
            ->assertSee('Money Forwardサポート回答（2026/10/26）では、免税事業者は消費税関連機能を利用できず')
            ->assertSee('仕訳CSVの税区分やインボイス欄に値を入力した場合、その内容は仕訳メモとして取り込まれる想定です。')
            ->assertSee('免税確認済みの期間は税区分・インボイス欄を空欄にして出力します。')
            ->assertSee('課税・免税状態が未確認の期間も出力を停止します。')
            ->assertSee('課税状態が確認済みの期間は、Money Forwardの勘定科目税区分と、借方・貸方ごとに保存したインボイス区分を出力します。');

        $accountReadsBeforeExport = $accountMasterReads;
        $taxReadsBeforeExport = $taxMasterReads;
        $csv = $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), ['year' => 2026, 'accounting_entry_id' => $entry->id])
            ->assertOk()->assertDownload()->streamedContent();
        $this->assertSame(1, $accountMasterReads - $accountReadsBeforeExport);
        $this->assertSame(1, $taxMasterReads - $taxReadsBeforeExport);
        $this->assertStringContainsString('売掛金', $csv);
        $this->assertStringContainsString('販売売上', $csv);
        $this->assertStringContainsString('課税売上10%', $csv);
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $firstRow = str_getcsv($lines[1] ?? '');
        $secondRow = str_getcsv($lines[2] ?? '');
        $summaryColumn = array_search('摘要', app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardHeaders(), true);
        $this->assertIsInt($summaryColumn);
        $this->assertSame("'".$formulaLikeTitle.' 売上', $firstRow[$summaryColumn]);
        $this->assertSame("'".$formulaLikeTitle.' 原価', $secondRow[$summaryColumn]);
        $this->assertSame('適格', $firstRow[7]);
        $this->assertSame('適格', $firstRow[15]);
        $this->assertSame('売掛金', $firstRow[2]);
        $this->assertSame('売上高', $firstRow[10]);
        $this->assertSame('仕入高', $secondRow[2]);
        $this->assertSame('商品', $secondRow[10]);
        $this->assertStringNotContainsString('売上原価', $csv);
        $this->assertStringNotContainsString('FD_REVIEW', $csv);
        $this->assertStringContainsString('適格', $csv);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/v3/journals'));
        $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('money_forward'));
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('取込履歴ID（任意）')
            ->assertSee('会計サービス側の取込履歴IDが分からない場合は空欄で構いません。')
            ->assertSee('CSV取込済みを記録');
        $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), ['year' => 2026, 'accounting_entry_id' => $entry->id])
            ->assertRedirect()->assertSessionHas('error');

        $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), ['year' => 2026, 'accounting_entry_id' => 999999])
            ->assertRedirect()->assertSessionHas('error');
        [, $otherEntry] = $this->entryFixture();
        $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), ['year' => 2026, 'accounting_entry_id' => $otherEntry->id])
            ->assertRedirect()->assertSessionHas('error');

        $entry->providerSyncs()->where('provider', 'money_forward')->update(['status' => 'failed']);
        $settings = $connection->settings;
        $settings['invoice_kind'] = 'INVOICE_KIND_NOT_TARGET';
        $connection->update(['settings' => $settings]);
        $csv = $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), [
            'year' => 2026, 'accounting_entry_id' => $entry->id,
        ])->assertOk()->assertDownload()->streamedContent();
        $this->assertStringNotContainsString('FD_REVIEW', $csv);
        $this->assertStringContainsString('適格', $csv);
    }

    public function test_money_forward_csv_allows_exempt_business_without_tax_master(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.vendor_journal_export_enabled', false);
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');
        AccountingConnection::query()->updateOrCreate([
            'user_id' => $user->id,
            'provider' => 'money_forward',
        ], [
            'external_account_id' => 'tenant-workflow',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => [
                'account_ids' => ['settlement' => 'a1', 'sales' => 'a2', 'fees' => 'a3', 'cost' => 'a4', 'inventory' => 'a5'],
                'sub_account_ids' => ['settlement' => null, 'sales' => null, 'fees' => null, 'cost' => null, 'inventory' => null],
                'tax_ids' => ['settlement' => null, 'sales' => null, 'fees' => null, 'cost' => null, 'inventory' => null],
                'invoice_kind' => 'INVOICE_KIND_OMIT',
                'business_tax_periods' => [['from' => '2000-01-01', 'to' => '2100-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT]],
                'invoice_kinds' => array_fill_keys(['settlement', 'sales', 'fees', 'cost', 'inventory'], 'INVOICE_KIND_OMIT'),
                'account_statement_types_checked' => true,
            ],
        ]);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => []]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'a1', 'name' => '売掛金', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'a2', 'name' => '売上高', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a3', 'name' => '支払手数料', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a4', 'name' => '売上原価', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a5', 'name' => '商品', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('Money Forward 1件CSV')
            ->assertSee('免税確認済みの期間です。税区分・インボイス欄は空欄で出力します。');
        $this->actingAs($user)->get(route('furimadeck-accounting.money-forward.setup'))
            ->assertOk()
            ->assertSee('消費税関連の設定・機能は利用できず')
            ->assertSee('仕訳CSVの税区分やインボイス欄に値を入力した場合、その内容は仕訳メモとして取り込まれる想定');
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'csv']))
            ->assertOk()
            ->assertViewHas('moneyForwardCsvBulkExportStatus', 'ready')
            ->assertSee('Money Forward CSV一括出力');

        $csv = $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), [
            'year' => 2026,
        ])->assertOk()->assertDownload()->streamedContent();
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        $rows = array_values(array_filter(array_map('str_getcsv', array_slice($lines, 1)), static fn (array $row): bool => count($row) >= 18));
        $this->assertCount(2, $rows);
        $this->assertSame('FurimaDeck要確認', $rows[0][19]);
        $this->assertSame('', $rows[1][19]);
        foreach ($rows as $row) {
            $this->assertSame(['', '', '', ''], [$row[6], $row[7], $row[14], $row[15]]);
        }
        $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('money_forward'));
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/v3/journals'));
    }

    public function test_vendor_csv_never_exports_failed_sync_with_external_transaction_id(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.vendor_journal_export_enabled', false);
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $this->reconcilePurchaseForProvider($user, $entry, 'freee');
        $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');

        foreach ([
            'freee' => ['furimadeck-accounting.freee', 'external_transaction_id'],
            'money_forward' => ['furimadeck-accounting.money-forward', 'external_import_id'],
        ] as $provider => [$route, $externalIdField]) {
            $entry->providerSyncs()->create([
                'provider' => $provider,
                'status' => 'failed',
            ] + [$externalIdField => 'already-registered']);

            $this->actingAs($user)->post(route($route), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
            ])->assertRedirect()->assertSessionHas('error');

            $sync = $entry->providerSyncs()->where('provider', $provider)->firstOrFail();
            $this->assertSame('failed', $sync->status);
            $this->assertSame('already-registered', $sync->{$externalIdField});
        }
    }

    public function test_csv_import_result_is_recorded_separately_from_api_sync_and_cannot_be_recorded_twice(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $freeeConnection = $this->ensureProviderConnection($user, 'freee');
        $moneyForwardConnection = $this->ensureProviderConnection($user, 'money_forward');
        foreach (['freee' => $freeeConnection, 'money_forward' => $moneyForwardConnection] as $provider => $connection) {
            $entry->providerSyncs()->create([
                'provider' => $provider,
                'status' => 'csv_pending',
                'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                    ->fingerprintForConnection($connection, $provider),
            ]);
        }

        $csvPendingPage = $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSV取込済みを記録')
            ->assertSee('CSVを受け取れたか、取込が完了したか不明でも、すぐ再出力しないでください。')
            ->assertSee('登録済みなら「CSV取込済みを記録」');
        $this->assertSame(2, substr_count($csvPendingPage->getContent(), '登録なしを記録'));

        foreach (['freee' => '11113474', 'money_forward' => null] as $provider => $importId) {
            $payload = [
                'action' => 'mark_csv_imported',
                'import_confirmed' => '1',
            ];
            if ($importId !== null) {
                $payload['external_import_id'] = $importId;
            }
            $connection = $provider === 'freee' ? $freeeConnection : $moneyForwardConnection;
            $exportScopeHash = app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, $provider);
            $entry->providerSyncs()->where('provider', $provider)->update([
                'provider_account_scope_hash' => $exportScopeHash,
            ]);
            $this->changeProviderAccountScope($connection, $provider);
            $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, $provider]), $payload)
                ->assertSessionHas('error');
            $this->assertSame('csv_pending', $entry->providerSyncs()->where('provider', $provider)->value('status'));
            $this->assertSame($exportScopeHash, $entry->providerSyncs()->where('provider', $provider)->value('provider_account_scope_hash'));
            $this->restoreProviderAccountScope($connection, $provider);

            $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, $provider]), $payload)
                ->assertSessionHas('success');

            $sync = $entry->providerSyncs()->where('provider', $provider)->firstOrFail();
            $this->assertSame('csv_imported', $sync->status);
            $this->assertSame(
                $exportScopeHash,
                $sync->provider_account_scope_hash,
            );
            $this->assertSame($importId, $sync->external_import_id);
            $this->assertNull($sync->external_transaction_id);
            $this->assertNull($sync->verified_at);
            $page = $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
                ->assertOk()
                ->assertSee('CSV取込済み・手動確認')
                ->assertDontSee('API照合');
            if ($importId !== null) {
                $page->assertSee($importId);
            }

            $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, $provider]), [
                'action' => 'mark_csv_imported',
                'external_import_id' => $importId,
                'import_confirmed' => '1',
            ])->assertSessionHas('error');
            $this->assertSame($importId, $sync->fresh()->external_import_id);
        }
    }

    public function test_csv_import_result_cannot_be_attributed_without_a_connected_account_scope(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'csv_pending']);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'mark_csv_imported',
            'import_confirmed' => '1',
        ])->assertSessionHas('error');

        $sync = $entry->providerSyncs()->where('provider', 'money_forward')->firstOrFail();
        $this->assertSame('csv_pending', $sync->status);
        $this->assertNull($sync->provider_account_scope_hash);

        $connection = $this->ensureProviderConnection($user, 'money_forward');
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'mark_csv_imported',
            'import_confirmed' => '1',
        ])->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'CSVを再取込しないでください'));
        $this->assertSame('csv_pending', $sync->fresh()->status);
        $this->assertNull($sync->fresh()->provider_account_scope_hash);
    }

    public function test_csv_import_result_does_not_overwrite_an_existing_import_id_on_pending_sync(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $connection = $this->ensureProviderConnection($user, 'freee');
        $existingImportId = 'existing-import-123';
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'csv_pending',
            'external_import_id' => $existingImportId,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'freee'),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'mark_csv_imported',
            'external_import_id' => 'replacement-import-456',
            'import_confirmed' => '1',
        ])->assertSessionHas('error');

        $this->assertSame('csv_pending', $sync->fresh()->status);
        $this->assertSame($existingImportId, $sync->fresh()->external_import_id);
    }

    public function test_purchase_csv_evidence_from_a_different_connected_tenant_does_not_unblock_sale(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $connection = $this->ensureProviderConnection($user, 'money_forward');
        $this->reconcilePurchaseForProvider($user, $entry, 'money_forward');
        $service = app(FurimaDeckAccountingEntryService::class);

        $this->assertArrayNotHasKey(
            $entry->id,
            $service->providerPurchaseReconciliationIssues(collect([$entry->fresh()]), 'money_forward'),
        );

        $connection->update(['external_account_id' => 'a-different-mf-tenant']);
        $issues = $service->providerPurchaseReconciliationIssues(collect([$entry->fresh()]), 'money_forward');

        $this->assertArrayHasKey($entry->id, $issues);
        $this->assertStringContainsString('Money Forward側で未照合', $issues[$entry->id]);
    }

    public function test_purchase_accounting_product_without_a_purchase_entry_remains_held(): void
    {
        [, $entry] = $this->entryFixture();
        $entry->sale->product->update(['purchase_accounting_enabled' => true]);

        $issues = app(FurimaDeckAccountingEntryService::class)
            ->providerPurchaseReconciliationIssues(collect([$entry->fresh()]), 'freee');

        $this->assertArrayHasKey($entry->id, $issues);
        $this->assertStringContainsString('仕入会計明細が見つかりません', $issues[$entry->id]);
        $this->assertStringContainsString('この売上CSVは保留です', $issues[$entry->id]);
    }

    public function test_legacy_unknown_can_be_manually_reconciled_with_a_distinct_audit_record_without_retry(): void
    {
        [$user, $entry] = $this->entryFixture();
        $this->ensureProviderConnection($user, 'freee');
        $entry->update([
            'source_status' => 'confirmed',
            'confirmed_at' => now(),
            'sync_status' => 'sync_unknown',
            'external_transaction_id' => 'legacy-unattributed-id',
        ]);
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'legacy_unknown',
        ]);
        $other = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $manualPayload = [
            'action' => 'manual_reconcile',
            'manual_external_transaction_id' => 'freee-manual-journal-001',
            'manual_transaction_date' => '2026-01-10',
            'manual_debit_total' => 3000,
            'manual_credit_total' => 3000,
            'manual_accounts' => "売掛金\n売上高",
            'manual_tax_classifications' => "対象外\n課税売上10%",
            'confirm_same_entry' => '1',
            'confirm_accounting_service' => '1',
            'confirm_journal_id' => '1',
            'confirm_transaction_date' => '1',
            'confirm_amounts' => '1',
            'confirm_accounts' => '1',
            'confirm_tax_classifications' => '1',
            'confirm_no_retry' => '1',
        ];

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('過去の登録結果を確認できないため')
            ->assertDontSee('旧記録を手動照合')
            ->assertDontSee('freee-manual-journal-001');

        $this->actingAs($other)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), $manualPayload)
            ->assertNotFound();
        $this->assertSame('legacy_unknown', $sync->fresh()->status);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), $manualPayload)
            ->assertSessionHas('success');

        $sync->refresh();
        $entry->refresh();
        $this->assertSame(AccountingEntrySync::STATUS_MANUALLY_RECONCILED, $sync->status);
        $this->assertSame('freee-manual-journal-001', $sync->external_transaction_id);
        $this->assertSame($user->id, $sync->manual_reconciled_by_user_id);
        $this->assertNotNull($sync->manual_reconciled_at);
        $this->assertNull($sync->verified_at);
        $this->assertNull($sync->synced_at);
        $this->assertSame('legacy-unattributed-id', $entry->external_transaction_id);
        $this->assertSame('sync_unknown', $entry->sync_status);
        $this->assertSame(3000, $sync->manual_reconciliation_evidence['debit_total']);
        $this->assertSame("売掛金\n売上高", $sync->manual_reconciliation_evidence['accounts']);
        $this->assertStringNotContainsString('manual_reconciliation_evidence', json_encode($sync->toArray(), JSON_THROW_ON_ERROR));

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('過去の登録確認記録あり')
            ->assertDontSee('freee-manual-journal-001')
            ->assertDontSee('旧記録を手動照合');

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'retry',
            'verified_absent' => '1',
        ])->assertSessionHas('error');
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'mark_synced',
            'external_transaction_id' => 'freee-manual-journal-001',
        ])->assertNotFound();
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'freee']), [
            'action' => 'mark_csv_imported',
            'external_import_id' => 'manual-import-attempt',
            'import_confirmed' => '1',
        ])->assertSessionHas('error');
        $this->assertSame(AccountingEntrySync::STATUS_MANUALLY_RECONCILED, $sync->fresh()->status);

        $entry->sale->update(['sold_price' => 3100, 'net_profit' => 2100]);
        try {
            app(FurimaDeckAccountingEntryService::class)->syncPeriod(
                $user,
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2027-01-01'),
            );
            $this->fail('手動照合済みの外部仕訳がある会計明細は無確認で更新されてはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('送信済みまたは送信結果が未確定', $exception->getMessage());
        }
    }

    public function test_legacy_manual_reconciliation_requires_all_attestations_and_balanced_totals(): void
    {
        [$user, $entry] = $this->entryFixture();
        $this->ensureProviderConnection($user, 'money_forward');
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'legacy_unknown',
        ]);
        $payload = [
            'action' => 'manual_reconcile',
            'manual_external_transaction_id' => 'mf-manual-journal-001',
            'manual_transaction_date' => '2026-01-10',
            'manual_debit_total' => 3000,
            'manual_credit_total' => 3000,
            'manual_accounts' => '売掛金、売上高',
            'manual_tax_classifications' => '対象外、課税売上10%',
            'confirm_same_entry' => '1',
            'confirm_accounting_service' => '1',
            'confirm_journal_id' => '1',
            'confirm_transaction_date' => '1',
            'confirm_amounts' => '1',
            'confirm_accounts' => '1',
            'confirm_tax_classifications' => '1',
            'confirm_no_retry' => '1',
        ];

        $missingAcknowledgement = $payload;
        unset($missingAcknowledgement['confirm_tax_classifications']);
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), $missingAcknowledgement)
            ->assertSessionHasErrors('confirm_tax_classifications');
        $this->assertSame('legacy_unknown', $sync->fresh()->status);

        $unbalanced = array_replace($payload, ['manual_credit_total' => 2999]);
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), $unbalanced)
            ->assertSessionHas('error');
        $this->assertSame('legacy_unknown', $sync->fresh()->status);
        $this->assertNull($sync->fresh()->manual_reconciliation_evidence);
        $this->assertNull($sync->fresh()->external_transaction_id);

        $zeroAmount = array_replace($payload, ['manual_debit_total' => 0, 'manual_credit_total' => 0]);
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), $zeroAmount)
            ->assertSessionHasErrors(['manual_debit_total', 'manual_credit_total']);
        $this->assertSame('legacy_unknown', $sync->fresh()->status);
        $this->assertNull($sync->fresh()->manual_reconciliation_evidence);
        $this->assertNull($sync->fresh()->external_transaction_id);
    }

    public function test_csv_imported_snapshot_cannot_be_silently_overwritten_when_sale_changes(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'csv_imported',
            'external_import_id' => '11113474',
        ]);
        $entry->refreshLegacySyncStatus();
        $entry->sale->update(['sold_price' => 3100, 'net_profit' => 2100]);

        try {
            app(FurimaDeckAccountingEntryService::class)->syncPeriod(
                $user,
                CarbonImmutable::parse('2026-01-01'),
                CarbonImmutable::parse('2027-01-01'),
            );
            $this->fail('CSV取込済みの会計スナップショットは無確認で上書きされてはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('送信済みまたは送信結果が未確定', $exception->getMessage());
        }

        $this->assertSame(3000, $entry->fresh()->sale_amount);
        $this->assertSame('csv_imported', $entry->providerSyncs()->where('provider', 'freee')->value('status'));
        $this->assertSame('11113474', $entry->providerSyncs()->where('provider', 'freee')->value('external_import_id'));
    }

    public function test_cancelled_and_returned_sales_use_adjustment_date_and_never_enter_normal_csv(): void
    {
        [$user, $entry] = $this->entryFixture();
        $cancelled = $entry->sale;
        $cancelled->update(['status' => 'cancelled', 'cancelled_at' => '2026-03-01 10:00:00']);
        $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'synced',
            'external_transaction_id' => 'freee-adjustment-check-123',
            'verified_at' => now(),
        ]);
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => AccountingEntrySync::STATUS_CSV_IMPORTED,
            'external_import_id' => 'mf-adjustment-check-456',
        ]);
        $returned = $cancelled->replicate();
        $returned->fill([
            'status' => 'returned', 'sold_at' => '2025-12-20 12:00:00',
            'returned_at' => '2026-06-10 14:00:00', 'cancelled_at' => null,
            'refund_amount' => 2200, 'return_shipping_fee' => 130,
            'sales_fee' => 300, 'shipping_fee' => 500,
            'purchase_shipping_cost' => 180, 'packing_cost' => 40,
            'repair_cost' => 60, 'cleaning_cost' => 70, 'other_expense' => 90,
            'restocked_quantity' => 1,
        ]);
        $returned->save();
        $later = $cancelled->replicate();
        $later->fill(['sold_at' => '2026-07-01 12:00:00', 'cancelled_at' => '2027-01-01 10:00:00']);
        $later->save();

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()->assertSee('取消・返品の照合: 2件')
            ->assertSee('照合用CSVは会計ソフトへ取り込む仕訳ではありません')
            ->assertSee('見つかった仕訳は削除・上書きせず')
            ->assertSee('取消・返品済みの売上は再登録しません')
            ->assertSee('2,200円')->assertSee('130円')
            ->assertDontSee('2027/01/01');

        $adjustmentCsv = $this->actingAs($user)->get(route('furimadeck-accounting.adjustments.csv', ['year' => 2026]));
        $adjustmentCsv->assertOk()->assertDownload();
        $csv = $adjustmentCsv->streamedContent();
        $this->assertSame(3, count(explode("\n", trim($csv))));
        $this->assertStringContainsString('2026/06/10,返品・返金済み,2025/12/20', $csv);
        $this->assertStringContainsString(',2200,130,', $csv);
        $this->assertStringContainsString(',1000,300,500,180,40,60,70,90,2200,130,1,会計明細なし', $csv);
        $this->assertStringContainsString('API登録・内容照合済み,freee-adjustment-check-123,CSV取込確認済み,', $csv);
        $this->assertStringNotContainsString('2027/01/01', $csv);

        $normalCsv = $this->actingAs($user)->get(route('furimadeck-accounting.csv', ['year' => 2026]));
        $normalCsv->assertOk();
        $this->assertStringNotContainsString('返品・返金済み', $normalCsv->streamedContent());

        $other = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $otherCsv = $this->actingAs($other)->get(route('furimadeck-accounting.adjustments.csv', ['year' => 2026]));
        $otherCsv->assertOk();
        $this->assertStringNotContainsString((string) $returned->internal_sku_snapshot, $otherCsv->streamedContent());
    }

    public function test_repeated_accounting_sync_does_not_duplicate_a_sale_in_common_csv(): void
    {
        [$user, $entry] = $this->entryFixture();
        $service = app(FurimaDeckAccountingEntryService::class);
        $from = CarbonImmutable::parse('2026-01-01');
        $to = CarbonImmutable::parse('2027-01-01');
        $service->syncPeriod($user, $from, $to);
        $service->syncPeriod($user, $from, $to);

        $this->assertSame(1, $user->accountingEntries()->where('sale_id', $entry->sale_id)->count());
        $csv = $this->actingAs($user)->get(route('furimadeck-accounting.csv', ['year' => 2026]))
            ->assertOk()->streamedContent();
        $this->assertSame(2, count(explode("\n", trim($csv))));
        $this->assertSame(1, substr_count($csv, $entry->management_id));
    }

    public function test_common_accounting_csv_preserves_negative_profit_and_escapes_formula_text(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->sale->update([
            'sold_price' => 900,
            'net_profit' => -100,
            'product_name_snapshot' => '=1+1',
        ]);
        app(FurimaDeckAccountingEntryService::class)->syncPeriod(
            $user,
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2027-01-01'),
        );

        $csv = $this->actingAs($user)
            ->get(route('furimadeck-accounting.csv', ['year' => 2026]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(",'=1+1,", $csv);
        $this->assertStringContainsString(',900,1000,0,0,0,-100,not_synced', $csv);
        $this->assertStringNotContainsString(",'-100,", $csv);
    }

    public function test_legacy_cancel_without_event_date_is_not_mislabeled_as_cancelled_on_sale_date(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->sale->update(['status' => 'cancelled', 'cancelled_at' => null]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'review']))
            ->assertOk()->assertSee('取消・返品の照合: 1件')
            ->assertSee('未記録');
        $csv = $this->actingAs($user)->get(route('furimadeck-accounting.adjustments.csv', ['year' => 2026]))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString($entry->sale_id.',,取消済み,2026/01/10', $csv);
    }

    public function test_unreconciled_purchase_notice_links_to_accounting_area_not_product_editor(): void
    {
        [$user, $entry] = $this->entryFixture();
        $product = $entry->sale->product;

        $response = $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSeeText('会計データ内の「仕入記録の手動照合」')
            ->assertSeeText('会計データで仕入・期首在庫を確認');

        $this->assertStringContainsString(
            route('furimadeck-accounting.products.purchase.edit', $product),
            $response->getContent(),
        );
    }

    /** @param array<string, int|string> $overrides @return array<string, int|string> */
    private function correction(Sale $sale, array $overrides = []): array
    {
        return array_replace([
            'record_version' => $sale->recordVersionToken(),
            'sold_at' => $sale->sold_at->format('Y-m-d\TH:i'),
            'marketplace_id' => $sale->marketplace_id,
            'sold_price' => $sale->sold_price,
            'cost_basis' => $sale->cost_basis,
            'sales_fee' => $sale->sales_fee,
            'shipping_fee' => $sale->shipping_fee,
            'purchase_shipping_cost' => $sale->purchase_shipping_cost,
            'packing_cost' => $sale->packing_cost,
            'repair_cost' => $sale->repair_cost,
            'cleaning_cost' => $sale->cleaning_cost,
            'other_expense' => $sale->other_expense,
        ], $overrides);
    }

    /** @return array{0: User, 1: AccountingEntry} */
    private function entryFixture(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create([
            'internal_sku' => 'WORKFLOW-'.uniqid(),
            'product_name' => '会計ワークフロー商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'workflow-'.uniqid(),
            'name' => 'ワークフロー販売先',
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
            'sold_price' => 3000,
            'sold_at' => '2026-01-10',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 2000,
        ]);
        $entry = app(FurimaDeckAccountingEntryService::class)->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))->firstOrFail();

        return [$user, $entry];
    }

    private function reconcilePurchaseForProvider(User $user, AccountingEntry $saleEntry, string $provider, string $purchaseDate = '2026-01-01'): void
    {
        $connection = $this->ensureProviderConnection($user, $provider);
        $product = $saleEntry->sale->product;
        $reviewedAt = now();
        $product->update([
            'purchase_date' => $purchaseDate,
            'purchase_accounting_enabled' => true,
            'purchase_evidence' => [
                'purchase_unit_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'qualified',
                    'date_matches_entry_confirmed' => true,
                    'reviewed_at' => $reviewedAt->toIso8601String(),
                ],
            ],
        ]);
        $purchase = $user->accountingEntries()->firstOrNew([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'product_id' => $product->id,
        ]);
        $purchase->fill([
            'transaction_date' => $purchaseDate,
            'management_id' => $product->internal_sku,
            'title' => $product->product_name,
            'sale_amount' => 0,
            'purchase_cost' => 1000,
            'selling_fee' => 0,
            'shipping_cost' => 0,
            'other_cost' => 0,
            'expense_breakdown' => [
                'purchase_quantity' => 1,
                'purchase_unit_cost' => 1000,
                'purchase_shipping_cost' => 0,
                'other_purchase_expense' => 0,
                'evidence' => [
                    'purchase_unit_cost' => [
                        'document_type' => 'receipt',
                        'tax_category' => 'standard_10',
                        'invoice_issuer' => 'qualified',
                        'date_matches_entry_confirmed' => true,
                        'reviewed_at' => $reviewedAt->toIso8601String(),
                    ],
                ],
            ],
            'furimadeck_actual_profit' => 0,
            'note' => 'FurimaDeck Purchase Product #'.$product->id,
            'source_status' => 'confirmed',
            'sync_status' => 'synced',
            'confirmed_at' => now(),
        ]);
        $purchase->save();
        $purchase->providerSyncs()->updateOrCreate(['provider' => $provider], [
            'status' => AccountingEntrySync::STATUS_CSV_IMPORTED,
            'external_import_id' => 'verified-purchase-'.$provider.'-'.$product->id,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, $provider),
        ]);
    }

    private function ensureProviderConnection(User $user, string $provider): AccountingConnection
    {
        $identity = $provider === 'freee'
            ? ['settings' => ['company_id' => '123']]
            : ['external_account_id' => 'tenant-workflow'];

        return AccountingConnection::query()->firstOrCreate(
            ['user_id' => $user->id, 'provider' => $provider],
            $identity + [
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_at' => now()->addHour(),
                'status' => 'connected',
            ],
        );
    }

    private function changeProviderAccountScope(AccountingConnection $connection, string $provider): void
    {
        if ($provider === 'freee') {
            $settings = (array) $connection->settings;
            $settings['company_id'] = '456';
            $connection->update(['settings' => $settings]);

            return;
        }

        $connection->update(['external_account_id' => 'tenant-workflow-switched']);
    }

    private function restoreProviderAccountScope(AccountingConnection $connection, string $provider): void
    {
        if ($provider === 'freee') {
            $settings = (array) $connection->settings;
            $settings['company_id'] = '123';
            $connection->update(['settings' => $settings]);

            return;
        }

        $connection->update(['external_account_id' => 'tenant-workflow']);
    }

    /** @return array{0: User, 1: AccountingEntry, 2: Product} */
    private function purchaseEntryFixture(string $provider): array
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-CSV-'.uniqid(),
            'product_name' => '仕入CSVテスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-01-10',
            'purchase_accounting_enabled' => true,
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_payment_sources' => [$provider => $provider === 'freee' ? '1' : 'bank-source'],
            'purchase_evidence' => [
                'purchase_unit_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'qualified',
                    'date_matches_entry_confirmed' => true,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ],
        ]);
        $entry = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product);
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

        return [$user, $entry, $product];
    }
}
