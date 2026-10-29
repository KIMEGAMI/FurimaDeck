<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckAccountingPurchaseReconciliationService;
use App\Services\FurimaDeckAccountingVendorCsvService;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use App\Services\FurimaDeckMoneyForwardAccountingSyncService;
use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckManualProductPurchaseReconciliationTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_purchase_screen_discloses_provider_csv_limit_and_follow_up_sale_hold(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'PURCHASE-CSV-LIMIT');

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertOk()
            ->assertSeeText('仕入CSVは1.0の対応対象外です')
            ->assertSeeText('ここで仕入会計データを保存しても、freee・Money Forwardには登録されません')
            ->assertSeeText('新しい仕入の場合は、まずここに仕入情報と証憑を保存し');

        $product = $this->newPurchaseAccountingProduct($user, 'PURCHASE-CSV-LIMIT-NEW');

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertOk()
            ->assertSeeText('この仕入は会計サービスへ登録されていません')
            ->assertSeeText('「仕入記録の手動照合」で仕訳ID・日付・金額・勘定科目・費用ごとの税区分を照合してください')
            ->assertSeeText('手動照合の証跡を保存');
    }

    public function test_recent_purchase_can_be_manually_reconciled_without_creating_internal_purchase_entry(): void
    {
        Http::fake();
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'RECENT-MANUAL-001');
        $purchaseDate = now()->subDay()->toDateString();
        $product->update(['purchase_date' => $purchaseDate]);
        $this->connectFreee($user);
        $payload = $this->validPayload();
        $payload['transaction_date'] = $purchaseDate;

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $payload)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(FurimaDeckAccountingPurchaseReconciliationService::class)
            ->isCurrent($product->manualPurchaseReconciliations()->where('provider', 'freee')->firstOrFail(), $product->refresh(), 'freee'));
        $this->assertSame(0, AccountingEntry::query()
            ->where('product_id', $product->id)
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->count());
        Http::assertNothingSent();
    }

    public function test_new_purchase_accounting_entry_can_be_manually_reconciled_without_external_send(): void
    {
        Http::fake();
        $user = $this->premiumUser();
        $product = $this->newPurchaseAccountingProduct($user, 'NEW-PURCHASE-MANUAL-001');
        $saleEntry = $this->saleEntry($user, $product);
        $this->connectFreee($user);

        $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)
            ->providerPurchaseReconciliationIssues(collect([$saleEntry]), 'freee'));

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $this->assertSame([], app(FurimaDeckAccountingEntryService::class)
            ->providerPurchaseReconciliationIssues(collect([$saleEntry->fresh()]), 'freee'));
        $this->assertSame(1100, app(FurimaDeckAccountingPurchaseReconciliationService::class)
            ->costOfGoodsSoldForSale($saleEntry->fresh(), 'freee'));
        $this->assertSame(1, AccountingEntry::query()
            ->where('product_id', $product->id)
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->count());
        Http::assertNothingSent();
    }

    public function test_purchase_manual_reconciliation_is_blocked_when_provider_send_state_is_unresolved(): void
    {
        $user = $this->premiumUser();
        $product = $this->newPurchaseAccountingProduct($user, 'NEW-PURCHASE-UNKNOWN-001');
        $purchaseEntry = AccountingEntry::query()
            ->where('product_id', $product->id)
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->firstOrFail();
        $purchaseEntry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'sync_unknown',
        ]);
        $this->connectFreee($user);

        $this->actingAs($user)
            ->from(route('furimadeck-accounting.products.purchase.edit', $product))
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasErrors('purchase_reconciliation');

        $this->assertDatabaseMissing('manual_product_purchase_reconciliations', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'provider' => 'freee',
        ]);
    }

    public function test_unscoped_purchase_entry_id_invalidates_existing_manual_provider_reconciliation(): void
    {
        Http::fake();
        $user = $this->premiumUser();
        $product = $this->newPurchaseAccountingProduct($user, 'NEW-PURCHASE-UNSCOPED-ID');
        $saleEntry = $this->saleEntry($user, $product);
        $this->connectFreee($user);
        $this->recordManualReconciliation($user, $product, 'freee');

        $purchaseEntry = AccountingEntry::query()
            ->where('product_id', $product->id)
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->firstOrFail();
        $purchaseEntry->update(['external_transaction_id' => 'unscoped-existing-id']);

        $issues = app(FurimaDeckAccountingEntryService::class)
            ->providerPurchaseReconciliationIssues(collect([$saleEntry->fresh()]), 'freee');

        $this->assertArrayHasKey($saleEntry->id, $issues);
        Http::assertNothingSent();
    }

    public function test_valid_manual_evidence_is_encrypted_and_unblocks_only_the_matching_provider_sale_check(): void
    {
        Http::fake();
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-001');
        $saleEntry = $this->saleEntry($user, $product);

        $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry]), 'freee'));
        $this->connectFreee($user);

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $reconciliation = $product->manualPurchaseReconciliations()->where('provider', 'freee')->firstOrFail();
        $storedEvidence = DB::connection('furimadeck')
            ->table('manual_product_purchase_reconciliations')
            ->where('id', $reconciliation->id)
            ->value('evidence_history');
        $this->assertStringNotContainsString('journal-001', $storedEvidence);
        $this->assertStringNotContainsString('仕入高', $storedEvidence);
        $this->assertSame('journal-001', $reconciliation->latestEvidence()['external_transaction_id']);
        $this->assertSame('課税仕入10%', $reconciliation->latestEvidence()['expense_evidence']['purchase_unit_cost']['external_tax_classification']);
        $this->assertSame(0, AccountingEntry::query()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertOk()
            ->assertSeeText('仕入記録の手動照合')
            ->assertSeeText('仕入はfreeeまたはMoney Forward側で別途登録し')
            ->assertSeeText('FurimaDeck内に仕入会計明細がある場合も')
            ->assertSeeText('現在の商品情報と手動照合済み')
            ->assertSeeText('journal-001');

        $this->assertSame([], app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry->fresh()]), 'freee'));
        $this->assertSame(1100, app(FurimaDeckAccountingPurchaseReconciliationService::class)
            ->costOfGoodsSoldForSale($saleEntry->fresh(), 'freee'));
        $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry->fresh()]), 'money_forward'));
        Http::assertNothingSent();
    }

    public function test_manual_purchase_expensed_at_purchase_is_not_counted_again_as_sale_cost(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-EXPENSE-TREATMENT');
        $saleEntry = $this->confirmedSaleEntry($user, $product);
        $this->connectFreee($user);
        $payload = $this->validPayload();
        $payload['expense_evidence']['purchase_shipping_cost']['accounting_treatment'] = 'expense';

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $payload)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $this->assertSame(1000, app(FurimaDeckAccountingPurchaseReconciliationService::class)
            ->costOfGoodsSoldForSale($saleEntry->fresh(), 'freee'));
    }

    public function test_freee_api_and_accounting_csv_use_only_manually_confirmed_inventory_cost(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        config()->set('furimadeck.accounting.freee_sync', $this->freeeSettings());
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-API-COST');
        $saleEntry = $this->confirmedSaleEntry($user, $product);
        $this->connectFreee($user);
        $payload = $this->validPayload();
        $payload['expense_evidence']['purchase_shipping_cost']['accounting_treatment'] = 'expense';
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $payload)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $accounts = [
            'settlement' => '売掛金',
            'sales' => '売上高',
            'fees' => '支払手数料',
            'cost' => '売上原価',
            'inventory' => '商品',
        ];
        $csvRows = app(FurimaDeckAccountingVendorCsvService::class)
            ->journalRows(collect([$saleEntry->fresh()]), $accounts, 'freee');
        $this->assertSame(1000, collect($csvRows)->firstWhere('debit_role', 'cost')['debit_amount']);

        Http::fake([
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => $this->freeeAccountItems()]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => $this->freeeTaxes()]),
            'https://api.freee.test/api/1/manual_journals' => Http::response(['manual_journal' => ['id' => 701]], 201),
        ]);

        $externalId = app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $saleEntry->fresh());

        $this->assertSame('701', $externalId);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.freee.test/api/1/manual_journals'
            && collect($request->data()['details'])->where('account_item_id', 4)->where('entry_side', 'debit')->sum('amount') === 1000
            && collect($request->data()['details'])->where('account_item_id', 5)->where('entry_side', 'credit')->sum('amount') === 1000);
    }

    public function test_money_forward_api_uses_only_manually_confirmed_inventory_cost(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api.accounting.test');
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-MF-API-COST');
        $saleEntry = $this->confirmedSaleEntry($user, $product);
        $this->connectMoneyForward($user, taxesOmitted: false);
        $payload = $this->validPayload();
        $payload['expense_evidence']['purchase_shipping_cost']['accounting_treatment'] = 'expense';
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'money_forward']), $payload)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        Http::fake([
            'https://api.accounting.test/api/v3/taxes*' => Http::response(['taxes' => $this->moneyForwardTaxes()]),
            'https://api.accounting.test/api/v3/accounts*' => Http::response(['accounts' => $this->moneyForwardAccounts()]),
            'https://api.accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-journal-701']], 201),
        ]);

        $externalId = app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $saleEntry->fresh());

        $this->assertSame('mf-journal-701', $externalId);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.accounting.test/api/v3/journals'
            && collect(data_get($request->data(), 'journal.branches'))->contains(fn (array $branch): bool => data_get($branch, 'debitor.account_id') === 'mf-cost'
                && data_get($branch, 'debitor.value') === 1000
                && data_get($branch, 'creditor.account_id') === 'mf-inventory'
                && data_get($branch, 'creditor.value') === 1000));
    }

    public function test_manual_reconciliation_requires_a_connected_account_with_an_identifiable_business(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-NO-ACCOUNT');

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertSessionHasErrors('purchase_reconciliation');

        $this->assertSame(0, $product->manualPurchaseReconciliations()->count());
        $this->assertSame(0, DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')->count());

        $this->connectFreee($user)->update(['external_account_id' => null, 'settings' => ['company_id' => null]]);
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertSessionHasErrors('purchase_reconciliation');

        $this->assertSame(0, $product->manualPurchaseReconciliations()->count());
    }

    public function test_manual_reconciliation_becomes_stale_when_the_connected_business_changes(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-ACCOUNT-SWITCH');
        $saleEntry = $this->confirmedSaleEntry($user, $product);
        $connection = $this->connectFreee($user);

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $reconciliation = $product->manualPurchaseReconciliations()->where('provider', 'freee')->firstOrFail();
        $service = app(FurimaDeckAccountingPurchaseReconciliationService::class);
        $this->assertTrue($service->isCurrent($reconciliation, $product, 'freee'));

        $connection->update(['external_account_id' => '123', 'settings' => ['company_id' => '456']]);

        $this->assertFalse($service->isCurrent($reconciliation->fresh(), $product->fresh(), 'freee'));
        $issues = app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry]), 'freee');
        $this->assertStringContainsString('手動照合した会計事業者が現在の接続先と一致しません', $issues[$saleEntry->id]);
        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertOk()
            ->assertSeeText('接続先事業者が変わった');
    }

    public function test_legacy_manual_evidence_without_business_scope_is_not_reused(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-LEGACY-SCOPE');
        $saleEntry = $this->confirmedSaleEntry($user, $product);
        $this->connectFreee($user);
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $reconciliation = $product->manualPurchaseReconciliations()->where('provider', 'freee')->firstOrFail();
        $evidence = $reconciliation->latestEvidence();
        unset($evidence['account_scope_hash']);
        $reconciliation->update(['evidence_history' => [$evidence]]);

        $this->assertFalse(app(FurimaDeckAccountingPurchaseReconciliationService::class)
            ->isCurrent($reconciliation->fresh(), $product->fresh(), 'freee'));
        $issues = app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry]), 'freee');
        $this->assertStringContainsString('手動照合した会計事業者が現在の接続先と一致しません', $issues[$saleEntry->id]);
        $this->assertDatabaseHas('manual_product_purchase_reconciliations', [
            'id' => $reconciliation->id,
            'product_id' => $product->id,
        ], 'furimadeck');
    }

    public function test_legacy_manual_evidence_without_accounting_treatment_stays_blocked(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-LEGACY-TREATMENT');
        $saleEntry = $this->confirmedSaleEntry($user, $product);
        $this->connectFreee($user);

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $reconciliation = $product->manualPurchaseReconciliations()->where('provider', 'freee')->firstOrFail();
        $evidence = $reconciliation->latestEvidence();
        unset($evidence['expense_evidence']['purchase_unit_cost']['accounting_treatment']);
        $reconciliation->update(['evidence_history' => [$evidence]]);

        $service = app(FurimaDeckAccountingPurchaseReconciliationService::class);
        $this->assertFalse($service->isCurrent($reconciliation->fresh(), $product->fresh(), 'freee'));
        $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)
            ->providerPurchaseReconciliationIssues(collect([$saleEntry->fresh()]), 'freee'));

        try {
            $service->costOfGoodsSoldForSale($saleEntry->fresh(), 'freee');
            $this->fail('Legacy evidence without accounting treatment must not enable a cost entry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('手動照合', $exception->getMessage());
        }

        $this->assertDatabaseHas('manual_product_purchase_reconciliations', [
            'id' => $reconciliation->id,
            'product_id' => $product->id,
        ], 'furimadeck');
    }

    public function test_product_with_manual_purchase_reconciliation_cannot_be_deleted(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-DELETE-001');
        $this->connectFreee($user);

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->delete(route('products.destroy', $product), ['confirm_deletion' => '1'])
            ->assertSessionHas('error', '出品・販売・会計履歴がある商品は削除できません。履歴を保護するため、在庫状態を変更してください。');

        $this->assertDatabaseHas('products', ['id' => $product->id], 'furimadeck');
        $this->assertDatabaseHas('manual_product_purchase_reconciliations', [
            'product_id' => $product->id,
            'provider' => 'freee',
        ], 'furimadeck');
    }

    public function test_amount_mismatch_and_unknown_tax_classification_are_rejected_without_saving_evidence(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-002');
        $this->connectFreee($user);
        $amountMismatch = $this->validPayload();
        $amountMismatch['debit_total'] = 1099;

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $amountMismatch)
            ->assertSessionHasErrors('purchase_reconciliation');

        $unknownTax = $this->validPayload();
        $unknownTax['expense_evidence']['purchase_unit_cost']['tax_category'] = 'unknown';
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $unknownTax)
            ->assertSessionHasErrors('expense_evidence.purchase_unit_cost.tax_category');

        $missingProviderTax = $this->validPayload();
        unset($missingProviderTax['expense_evidence']['purchase_unit_cost']['external_tax_classification']);
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $missingProviderTax)
            ->assertSessionHasErrors('expense_evidence.purchase_unit_cost.external_tax_classification');

        $missingAccountingTreatment = $this->validPayload();
        unset($missingAccountingTreatment['expense_evidence']['purchase_unit_cost']['accounting_treatment']);
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $missingAccountingTreatment)
            ->assertSessionHasErrors('expense_evidence.purchase_unit_cost.accounting_treatment');

        $this->assertSame(0, $product->manualPurchaseReconciliations()->count());
    }

    public function test_one_external_purchase_journal_can_be_allocated_across_products_and_blocks_until_balanced(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        config()->set('furimadeck.accounting.freee_sync', $this->freeeSettings());
        Http::preventStrayRequests();
        Http::fake();

        $user = $this->premiumUser();
        $firstProduct = $this->legacyProduct($user, 'MANUAL-003');
        $firstProduct->update(['purchase_unit_cost' => 500]);
        $secondProduct = $this->legacyProduct($user, 'MANUAL-004');
        $secondProduct->update(['purchase_unit_cost' => 400]);
        $saleEntry = $this->confirmedSaleEntry($user, $firstProduct);
        $this->connectFreee($user);

        $editResponse = $this->actingAs($user)->get(route('furimadeck-accounting.products.purchase.edit', $firstProduct))->assertOk();
        $this->assertMatchesRegularExpression('/name="debit_total"[^>]*value=""/', $editResponse->getContent());
        $this->assertMatchesRegularExpression('/name="credit_total"[^>]*value=""/', $editResponse->getContent());

        $singleProductTotal = $this->validPayload();
        $singleProductTotal['debit_total'] = 600;
        $singleProductTotal['credit_total'] = 600;
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$firstProduct, 'freee']), $singleProductTotal)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $firstProduct))
            ->assertSessionHasNoErrors();

        $firstReconciliation = $firstProduct->manualPurchaseReconciliations()->where('provider', 'freee')->firstOrFail();
        $reconciliationService = app(FurimaDeckAccountingPurchaseReconciliationService::class);
        $this->assertTrue($reconciliationService->isCurrent($firstReconciliation, $firstProduct, 'freee'));

        $firstAllocation = $this->validPayload();
        $firstAllocation['debit_total'] = 1100;
        $firstAllocation['credit_total'] = 1100;

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$firstProduct, 'freee']), $firstAllocation)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $firstProduct))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $firstReconciliation = $firstReconciliation->fresh();
        $allocationStatus = $reconciliationService->allocationStatus($firstReconciliation, $firstProduct, 'freee');
        $this->assertSame(['allocated_total' => 600, 'transaction_total' => 1100, 'product_count' => 1, 'is_complete' => false], $allocationStatus);
        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase.edit', $firstProduct))
            ->assertOk()
            ->assertSeeText('配分待ち')
            ->assertSeeText('あと 500円');

        $issues = app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry]), 'freee');
        $this->assertStringContainsString('配分が未完了', $issues[$saleEntry->id]);
        try {
            app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $saleEntry);
            $this->fail('A partially allocated purchase journal must block API sending.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('配分が未完了', $exception->getMessage());
        }
        $this->actingAs($user)
            ->post(route('furimadeck-accounting.freee'), ['year' => 2026, 'accounting_entry_id' => $saleEntry->id])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '配分が未完了'));
        $this->assertSame('not_synced', $saleEntry->fresh()->providerSyncStatus('freee'));
        Http::assertNothingSent();

        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $saleEntry->id,
        ])->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '配分が未完了'));
        $this->assertSame('not_synced', $saleEntry->fresh()->providerSyncStatus('freee'));

        $secondAllocation = $this->validPayload();
        $secondAllocation['external_transaction_id'] = 'journal-001';
        $secondAllocation['debit_total'] = 1100;
        $secondAllocation['credit_total'] = 1100;

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$secondProduct, 'freee']), $secondAllocation)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $secondProduct))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message): bool => str_contains($message, '2商品') && str_contains($message, '一致しました'));

        $this->assertSame(2, DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')->count());
        $this->assertSame(
            ['allocated_total' => 1100, 'transaction_total' => 1100, 'product_count' => 2, 'is_complete' => true],
            $reconciliationService->allocationStatus($firstReconciliation->fresh(), $firstProduct->fresh(), 'freee'),
        );
        $this->assertTrue($reconciliationService->isCurrent($firstReconciliation->fresh(), $firstProduct->fresh(), 'freee'));
        $secondEntry = $this->confirmedSaleEntry($user, $secondProduct);
        $issues = app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry, $secondEntry]), 'freee');
        $this->assertSame([], $issues);
    }

    public function test_purchase_journal_allocation_cannot_exceed_total_or_change_between_products(): void
    {
        $user = $this->premiumUser();
        $firstProduct = $this->legacyProduct($user, 'MANUAL-OVER-001');
        $firstProduct->update(['purchase_unit_cost' => 500]);
        $secondProduct = $this->legacyProduct($user, 'MANUAL-OVER-002');
        $secondProduct->update(['purchase_unit_cost' => 500]);
        $this->connectFreee($user);
        $payload = $this->validPayload();
        $payload['debit_total'] = 1100;
        $payload['credit_total'] = 1100;

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$firstProduct, 'freee']), $payload)
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$secondProduct, 'freee']), $payload)
            ->assertSessionHasErrors('purchase_reconciliation');

        $changedTotal = $payload;
        $changedTotal['debit_total'] = 1200;
        $changedTotal['credit_total'] = 1200;
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$secondProduct, 'freee']), $changedTotal)
            ->assertSessionHasErrors('purchase_reconciliation');

        $this->assertSame(1, DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')->count());
        $this->assertSame(0, $secondProduct->manualPurchaseReconciliations()->count());
    }

    public function test_fully_allocated_purchase_journal_allows_each_product_sale_csv_export(): void
    {
        config()->set('furimadeck.accounting.freee_sync', $this->freeeSettings());
        config()->set('furimadeck.accounting.oauth.freee.api_base', 'https://api.freee.test');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => 'Test', 'display_name' => 'Test']]]),
            'https://api.freee.test/api/1/account_items/1?company_id=123' => Http::response(['account_item' => ['id' => 1, 'name' => '売掛金', 'categories' => ['資産', '流動資産', '売掛金']]]),
            'https://api.freee.test/api/1/account_items/2?company_id=123' => Http::response(['account_item' => ['id' => 2, 'name' => '売上高', 'categories' => ['損益', '収入金額']]]),
            'https://api.freee.test/api/1/account_items/3?company_id=123' => Http::response(['account_item' => ['id' => 3, 'name' => '支払手数料', 'categories' => ['損益', '経費']]]),
            'https://api.freee.test/api/1/account_items/4?company_id=123' => Http::response(['account_item' => ['id' => 4, 'name' => '売上原価', 'categories' => ['損益', '売上原価']]]),
            'https://api.freee.test/api/1/account_items/5?company_id=123' => Http::response(['account_item' => ['id' => 5, 'name' => '商品', 'categories' => ['資産', '流動資産', '棚卸資産']]]),
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => $this->freeeAccountItems(true)]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => $this->freeeTaxes()]),
        ]);

        $user = $this->premiumUser();
        $firstProduct = $this->legacyProduct($user, 'MANUAL-MULTI-CSV-001');
        $firstProduct->update(['purchase_unit_cost' => 500]);
        $secondProduct = $this->legacyProduct($user, 'MANUAL-MULTI-CSV-002');
        $secondProduct->update(['purchase_unit_cost' => 400]);
        $firstEntry = $this->confirmedSaleEntry($user, $firstProduct);
        $secondEntry = $this->confirmedSaleEntry($user, $secondProduct);
        $this->connectFreee($user);

        foreach ([$firstProduct, $secondProduct] as $product) {
            $payload = $this->validPayload();
            $payload['debit_total'] = 1100;
            $payload['credit_total'] = 1100;
            $this->actingAs($user)
                ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $payload)
                ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
                ->assertSessionHasNoErrors();
        }

        foreach ([[$firstProduct, $firstEntry], [$secondProduct, $secondEntry]] as [$product, $entry]) {
            $response = $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
                'confirm_all_amounts_tax_inclusive' => '1',
            ]);

            $response->assertDownload();
            $this->assertStringContainsString($product->product_name.' 売上', $response->streamedContent());
            $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('freee'));
        }
    }

    public function test_changed_product_purchase_snapshot_invalidates_manual_evidence(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-005');
        $saleEntry = $this->saleEntry($user, $product);
        $this->connectFreee($user);

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect();

        $product->update(['purchase_unit_cost' => 1001]);

        $issues = app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry->fresh()]), 'freee');
        $this->assertStringContainsString('再確認', $issues[$saleEntry->id]);
    }

    public function test_changed_purchase_document_classification_invalidates_manual_evidence(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-008');
        $saleEntry = $this->saleEntry($user, $product);
        $this->connectFreee($user);

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect();

        $product->update(['purchase_evidence' => [
            'purchase_unit_cost' => ['document_type' => 'invoice', 'tax_category' => 'standard_10'],
        ]]);

        $issues = app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$saleEntry->fresh()]), 'freee');
        $this->assertStringContainsString('再確認', $issues[$saleEntry->id]);
    }

    public function test_manual_freee_reconciliation_allows_freee_api_send_but_keeps_money_forward_blocked(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        config()->set('furimadeck.accounting.freee_sync', $this->freeeSettings());
        Http::preventStrayRequests();
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => $this->freeeAccountItems()]),
            'https://api.freee.test/api/1/manual_journals' => Http::response(['manual_journal' => ['id' => 'freee-sale-001']], 201),
        ]);

        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-API-FREEE');
        $entry = $this->confirmedSaleEntry($user, $product);
        $this->connectFreee($user);
        $this->recordManualReconciliation($user, $product, 'freee');

        $externalId = app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry->fresh());

        $this->assertSame('freee-sale-001', $externalId);
        $this->assertSame('synced', $entry->fresh()->providerSyncStatus('freee'));
        $this->assertSame('freee-sale-001', $entry->fresh()->providerSync('freee')->external_transaction_id);
        $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$entry->fresh()]), 'money_forward'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.freee.test/api/1/manual_journals');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api-accounting.test'));
    }

    public function test_manual_money_forward_reconciliation_allows_money_forward_api_send_but_keeps_freee_blocked(): void
    {
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
        Http::preventStrayRequests();
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => []]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => $this->moneyForwardAccounts()]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-sale-001']], 201),
        ]);

        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-API-MF');
        $entry = $this->confirmedSaleEntry($user, $product);
        $connection = $this->connectMoneyForward($user);
        $this->recordManualReconciliation($user, $product, 'money_forward');

        $externalId = app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry->fresh());

        $this->assertSame('mf-sale-001', $externalId);
        $this->assertSame('synced', $entry->fresh()->providerSyncStatus('money_forward'));
        $this->assertSame('mf-sale-001', $entry->fresh()->providerSync('money_forward')->external_transaction_id);
        $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$entry->fresh()]), 'freee'));
        $this->assertSame('connected', $connection->fresh()->status);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api-accounting.test/api/v3/journals');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.freee.test'));
    }

    public function test_manual_freee_reconciliation_allows_freee_csv_export_only(): void
    {
        config()->set('furimadeck.accounting.freee_sync', $this->freeeSettings());
        config()->set('furimadeck.accounting.oauth.freee.api_base', 'https://api.freee.test');
        Http::preventStrayRequests();
        Http::fake([
            'https://api.freee.test/api/1/companies' => Http::response(['companies' => [['id' => 123, 'name' => 'Test', 'display_name' => 'Test']]]),
            'https://api.freee.test/api/1/account_items/1?company_id=123' => Http::response(['account_item' => ['id' => 1, 'name' => '売掛金', 'categories' => ['資産', '流動資産', '売掛金']]]),
            'https://api.freee.test/api/1/account_items/2?company_id=123' => Http::response(['account_item' => ['id' => 2, 'name' => '売上高', 'categories' => ['損益', '収入金額']]]),
            'https://api.freee.test/api/1/account_items/3?company_id=123' => Http::response(['account_item' => ['id' => 3, 'name' => '支払手数料', 'categories' => ['損益', '経費']]]),
            'https://api.freee.test/api/1/account_items/4?company_id=123' => Http::response(['account_item' => ['id' => 4, 'name' => '売上原価', 'categories' => ['損益', '売上原価']]]),
            'https://api.freee.test/api/1/account_items/5?company_id=123' => Http::response(['account_item' => ['id' => 5, 'name' => '商品', 'categories' => ['資産', '流動資産', '棚卸資産']]]),
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => $this->freeeAccountItems(true)]),
            'https://api.freee.test/api/1/taxes/companies/123' => Http::response(['taxes' => $this->freeeTaxes()]),
        ]);

        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-CSV-FREEE');
        $entry = $this->confirmedSaleEntry($user, $product);
        $this->connectFreee($user);
        $this->recordManualReconciliation($user, $product, 'freee');

        $response = $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'confirm_all_amounts_tax_inclusive' => '1',
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) session('error'));
        $response->assertDownload();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('手動照合テスト商品 売上', $csv);
        $this->assertStringContainsString('手動照合テスト商品 原価', $csv);
        $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('freee'));
        $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
        ])->assertRedirect()->assertSessionHas('error', fn (string $message): bool => str_contains($message, '仕入れ・期首在庫は未照合'));
        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('money_forward'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api-accounting.test'));
    }

    public function test_manual_money_forward_reconciliation_allows_money_forward_csv_export_only(): void
    {
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
        Http::preventStrayRequests();
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => $this->moneyForwardTaxes()]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => $this->moneyForwardAccounts()]),
        ]);

        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-CSV-MF');
        $entry = $this->confirmedSaleEntry($user, $product);
        $this->connectMoneyForward($user, false);
        $this->recordManualReconciliation($user, $product, 'money_forward');

        $response = $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) session('error'));
        $response->assertDownload();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('手動照合テスト商品 売上', $csv);
        $this->assertStringContainsString('手動照合テスト商品 原価', $csv);
        $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('money_forward'));
        $this->actingAs($user)->post(route('furimadeck-accounting.freee'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
        ])->assertRedirect()->assertSessionHas('error', fn (string $message): bool => str_contains($message, '仕入れ・期首在庫は未照合'));
        $this->assertSame('not_synced', $entry->fresh()->providerSyncStatus('freee'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.freee.test'));
    }

    public function test_fully_allocated_multi_product_money_forward_purchase_journal_allows_sale_csv_exports(): void
    {
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
        Http::preventStrayRequests();
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => $this->moneyForwardTaxes()]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => $this->moneyForwardAccounts()]),
        ]);

        $user = $this->premiumUser();
        $firstProduct = $this->legacyProduct($user, 'MANUAL-MULTI-MF-001');
        $firstProduct->update(['purchase_unit_cost' => 500]);
        $secondProduct = $this->legacyProduct($user, 'MANUAL-MULTI-MF-002');
        $secondProduct->update(['purchase_unit_cost' => 400]);
        $firstEntry = $this->confirmedSaleEntry($user, $firstProduct);
        $secondEntry = $this->confirmedSaleEntry($user, $secondProduct);
        $this->connectMoneyForward($user, false);

        $firstAllocation = $this->validPayload();
        $firstAllocation['debit_total'] = 1100;
        $firstAllocation['credit_total'] = 1100;
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$firstProduct, 'money_forward']), $firstAllocation)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $firstProduct))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.money-forward'), [
                'year' => 2026,
                'accounting_entry_id' => $firstEntry->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '配分が未完了'));
        $this->assertSame('not_synced', $firstEntry->fresh()->providerSyncStatus('money_forward'));

        $secondAllocation = $this->validPayload();
        $secondAllocation['debit_total'] = 1100;
        $secondAllocation['credit_total'] = 1100;
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$secondProduct, 'money_forward']), $secondAllocation)
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $secondProduct))
            ->assertSessionHasNoErrors();

        foreach ([[$firstProduct, $firstEntry], [$secondProduct, $secondEntry]] as [$product, $entry]) {
            $response = $this->actingAs($user)->post(route('furimadeck-accounting.money-forward'), [
                'year' => 2026,
                'accounting_entry_id' => $entry->id,
            ]);

            $response->assertDownload();
            $this->assertStringContainsString($product->product_name.' 売上', $response->streamedContent());
            $this->assertSame('csv_pending', $entry->fresh()->providerSyncStatus('money_forward'));
        }
    }

    public function test_another_users_product_cannot_be_reconciled(): void
    {
        $owner = $this->premiumUser();
        $other = $this->premiumUser();
        $product = $this->legacyProduct($owner, 'MANUAL-006');

        $this->actingAs($other)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertNotFound();

        $this->assertSame(0, $product->manualPurchaseReconciliations()->count());
    }

    public function test_migration_rollback_preserves_existing_manual_evidence(): void
    {
        $user = $this->premiumUser();
        $product = $this->legacyProduct($user, 'MANUAL-007');
        $this->connectFreee($user);

        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, 'freee']), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();

        $migration = require database_path('migrations/furimadeck/2026_10_18_000300_create_manual_product_purchase_reconciliations.php');
        try {
            $migration->down();
            $this->fail('Rollback must not remove existing manual accounting evidence.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Manual purchase reconciliation evidence exists and must be preserved.', $exception->getMessage());
        }

        $columnMigration = require database_path('migrations/furimadeck/2026_10_18_000400_change_manual_purchase_evidence_to_long_text.php');
        try {
            $columnMigration->down();
            $this->fail('Rollback must not convert encrypted evidence back to a JSON column.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Encrypted manual purchase reconciliation evidence must be preserved.', $exception->getMessage());
        }

        $allocationMigration = require database_path('migrations/furimadeck/2026_10_19_000100_allow_multi_product_purchase_reconciliation_allocations.php');
        try {
            $allocationMigration->down();
            $this->fail('Rollback must not remove existing purchase allocation references.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Manual purchase allocation evidence exists and must be preserved.', $exception->getMessage());
        }

        $this->assertTrue(Schema::connection('furimadeck')->hasTable('manual_product_purchase_reconciliations'));
        $this->assertTrue(Schema::connection('furimadeck')->hasTable('manual_product_purchase_reconciliation_refs'));
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
    }

    private function legacyProduct(User $user, string $sku): Product
    {
        return $user->products()->create([
            'internal_sku' => $sku,
            'product_name' => '手動照合テスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-01',
            'purchase_accounting_enabled' => false,
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'purchase_shipping_cost' => 100,
            'other_purchase_expense' => 0,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
    }

    private function newPurchaseAccountingProduct(User $user, string $sku): Product
    {
        $product = $this->legacyProduct($user, $sku);
        $reviewedAt = now()->toIso8601String();
        $expenseEvidence = [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'qualified',
            'date_matches_entry_confirmed' => true,
            'reviewed_at' => $reviewedAt,
        ];
        $product->update([
            'purchase_accounting_enabled' => true,
            'purchase_evidence' => [
                'purchase_unit_cost' => $expenseEvidence,
                'purchase_shipping_cost' => $expenseEvidence,
            ],
        ]);
        app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product->refresh());

        return $product->refresh();
    }

    private function saleEntry(User $user, Product $product): AccountingEntry
    {
        $marketplace = Marketplace::query()->firstOrCreate(
            ['code' => 'manual-reconcile-test'],
            [
                'name' => '手動照合テスト',
                'base_url' => 'https://example.test',
                'fee_type' => 'percentage',
                'default_fee_rate' => 0,
                'is_active' => true,
            ],
        );
        $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => '2026-10-10 12:00:00',
            'status' => 'completed',
            'cost_basis' => 1100,
            'sales_fee' => 0,
            'shipping_fee' => 0,
            'purchase_shipping_cost' => 0,
            'packing_cost' => 0,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
            'net_profit' => 1900,
        ]);

        app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-11'));

        return AccountingEntry::query()
            ->where('user_id', $user->id)
            ->where('entry_type', AccountingEntry::TYPE_SALE)
            ->whereHas('sale', fn ($query) => $query->where('product_id', $product->id))
            ->firstOrFail();
    }

    private function confirmedSaleEntry(User $user, Product $product): AccountingEntry
    {
        $entry = $this->saleEntry($user, $product);
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

        return $entry->fresh();
    }

    private function recordManualReconciliation(User $user, Product $product, string $provider): void
    {
        $this->actingAs($user)
            ->post(route('products.accounting-purchase-reconciliation.store', [$product, $provider]), $this->validPayload())
            ->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertSessionHasNoErrors();
    }

    private function connectFreee(User $user): AccountingConnection
    {
        $settings = $this->freeeSettings();
        unset($settings['api_base']);

        return AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'test-freee-access',
            'refresh_token' => 'test-freee-refresh',
            'expires_at' => now()->addHour(),
            'external_account_id' => '123',
            'status' => 'connected',
            'settings' => $settings,
        ]);
    }

    private function connectMoneyForward(User $user, bool $taxesOmitted = true): AccountingConnection
    {
        $roles = ['settlement', 'sales', 'fees', 'cost', 'inventory'];
        $accountIds = array_combine($roles, ['mf-settlement', 'mf-sales', 'mf-fees', 'mf-cost', 'mf-inventory']);
        $taxIds = $taxesOmitted ? array_fill_keys($roles, null) : array_combine($roles, ['tax-1', 'tax-2', 'tax-3', 'tax-4', 'tax-5']);
        $settings = [
            'account_ids' => $accountIds,
            'sub_account_ids' => array_fill_keys($roles, null),
            'tax_ids' => $taxIds,
            'account_statement_types_checked' => true,
            'invoice_kind' => $taxesOmitted
                ? FurimaDeckMoneyForwardInvoiceKindService::OMIT
                : FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET,
            'business_tax_periods' => [[
                'from' => '2000-01-01',
                'to' => '2100-12-31',
                'invoice_kind' => $taxesOmitted
                    ? FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT
                    : FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
            ]],
            'invoice_kinds' => array_fill_keys(
                [...$roles, 'shipping', 'other'],
                $taxesOmitted
                    ? FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET
                    : FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED,
            ),
        ];

        return AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'money_forward',
            'access_token' => 'test-money-forward-access',
            'refresh_token' => 'test-money-forward-refresh',
            'expires_at' => now()->addHour(),
            'external_account_id' => 'tenant-test-123',
            'status' => 'connected',
            'settings' => $settings,
        ]);
    }

    /** @return array<string, mixed> */
    private function freeeSettings(): array
    {
        return [
            'api_base' => 'https://api.freee.test',
            'company_id' => '123',
            'account_item_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
            'tax_codes' => ['settlement' => '21', 'sales' => '22', 'fees' => '23', 'cost' => '24', 'inventory' => '25'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function freeeAccountItems(bool $includeNames = false): array
    {
        $names = ['売掛金', '売上高', '支払手数料', '売上原価', '商品'];
        $items = [];
        foreach (['1' => '21', '2' => '22', '3' => '23', '4' => '24', '5' => '25'] as $id => $taxCode) {
            $item = ['id' => (int) $id, 'default_tax_code' => (int) $taxCode, 'available' => true];
            if ($includeNames) {
                $item['name'] = $names[(int) $id - 1];
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function freeeTaxes(): array
    {
        $taxes = [];
        foreach (['21', '22', '23', '24', '25'] as $code) {
            $taxes[] = ['code' => (int) $code, 'name' => 'purchase_with_tax_10', 'name_ja' => '課税仕入10%'];
        }

        return $taxes;
    }

    /** @return list<array<string, mixed>> */
    private function moneyForwardAccounts(): array
    {
        return [
            ['id' => 'mf-settlement', 'name' => '売掛金', 'tax_id' => 'tax-1', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ['id' => 'mf-sales', 'name' => '売上高', 'tax_id' => 'tax-2', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
            ['id' => 'mf-fees', 'name' => '支払手数料', 'tax_id' => 'tax-3', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
            ['id' => 'mf-cost', 'name' => '売上原価', 'tax_id' => 'tax-4', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
            ['id' => 'mf-inventory', 'name' => '商品', 'tax_id' => 'tax-5', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
        ];
    }

    /** @return list<array<string, string>> */
    private function moneyForwardTaxes(): array
    {
        return [
            ['id' => 'tax-1', 'name' => '対象外'],
            ['id' => 'tax-2', 'name' => '課税売上10%'],
            ['id' => 'tax-3', 'name' => '課税仕入10%'],
            ['id' => 'tax-4', 'name' => '課税仕入10%'],
            ['id' => 'tax-5', 'name' => '対象外'],
        ];
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'external_transaction_id' => 'journal-001',
            'transaction_date' => '2026-10-01',
            'debit_total' => 1100,
            'credit_total' => 1100,
            'debit_accounts' => '仕入高',
            'credit_accounts' => '普通預金',
            'expense_evidence' => [
                'purchase_unit_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'qualified',
                    'accounting_treatment' => 'inventory',
                    'external_tax_classification' => '課税仕入10%',
                    'date_matches_entry_confirmed' => '1',
                ],
                'purchase_shipping_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'qualified',
                    'accounting_treatment' => 'inventory',
                    'external_tax_classification' => '課税仕入10%',
                    'date_matches_entry_confirmed' => '1',
                ],
            ],
            'confirmations' => [
                'service' => '1',
                'same_purchase' => '1',
                'date' => '1',
                'amount' => '1',
                'accounts_tax' => '1',
                'no_resend' => '1',
            ],
        ];
    }
}
