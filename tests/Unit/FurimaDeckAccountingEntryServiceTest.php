<?php

namespace Tests\Unit;

use App\Models\AccountingEntry;
use App\Models\Marketplace;
use App\Models\Sale;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckAccountingEntryServiceTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_sync_is_user_scoped_idempotent_and_preserves_zero_costs(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $marketplace = Marketplace::query()->create(['code' => 'accounting-test', 'name' => '会計テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $product = $user->products()->create(['internal_sku' => 'ACC-001', 'product_name' => '会計商品', 'condition' => 'used', 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        $otherProduct = $other->products()->create(['internal_sku' => 'ACC-OTHER', 'product_name' => '他人商品', 'condition' => 'used', 'purchase_unit_cost' => 1, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 5000, 'sold_at' => '2026-01-01 12:00:00', 'status' => 'completed', 'cost_basis' => 1000, 'sales_fee' => 0, 'shipping_fee' => 500, 'purchase_shipping_cost' => 0, 'packing_cost' => 0, 'repair_cost' => 0, 'cleaning_cost' => 0, 'other_expense' => 0, 'net_profit' => 3500]);
        $other->sales()->create(['product_id' => $otherProduct->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $otherProduct->internal_sku, 'product_name_snapshot' => $otherProduct->product_name, 'quantity' => 1, 'sold_price' => 9999, 'sold_at' => '2026-01-01 12:00:00', 'status' => 'completed', 'net_profit' => 9999]);

        $service = app(FurimaDeckAccountingEntryService::class);
        $first = $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));
        $second = $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(1, AccountingEntry::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, AccountingEntry::query()->where('user_id', $other->id)->count());
        $this->assertSame(3500, $first->first()->furimadeck_actual_profit);
        $this->assertSame(0, $first->first()->other_cost);
    }

    public function test_existing_product_snapshots_cannot_create_purchase_accounting_entries(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-001',
            'product_name' => '既存の仕入商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-03',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 2,
            'purchase_shipping_cost' => 300,
            'other_purchase_expense' => 50,
            'quantity_available' => 2,
            'inventory_status' => 'in_stock',
        ]);
        try {
            app(FurimaDeckAccountingEntryService::class)->preparePurchaseEntries($user, [$product->id]);
            $this->fail('既存商品情報から仕入会計データを作成してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('期首在庫', $exception->getMessage());
        }

        $this->assertSame(0, AccountingEntry::query()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_existing_product_selection_cannot_create_partial_purchase_entries(): void
    {
        $user = User::factory()->create();
        $dated = $user->products()->create([
            'internal_sku' => 'PURCHASE-DATED',
            'product_name' => '日付あり',
            'condition' => 'used',
            'purchase_date' => '2026-10-03',
            'purchase_unit_cost' => 500,
            'purchase_quantity' => 1,
            'purchase_shipping_cost' => 0,
            'other_purchase_expense' => 0,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $undated = $user->products()->create([
            'internal_sku' => 'PURCHASE-UNDATED',
            'product_name' => '日付なし',
            'condition' => 'used',
            'purchase_unit_cost' => 500,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        try {
            app(FurimaDeckAccountingEntryService::class)->preparePurchaseEntries($user, [$dated->id, $undated->id]);
            $this->fail('既存商品情報から仕入会計データを作成してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('重複', $exception->getMessage());
        }

        $this->assertSame(0, AccountingEntry::query()->count());
    }

    public function test_legacy_single_evidence_is_not_migrated_or_accepted_as_expense_level_proof(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-LEGACY-EVIDENCE',
            'product_name' => '旧形式証憑商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-03',
            'purchase_unit_cost' => 500,
            'purchase_quantity' => 1,
            'purchase_accounting_enabled' => true,
            'purchase_document_type' => 'receipt',
            'purchase_tax_category' => 'standard_10',
            'purchase_invoice_issuer' => 'qualified',
            'purchase_document_reviewed_at' => now(),
            'quantity_available' => 1,
        ]);

        $this->assertNull($product->purchase_evidence);
        try {
            app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product);
            $this->fail('旧形式の証憑を費用別の確認済み証憑として扱ってはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('費用ごとに証憑を確認', $exception->getMessage());
        }

        $this->assertSame(0, AccountingEntry::query()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_existing_purchase_snapshot_remains_readable_and_source_changes_are_detected(): void
    {
        $user = User::factory()->create();
        $reviewedAt = now();
        $product = $user->products()->create([
            'internal_sku' => 'PURCHASE-CHANGE',
            'product_name' => '変更確認商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-03',
            'purchase_unit_cost' => 500,
            'purchase_quantity' => 1,
            'purchase_shipping_cost' => 0,
            'other_purchase_expense' => 0,
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
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $service = app(FurimaDeckAccountingEntryService::class);
        $entry = AccountingEntry::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => '2026-10-03',
            'management_id' => $product->internal_sku,
            'title' => $product->product_name,
            'sale_amount' => 0,
            'purchase_cost' => 500,
            'selling_fee' => 0,
            'shipping_cost' => 0,
            'other_cost' => 0,
            'expense_breakdown' => [
                'purchase_quantity' => 1,
                'purchase_unit_cost' => 500,
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
            'source_status' => 'review',
            'sync_status' => 'not_synced',
        ]);
        $this->assertTrue($service->isCurrent($entry));

        $product->update(['purchase_payment_sources' => ['freee' => 'bank-id']]);
        $this->assertFalse($service->isCurrent($entry->fresh()));

        $product->update(['purchase_unit_cost' => 600]);
        $this->assertFalse($service->isCurrent($entry->fresh()));
        $this->assertSame(500, $entry->fresh()->purchase_cost);
    }

    public function test_changed_sale_requires_a_new_review_before_sending(): void
    {
        [$user, $sale] = $this->saleFixture();
        $service = app(FurimaDeckAccountingEntryService::class);
        $entry = $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))->firstOrFail();
        $entry->update(['source_status' => 'confirmed']);
        $sale->update(['sold_price' => 5001, 'net_profit' => 4001]);

        try {
            $service->assertCurrent($entry->fresh());
            $this->fail('変更後の売上を古い会計データで送信してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('一致しません', $exception->getMessage());
        }

        $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));
        $this->assertSame('review', $entry->fresh()->source_status);
        $this->assertSame(5001, $entry->fresh()->sale_amount);
    }

    public function test_csv_pending_entry_is_not_overwritten_when_sale_changes(): void
    {
        [$user, $sale] = $this->saleFixture();
        $service = app(FurimaDeckAccountingEntryService::class);
        $entry = $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))->firstOrFail();
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'csv_pending']);
        $sale->update(['sold_price' => 5001, 'net_profit' => 4001]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('外部会計ソフトと照合');
        try {
            $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));
        } finally {
            $this->assertSame(5000, $entry->fresh()->sale_amount);
        }
    }

    public function test_expense_breakdown_key_order_does_not_make_current_entry_stale(): void
    {
        [$user] = $this->saleFixture();
        $service = app(FurimaDeckAccountingEntryService::class);
        $entry = $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))->firstOrFail();
        $entry->update(['expense_breakdown' => array_reverse($entry->expense_breakdown, true)]);

        $this->assertTrue($service->isCurrent($entry->fresh()));
        $entry->update(['expense_breakdown' => [...$entry->expense_breakdown, 'other_expense' => 1]]);
        $this->assertFalse($service->isCurrent($entry->fresh()));
    }

    public function test_sync_preserves_every_cost_component_in_the_management_profit(): void
    {
        [$user, $sale] = $this->saleFixture();
        $sale->update([
            'sold_price' => 10000,
            'cost_basis' => 3000,
            'sales_fee' => 1000,
            'shipping_fee' => 750,
            'purchase_shipping_cost' => 500,
            'packing_cost' => 200,
            'repair_cost' => 100,
            'cleaning_cost' => 50,
            'other_expense' => 20,
            'net_profit' => 4380,
        ]);

        $entry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstOrFail();

        $this->assertSame(10000, $entry->sale_amount);
        $this->assertSame(3000, $entry->purchase_cost);
        $this->assertSame(1000, $entry->selling_fee);
        $this->assertSame(750, $entry->shipping_cost);
        $this->assertSame(870, $entry->other_cost);
        $this->assertSame([
            'purchase_shipping_cost' => 500,
            'packing_cost' => 200,
            'repair_cost' => 100,
            'cleaning_cost' => 50,
            'other_expense' => 20,
        ], $entry->expense_breakdown);
        $this->assertSame(4380, $entry->furimadeck_actual_profit);
        $this->assertSame(
            $entry->sale_amount - $entry->purchase_cost - $entry->selling_fee - $entry->shipping_cost - $entry->other_cost,
            $entry->furimadeck_actual_profit,
        );
    }

    public function test_one_yen_profit_mismatch_blocks_accounting_sync_and_current_checks(): void
    {
        [$user, $sale] = $this->saleFixture();
        $service = app(FurimaDeckAccountingEntryService::class);
        $from = CarbonImmutable::parse('2026-01-01');
        $to = CarbonImmutable::parse('2027-01-01');

        $sale->update(['net_profit' => 3999]);
        try {
            $service->syncPeriod($user, $from, $to);
            $this->fail('実利益が1円違う売上を会計データにしてはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('実利益', $exception->getMessage());
        }
        $this->assertSame(0, AccountingEntry::query()->where('user_id', $user->id)->count());

        $sale->update(['net_profit' => 4000]);
        $entry = $service->syncPeriod($user, $from, $to)->firstOrFail();
        $sale->update(['net_profit' => 3999]);

        $this->assertFalse($service->isCurrent($entry->fresh()));
        $this->assertSame(3999, $sale->fresh()->net_profit);
    }

    public function test_legacy_entry_keeps_unknown_breakdown_without_rewriting_snapshot(): void
    {
        [$user, $sale] = $this->saleFixture();
        $service = app(FurimaDeckAccountingEntryService::class);
        $from = CarbonImmutable::parse('2026-01-01');
        $to = CarbonImmutable::parse('2027-01-01');
        $entry = $service->syncPeriod($user, $from, $to)->firstOrFail();
        $entry->update(['expense_breakdown' => null, 'source_status' => 'confirmed', 'sync_status' => 'synced']);

        $this->assertFalse($service->isCurrent($entry->fresh()));
        try {
            $service->syncPeriod($user, $from, $to);
            $this->fail('送信済みの旧明細へ費用内訳を推定して書き込んではいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('照合', $exception->getMessage());
        }
        $this->assertNull($entry->fresh()->expense_breakdown);
        $this->assertSame('confirmed', $entry->fresh()->source_status);

        $sale->update(['sold_price' => 5001, 'net_profit' => 4001]);
        $this->assertFalse($service->isCurrent($entry->fresh()));
    }

    public function test_unsent_legacy_entry_with_unknown_breakdown_requires_fresh_review(): void
    {
        [$user] = $this->saleFixture();
        $service = app(FurimaDeckAccountingEntryService::class);
        $from = CarbonImmutable::parse('2026-01-01');
        $to = CarbonImmutable::parse('2027-01-01');
        $entry = $service->syncPeriod($user, $from, $to)->firstOrFail();
        $entry->update(['expense_breakdown' => null, 'source_status' => 'confirmed', 'confirmed_at' => now()]);

        $this->assertFalse($service->isCurrent($entry->fresh()));
        $service->syncPeriod($user, $from, $to);

        $this->assertSame('review', $entry->fresh()->source_status);
        $this->assertNull($entry->fresh()->confirmed_at);
        $this->assertSame([
            'purchase_shipping_cost' => 0,
            'packing_cost' => 0,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
        ], $entry->fresh()->expense_breakdown);
    }

    public function test_synced_snapshot_is_not_rewritten_when_source_sale_changes(): void
    {
        [$user, $sale] = $this->saleFixture();
        $service = app(FurimaDeckAccountingEntryService::class);
        $entry = $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))->firstOrFail();
        $entry->update(['source_status' => 'confirmed', 'sync_status' => 'synced', 'external_transaction_id' => 'external-123']);
        $sale->update(['sold_price' => 5001]);

        try {
            $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));
            $this->fail('送信済みのスナップショットを上書きしてはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('照合', $exception->getMessage());
        }

        $this->assertSame(5000, $entry->fresh()->sale_amount);
        $this->assertSame('external-123', $entry->fresh()->external_transaction_id);
    }

    public function test_legacy_summary_does_not_hide_another_provider_in_progress_or_unknown(): void
    {
        [$user] = $this->saleFixture();
        $entry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstOrFail();
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'synced']);
        $otherProvider = $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'sending']);

        $entry->refreshLegacySyncStatus();
        $this->assertSame('queued', $entry->fresh()->sync_status);

        $otherProvider->update(['status' => 'sync_unknown']);
        $entry->refreshLegacySyncStatus();
        $this->assertSame('sync_unknown', $entry->fresh()->sync_status);

        $otherProvider->update(['status' => 'synced']);
        $entry->refreshLegacySyncStatus();
        $this->assertSame('synced', $entry->fresh()->sync_status);
    }

    public function test_unrelated_provider_sync_does_not_clear_an_unattributed_legacy_unknown(): void
    {
        [$user] = $this->saleFixture();
        $entry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstOrFail();
        $entry->update(['sync_status' => 'sync_unknown']);
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'failed']);

        $this->assertSame('sync_unknown', $entry->fresh()->providerSyncStatus('money_forward'));

        [$otherUser] = $this->saleFixture();
        $otherEntry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($otherUser, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstOrFail();
        $otherEntry->update(['sync_status' => 'sync_unknown']);
        $otherEntry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'csv_pending']);

        $this->assertSame('not_synced', $otherEntry->fresh()->providerSyncStatus('freee'));
    }

    public function test_unrelated_provider_failure_does_not_clear_a_legacy_queued_state(): void
    {
        [$user] = $this->saleFixture();
        $entry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstOrFail();
        $entry->update(['sync_status' => 'queued']);
        $entry->providerSyncs()->create(['provider' => 'freee', 'status' => 'failed']);

        $this->assertSame('queued', $entry->fresh()->providerSyncStatus('money_forward'));
    }

    /** @return array{0: User, 1: Sale} */
    private function saleFixture(): array
    {
        $user = User::factory()->create();
        $marketplace = Marketplace::query()->create(['code' => 'accounting-'.uniqid(), 'name' => '会計テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $product = $user->products()->create(['internal_sku' => 'ACC-'.uniqid(), 'product_name' => '会計商品', 'condition' => 'used', 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        $sale = $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 5000, 'sold_at' => '2026-01-10', 'status' => 'completed', 'cost_basis' => 1000, 'net_profit' => 4000]);

        return [$user, $sale];
    }
}
