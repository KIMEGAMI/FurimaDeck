<?php

namespace Tests\Feature;

use App\Models\AccountingEntry;
use App\Models\Marketplace;
use App\Models\Sale;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckSaleExpenseAccountingService;
use App\Services\SaleLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SaleReturnLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_return_with_restock_records_refund_and_restores_inventory(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'RETURN-001',
            'product_name' => '返品テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 2,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'test-marketplace',
            'name' => 'テスト販売先',
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
            'quantity' => 2,
            'sold_price' => 5000,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 2000,
            'net_profit' => 3000,
        ]);

        $returned = app(SaleLifecycleService::class)->returnSale($user, $sale, '購入者都合', 5000, 600, true);

        $this->assertSame('returned', $returned->status);
        $this->assertSame(5000, $returned->refund_amount);
        $this->assertSame(600, $returned->return_shipping_fee);
        $this->assertSame(2, $returned->restocked_quantity);
        $this->assertSame(2, $product->fresh()->quantity_available);
        $this->assertSame('in_stock', $product->fresh()->inventory_status);
    }

    public function test_new_purchase_inventory_cost_is_included_in_sale_cost_only_once(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'CAPITALIZED-001',
            'product_name' => '取得原価テスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-12',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_shipping_cost' => 180,
            'other_purchase_expense' => 20,
            'purchase_accounting_enabled' => true,
            'purchase_evidence' => [
                'purchase_unit_cost' => ['document_type' => 'receipt', 'tax_category' => 'standard_10', 'invoice_issuer' => 'qualified', 'date_matches_entry_confirmed' => true, 'reviewed_at' => now()->toIso8601String()],
                'purchase_shipping_cost' => ['document_type' => 'receipt', 'tax_category' => 'standard_10', 'invoice_issuer' => 'qualified', 'date_matches_entry_confirmed' => true, 'reviewed_at' => now()->toIso8601String()],
                'other_purchase_expense' => ['document_type' => 'receipt', 'tax_category' => 'standard_10', 'invoice_issuer' => 'qualified', 'date_matches_entry_confirmed' => true, 'reviewed_at' => now()->toIso8601String()],
            ],
        ]);
        app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product);
        $marketplace = Marketplace::query()->create([
            'code' => 'capitalized-test',
            'name' => '取得原価テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);

        $sale = app(SaleLifecycleService::class)->record($user, [
            'product_id' => $product->id,
            'quantity' => 1,
            'marketplace_id' => $marketplace->id,
            'sold_price' => 3000,
            'sold_at' => '2026-10-14 12:00:00',
            'cost_basis' => 1200,
            'sales_fee' => 300,
            'shipping_fee' => 500,
            'purchase_shipping_cost' => 180,
            'packing_cost' => 0,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
        ]);

        $this->assertSame(1400, $sale->cost_basis);
        $this->assertSame(0, $sale->purchase_shipping_cost);
        $this->assertSame(800, $sale->net_profit);
    }

    public function test_sale_with_unclassified_purchase_evidence_is_recorded_but_held_from_accounting_sync(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'UNCLASSIFIED-001',
            'product_name' => '証憑未確定テスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-12',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_accounting_enabled' => true,
            'purchase_evidence' => [
                'purchase_unit_cost' => ['document_type' => 'receipt', 'tax_category' => 'unknown', 'invoice_issuer' => 'qualified', 'date_matches_entry_confirmed' => true, 'reviewed_at' => now()->toIso8601String()],
            ],
        ]);
        app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product);
        $marketplace = Marketplace::query()->create([
            'code' => 'unclassified-test',
            'name' => '証憑未確定テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $sale = app(SaleLifecycleService::class)->record($user, [
            'product_id' => $product->id,
            'quantity' => 1,
            'marketplace_id' => $marketplace->id,
            'sold_price' => 3000,
            'sold_at' => '2026-10-14 12:00:00',
            'cost_basis' => 1200,
            'sales_fee' => 0,
            'shipping_fee' => 0,
            'purchase_shipping_cost' => 0,
            'packing_cost' => 0,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
        ]);
        $saleEntry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstOrFail();
        $issues = app(FurimaDeckAccountingEntryService::class)
            ->providerPurchaseReconciliationIssues(collect([$saleEntry]), 'freee');

        $this->assertSame($sale->id, $saleEntry->sale_id);
        $this->assertStringContainsString('未照合', $issues[$saleEntry->id]);
    }

    public function test_sale_status_can_only_progress_from_awaiting_shipment_to_completed(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'STATUS-001',
            'product_name' => 'ステータス確認商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'status-marketplace',
            'name' => 'ステータス販売先',
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
            'sold_at' => now(),
            'status' => 'awaiting_shipment',
            'cost_basis' => 1000,
            'net_profit' => 2000,
        ]);

        $shipped = app(SaleLifecycleService::class)->advanceStatus($user, $sale, 'shipped');
        $completed = app(SaleLifecycleService::class)->advanceStatus($user, $shipped, 'completed');

        $this->assertSame('shipped', $shipped->status);
        $this->assertNotNull($shipped->shipped_at);
        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->completed_at);

        $this->expectException(RuntimeException::class);
        app(SaleLifecycleService::class)->advanceStatus($user, $completed, 'shipped');
    }

    public function test_amount_correction_refreshes_unsent_accounting_entry_and_requires_review_again(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'CORRECTION-001',
            'product_name' => '金額修正テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_info_confirmed' => true,
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'correction-marketplace',
            'name' => '金額修正テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
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

        $corrected = app(SaleLifecycleService::class)->correctAmounts($user, $sale, [
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

        $entry->refresh();
        $this->assertSame('2026-10-22', $entry->transaction_date->toDateString());
        $this->assertSame(6000, $entry->sale_amount);
        $this->assertSame(200, $entry->shipping_cost);
        $this->assertSame(4800, $entry->furimadeck_actual_profit);
        $this->assertSame('review', $entry->source_status);
        $this->assertNull($entry->confirmed_at);
        $this->assertSame(6000, $corrected->fresh()->sold_price);
    }

    public function test_amount_correction_rejects_stale_snapshot_when_updated_at_is_unchanged(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'STALE-CORRECTION-001',
            'product_name' => '同秒更新テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'stale-correction-marketplace',
            'name' => '同秒更新テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
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
        $staleVersion = $sale->recordVersionToken();
        $originalUpdatedAt = $sale->getRawOriginal('updated_at');

        Sale::query()->whereKey($sale->id)->update([
            'sold_price' => 5500,
            'updated_at' => $originalUpdatedAt,
        ]);
        $this->assertSame($originalUpdatedAt, $sale->fresh()->getRawOriginal('updated_at'));

        try {
            app(SaleLifecycleService::class)->correctAmounts($user, $sale, [
                'record_version' => $staleVersion,
                'sold_at' => '2026-10-21 12:00:00',
                'sold_price' => 5000,
                'cost_basis' => 1000,
                'sales_fee' => 0,
                'shipping_fee' => 100,
                'purchase_shipping_cost' => 0,
                'packing_cost' => 0,
                'repair_cost' => 0,
                'cleaning_cost' => 0,
                'other_expense' => 0,
            ]);
            $this->fail('A stale snapshot must not overwrite a same-second sale correction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('別の操作で販売記録が更新されました。再読み込みして確認してください。', $exception->getMessage());
        }

        $this->assertSame(5500, $sale->fresh()->sold_price);
    }

    public function test_expense_evidence_update_refreshes_accounting_entry_and_requires_review_again(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'EXPENSE-REVIEW-001',
            'product_name' => '販売費用確認テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_info_confirmed' => true,
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'expense-review-marketplace',
            'name' => '販売費用確認テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
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
            'packing_cost' => 100,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
        ]);
        $sale = $sale->fresh();
        $entry = $user->accountingEntries()->where('sale_id', $sale->id)->firstOrFail();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

        app(FurimaDeckSaleExpenseAccountingService::class)->updateExpenseDetails($user, $sale, [
            'packing_cost' => [
                'document_type' => 'receipt',
                'tax_category' => 'standard_10',
                'invoice_issuer' => 'qualified',
                'date_matches_entry_confirmed' => true,
            ],
        ]);

        $entry->refresh();
        $evidence = $entry->expense_breakdown['sale_expenses']['packing_cost'];
        $this->assertSame('receipt', $evidence['document_type']);
        $this->assertSame('standard_10', $evidence['tax_category']);
        $this->assertSame('qualified', $evidence['invoice_issuer']);
        $this->assertSame(true, $evidence['date_matches_entry_confirmed']);
        $this->assertNotEmpty($evidence['reviewed_at']);
        $this->assertSame('review', $entry->source_status);
        $this->assertNull($entry->confirmed_at);
        $this->assertSame(1, $user->accountingEntries()->where('sale_id', $sale->id)->count());
    }

    public function test_amount_correction_rolls_back_when_accounting_entry_refresh_fails(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'CORRECTION-ROLLBACK-001',
            'product_name' => '会計更新失敗テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_info_confirmed' => true,
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'correction-rollback-marketplace',
            'name' => '会計更新失敗テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
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

        $accountingEntries = Mockery::mock(FurimaDeckAccountingEntryService::class);
        $accountingEntries->shouldReceive('recordSale')->once()->andThrow(new RuntimeException('simulated entry failure'));
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
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated entry failure', $exception->getMessage());
        }

        $this->assertSame(5000, $sale->fresh()->sold_price);
        $this->assertSame('2026-10-21', $sale->fresh()->sold_at->toDateString());
        $this->assertSame(100, $sale->fresh()->shipping_fee);
        $this->assertSame(5000, $entry->fresh()->sale_amount);
        $this->assertSame('confirmed', $entry->fresh()->source_status);
        $this->assertNotNull($entry->fresh()->confirmed_at);
    }

    public function test_accounting_send_in_progress_blocks_sale_changes_that_would_make_the_payload_stale(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'SYNC-LOCK-001',
            'product_name' => '会計送信中テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sync-lock-marketplace',
            'name' => '送信中テスト販売先',
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
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 2000,
        ]);
        $entry = AccountingEntry::query()->create([
            'user_id' => $user->id,
            'sale_id' => $sale->id,
            'transaction_date' => $sale->sold_at->toDateString(),
            'management_id' => $sale->internal_sku_snapshot,
            'title' => $sale->product_name_snapshot,
            'sale_amount' => $sale->sold_price,
            'purchase_cost' => $sale->cost_basis,
            'furimadeck_actual_profit' => $sale->net_profit,
            'source_status' => 'confirmed',
            'sync_status' => 'queued',
            'confirmed_at' => now(),
        ]);
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'sending']);
        $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'queued']);
        $lifecycle = app(SaleLifecycleService::class);

        foreach ([
            fn () => $lifecycle->cancel($user, $sale, 'test'),
            fn () => $lifecycle->returnSale($user, $sale, 'test', 3000, 0, true),
            fn () => $lifecycle->correctAmounts($user, $sale, []),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An accounting send in progress must prevent sale changes.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('結果が未確定', $exception->getMessage());
            }
        }

        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame(3000, $sale->fresh()->sold_price);
        $this->assertSame('confirmed', $entry->fresh()->source_status);
        $this->assertSame('sending', $entry->providerSync('freee')->fresh()->status);
        $this->assertSame('queued', $entry->providerSync('money_forward')->fresh()->status);

        $entry->providerSyncs()->update(['status' => 'csv_pending']);
        foreach ([
            fn () => $lifecycle->cancel($user, $sale, 'test'),
            fn () => $lifecycle->returnSale($user, $sale, 'test', 3000, 0, true),
            fn () => $lifecycle->correctAmounts($user, $sale, []),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A CSV awaiting import confirmation must prevent sale changes.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('CSV取込', $exception->getMessage());
            }
        }

        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame(3000, $sale->fresh()->sold_price);

        $entry->providerSyncs()->update(['status' => 'sync_unknown']);
        $returned = $lifecycle->returnSale($user, $sale, 'uncertain send reconciliation', 3000, 0, true);
        $this->assertSame('returned', $returned->status);
        $this->assertSame('sync_unknown', $entry->providerSync('freee')->fresh()->status);
    }
}
