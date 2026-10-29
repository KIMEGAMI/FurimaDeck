<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\ManualProductPurchaseReconciliation;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckAccountingPurchaseReconciliationService;
use App\Services\SaleLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FurimaDeckMariaDbCsvGateTest extends TestCase
{
    public function test_restore_csv_export_runs_against_mariadb_without_persisting_test_data(): void
    {
        if (env('FURIMADECK_MARIADB_GATE') !== '1') {
            $this->markTestSkipped('MariaDB gate is opt-in.');
        }

        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $schema = Schema::connection('furimadeck');
        $this->assertTrue($schema->hasTable('products'));
        $this->assertTrue($schema->hasTable('listings'));
        $this->assertTrue($schema->hasTable('sales'));
        $this->assertTrue($schema->hasColumn('sales', 'accounting_expense_details'));

        DB::beginTransaction();
        try {
            $marketplace = Marketplace::query()->create([
                'code' => 'mariadb-gate-'.bin2hex(random_bytes(4)),
                'name' => 'MariaDBゲート',
                'base_url' => 'https://example.test',
                'default_fee_rate' => 10,
                'is_active' => true,
            ]);
            $user = User::factory()->create([
                'email_verified_at' => now(),
                'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
                'subscription_status' => 'active',
            ]);
            $product = $user->products()->create([
                'internal_sku' => 'MARIADB-GATE-001',
                'product_name' => 'MariaDB確認商品',
                'condition' => 'used',
                'purchase_unit_cost' => 1200,
                'purchase_quantity' => 1,
                'quantity_available' => 1,
                'inventory_status' => 'listed',
            ]);
            $user->listings()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => $product->product_name,
                'listing_price' => 2500,
                'expected_fee_rate' => 10,
                'shipping_fee' => 210,
                'status' => 'active',
            ]);

            $response = $this->actingAs($user)->get(route('furimadeck-export.restore-spec'));

            $response->assertOk()->assertDownload('furimadeck-restore.csv');
            $this->assertStringContainsString('MARIADB-GATE-001,MariaDB確認商品', $response->streamedContent());
        } finally {
            DB::rollBack();
        }
    }

    public function test_accounting_entries_are_idempotent_and_provider_states_are_separate_on_mariadb(): void
    {
        if (env('FURIMADECK_MARIADB_GATE') !== '1') {
            $this->markTestSkipped('MariaDB gate is opt-in.');
        }

        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->assertTrue(Schema::connection('furimadeck')->hasTable('accounting_entry_syncs'));

        DB::beginTransaction();
        try {
            $user = User::factory()->create();
            $marketplace = Marketplace::query()->create([
                'code' => 'mariadb-accounting-'.bin2hex(random_bytes(4)),
                'name' => 'MariaDB会計ゲート',
                'base_url' => 'https://example.test',
                'fee_type' => 'percentage',
                'default_fee_rate' => 0,
                'is_active' => true,
            ]);
            $product = $user->products()->create([
                'internal_sku' => 'MARIADB-ACCOUNTING-001',
                'product_name' => '会計同期の確認商品',
                'condition' => 'used',
                'purchase_unit_cost' => 1000,
                'purchase_quantity' => 1,
                'quantity_available' => 0,
                'inventory_status' => 'out_of_stock',
            ]);
            $sale = $user->sales()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => 1,
                'sold_price' => 5000,
                'sold_at' => '2026-10-12 12:00:00',
                'status' => 'completed',
                'cost_basis' => 1000,
                'sales_fee' => 500,
                'shipping_fee' => 500,
                'purchase_shipping_cost' => 0,
                'packing_cost' => 100,
                'repair_cost' => 0,
                'cleaning_cost' => 0,
                'other_expense' => 0,
                'accounting_expense_details' => [
                    'packing_cost' => [
                        'document_type' => 'receipt',
                        'tax_category' => 'standard_10',
                        'invoice_issuer' => 'qualified',
                        'reviewed_at' => now()->toIso8601String(),
                        'payment_sources' => ['freee' => '1'],
                    ],
                ],
                'net_profit' => 2900,
            ]);

            $service = app(FurimaDeckAccountingEntryService::class);
            $from = CarbonImmutable::parse('2026-01-01');
            $to = CarbonImmutable::parse('2027-01-01');
            $connection = DB::connection('furimadeck');
            $connection->enableQueryLog();
            $first = $service->syncPeriod($user, $from, $to);
            $second = $service->syncPeriod($user, $from, $to);
            $bulkExportDates = $user->accountingEntries()->eligibleForAccounting()
                ->where('entry_type', AccountingEntry::TYPE_SALE)
                ->where('transaction_date', '>=', $from->toDateString())
                ->where('transaction_date', '<', $to->toDateString())
                ->selectRaw('DATE(transaction_date) as transaction_date')
                ->distinct()
                ->orderBy('transaction_date')
                ->toBase()
                ->pluck('transaction_date')
                ->all();
            $saleLockWasIssued = collect($connection->getQueryLog())->contains(
                fn (array $query): bool => str_contains(strtolower($query['query']), 'from `sales`')
                    && str_contains(strtolower($query['query']), 'for update'),
            );
            $connection->disableQueryLog();
            $entry = $first->firstOrFail();

            $entry->providerSyncs()->create([
                'provider' => 'freee',
                'status' => 'synced',
                'external_transaction_id' => 'freee-mariadb-gate-1',
                'synced_at' => now(),
            ]);
            $entry->providerSyncs()->create([
                'provider' => 'money_forward',
                'status' => 'not_synced',
            ]);

            $this->assertCount(1, $first);
            $this->assertCount(1, $second);
            $this->assertSame(['2026-10-12'], $bulkExportDates);
            $this->assertTrue($saleLockWasIssued);
            $this->assertSame('standard_10', data_get($sale->fresh()->accounting_expense_details, 'packing_cost.tax_category'));
            $this->assertSame('qualified', data_get($entry->fresh()->expense_breakdown, 'sale_expenses.packing_cost.invoice_issuer'));
            $this->assertSame(1, AccountingEntry::query()->where('user_id', $user->id)->count());
            $this->assertSame(2900, $entry->fresh()->furimadeck_actual_profit);
            $this->assertSame('synced', $entry->providerSyncs()->where('provider', 'freee')->value('status'));
            $this->assertSame('not_synced', $entry->providerSyncs()->where('provider', 'money_forward')->value('status'));
            $this->assertSame(2, AccountingEntrySync::query()->where('accounting_entry_id', $entry->id)->count());
        } finally {
            DB::connection('furimadeck')->disableQueryLog();
            DB::rollBack();
        }
    }

    public function test_sale_correction_rolls_back_on_mariadb_when_accounting_entry_refresh_fails(): void
    {
        if (env('FURIMADECK_MARIADB_GATE') !== '1') {
            $this->markTestSkipped('MariaDB gate is opt-in.');
        }

        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        DB::beginTransaction();
        try {
            $user = User::factory()->create();
            $marketplace = Marketplace::query()->create([
                'code' => 'mariadb-rollback-'.bin2hex(random_bytes(4)),
                'name' => 'MariaDB会計更新失敗ゲート',
                'base_url' => 'https://example.test',
                'fee_type' => 'percentage',
                'default_fee_rate' => 0,
                'is_active' => true,
            ]);
            $product = $user->products()->create([
                'internal_sku' => 'MARIADB-ROLLBACK-001',
                'product_name' => 'MariaDB会計更新失敗商品',
                'condition' => 'used',
                'purchase_unit_cost' => 1000,
                'purchase_quantity' => 1,
                'quantity_available' => 1,
                'purchase_info_confirmed' => true,
            ]);
            $sale = app(SaleLifecycleService::class)->record($user, [
                'product_id' => $product->id,
                'quantity' => 1,
                'marketplace_id' => $marketplace->id,
                'sold_price' => 5000,
                'sold_at' => '2026-10-21 12:00:00',
                'cost_basis' => 1000,
                'sales_fee' => 0,
                'shipping_fee' => 100,
                'purchase_shipping_cost' => 0,
                'packing_cost' => 0,
                'repair_cost' => 0,
                'cleaning_cost' => 0,
                'other_expense' => 0,
            ]);
            $sale = $sale->fresh();
            $entry = $user->accountingEntries()->where('sale_id', $sale->id)->firstOrFail();
            $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

            $accountingEntries = \Mockery::mock(FurimaDeckAccountingEntryService::class);
            $accountingEntries->shouldReceive('recordSale')->once()->andThrow(new \RuntimeException('simulated entry failure'));
            $this->app->instance(FurimaDeckAccountingEntryService::class, $accountingEntries);

            try {
                app(SaleLifecycleService::class)->correctAmounts($user, $sale, [
                    'record_version' => $sale->recordVersionToken(),
                    'sold_at' => '2026-10-22 15:30:00',
                    'sold_price' => 6000,
                    'cost_basis' => 1000,
                    'sales_fee' => 0,
                    'shipping_fee' => 200,
                    'purchase_shipping_cost' => 0,
                    'packing_cost' => 0,
                    'repair_cost' => 0,
                    'cleaning_cost' => 0,
                    'other_expense' => 0,
                ]);
                $this->fail('A failed accounting refresh must roll back the sale correction.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('simulated entry failure', $exception->getMessage());
            }

            $this->assertSame(5000, $sale->fresh()->sold_price);
            $this->assertSame('2026-10-21', $sale->fresh()->sold_at->toDateString());
            $this->assertSame(100, $sale->fresh()->shipping_fee);
            $this->assertSame(5000, $entry->fresh()->sale_amount);
            $this->assertSame('confirmed', $entry->fresh()->source_status);
        } finally {
            DB::rollBack();
        }
    }

    public function test_manual_purchase_reconciliation_is_encrypted_and_provider_scoped_on_mariadb(): void
    {
        if (env('FURIMADECK_MARIADB_GATE') !== '1') {
            $this->markTestSkipped('MariaDB gate is opt-in.');
        }

        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->assertTrue(Schema::connection('furimadeck')->hasTable('manual_product_purchase_reconciliations'));
        $this->assertTrue(Schema::connection('furimadeck')->hasTable('manual_product_purchase_reconciliation_refs'));
        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('manual_product_purchase_reconciliation_refs', 'transaction_total'));
        $this->assertTrue(Schema::connection('furimadeck')->hasColumn('manual_product_purchase_reconciliation_refs', 'allocated_amount'));
        $this->assertSame('longtext', strtolower(Schema::connection('furimadeck')->getColumnType('manual_product_purchase_reconciliations', 'evidence_history')));

        DB::beginTransaction();
        try {
            $user = User::factory()->create([
                'email_verified_at' => now(),
                'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
                'subscription_status' => 'active',
            ]);
            $marketplace = Marketplace::query()->create([
                'code' => 'mariadb-manual-'.bin2hex(random_bytes(4)),
                'name' => 'MariaDB手動照合ゲート',
                'base_url' => 'https://example.test',
                'default_fee_rate' => 0,
                'is_active' => true,
            ]);
            $product = $user->products()->create([
                'internal_sku' => 'MARIADB-MANUAL-001',
                'product_name' => 'MariaDB手動照合商品',
                'condition' => 'used',
                'purchase_date' => '2026-10-01',
                'purchase_accounting_enabled' => false,
                'purchase_unit_cost' => 1000,
                'purchase_quantity' => 1,
                'quantity_available' => 0,
                'inventory_status' => 'out_of_stock',
            ]);
            $secondProduct = $user->products()->create([
                'internal_sku' => 'MARIADB-MANUAL-002',
                'product_name' => 'MariaDB手動照合商品2',
                'condition' => 'used',
                'purchase_date' => '2026-10-01',
                'purchase_accounting_enabled' => false,
                'purchase_unit_cost' => 500,
                'purchase_quantity' => 1,
                'quantity_available' => 1,
                'inventory_status' => 'in_stock',
            ]);
            AccountingConnection::query()->create([
                'user_id' => $user->id,
                'provider' => 'freee',
                'access_token' => 'mariadb-gate-token',
                'refresh_token' => 'mariadb-gate-refresh',
                'external_account_id' => '123',
                'status' => 'connected',
                'settings' => ['company_id' => '123'],
            ]);
            $sale = $user->sales()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => 1,
                'sold_price' => 3000,
                'sold_at' => '2026-10-10 12:00:00',
                'status' => 'completed',
                'cost_basis' => 1000,
                'sales_fee' => 0,
                'shipping_fee' => 0,
                'purchase_shipping_cost' => 0,
                'packing_cost' => 0,
                'repair_cost' => 0,
                'cleaning_cost' => 0,
                'other_expense' => 0,
                'net_profit' => 2000,
            ]);
            $entry = app(FurimaDeckAccountingEntryService::class)
                ->syncPeriod($user, CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-11'))
                ->firstOrFail();
            $reconciliations = app(FurimaDeckAccountingPurchaseReconciliationService::class);

            $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$entry]), 'freee'));
            $reconciliation = $reconciliations->record($user, $product, 'freee', [
                'external_transaction_id' => 'mariadb-journal-manual-001',
                'transaction_date' => '2026-10-01',
                'debit_total' => 1500,
                'credit_total' => 1500,
                'debit_accounts' => '仕入高',
                'credit_accounts' => '普通預金',
                'expense_evidence' => [
                    'purchase_unit_cost' => [
                        'document_type' => 'receipt',
                        'tax_category' => 'standard_10',
                        'invoice_issuer' => 'qualified',
                        'accounting_treatment' => 'inventory',
                        'external_tax_classification' => '課税仕入10%',
                        'date_matches_entry_confirmed' => true,
                    ],
                ],
            ]);
            $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$entry]), 'freee'));
            $secondReconciliation = $reconciliations->record($user, $secondProduct, 'freee', [
                'external_transaction_id' => 'mariadb-journal-manual-001',
                'transaction_date' => '2026-10-01',
                'debit_total' => 1500,
                'credit_total' => 1500,
                'debit_accounts' => '仕入高',
                'credit_accounts' => '普通預金',
                'expense_evidence' => [
                    'purchase_unit_cost' => [
                        'document_type' => 'receipt',
                        'tax_category' => 'standard_10',
                        'invoice_issuer' => 'qualified',
                        'accounting_treatment' => 'inventory',
                        'external_tax_classification' => '課税仕入10%',
                        'date_matches_entry_confirmed' => true,
                    ],
                ],
            ]);
            $storedEvidence = DB::connection('furimadeck')
                ->table('manual_product_purchase_reconciliations')
                ->where('id', $reconciliation->id)
                ->value('evidence_history');

            $this->assertStringNotContainsString('mariadb-journal-manual-001', $storedEvidence);
            $this->assertNotEmpty($reconciliation->latestEvidence()['account_scope_hash']);
            $this->assertSame('課税仕入10%', $reconciliation->latestEvidence()['expense_evidence']['purchase_unit_cost']['external_tax_classification']);
            $this->assertSame([], app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$entry->fresh()]), 'freee'));
            $this->assertNotEmpty(app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues(collect([$entry->fresh()]), 'money_forward'));
            $this->assertSame(2, ManualProductPurchaseReconciliation::query()->where('user_id', $user->id)->count());
            $this->assertSame(2, DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')->where('user_id', $user->id)->count());
            $this->assertTrue($reconciliations->isCurrent($reconciliation, $product, 'freee'));
            $this->assertTrue($reconciliations->isCurrent($secondReconciliation, $secondProduct, 'freee'));
        } finally {
            DB::rollBack();
        }
    }
}
