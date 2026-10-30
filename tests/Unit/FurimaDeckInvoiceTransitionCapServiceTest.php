<?php

namespace Tests\Unit;

use App\Models\AccountingEntry;
use App\Models\AccountingPurchaseCapReview;
use App\Models\ManualProductPurchaseReconciliation;
use App\Models\User;
use App\Services\FurimaDeckAccountingPurchaseReconciliationService;
use App\Services\FurimaDeckAccountingVendorCsvService;
use App\Services\FurimaDeckInvoiceTransitionCapService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckInvoiceTransitionCapServiceTest extends TestCase
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

    public function test_current_cap_allows_exactly_one_hundred_million_yen_for_periods_starting_in_october_2026(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 99_999_999);
        $entry = $this->purchaseEntry($user, $review, '2026-10-02', 1);

        app(FurimaDeckInvoiceTransitionCapService::class)->assertEntryWithinCap($entry);

        $review->update(['external_qualifying_purchase_amount' => 100_000_000]);
        try {
            (new FurimaDeckInvoiceTransitionCapService)->assertEntryWithinCap($entry->fresh());
            $this->fail('The purchase exceeding the cap by one yen must be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('上限', $exception->getMessage());
        }
    }

    public function test_one_billion_yen_cap_is_used_for_periods_starting_before_october_2026(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-01-01', '2026-12-31', 999_999_999);
        $entry = $this->purchaseEntry($user, $review, '2026-10-05', 1);

        app(FurimaDeckInvoiceTransitionCapService::class)->assertEntryWithinCap($entry);

        $review->update(['external_qualifying_purchase_amount' => 1_000_000_000]);
        $this->expectException(RuntimeException::class);
        (new FurimaDeckInvoiceTransitionCapService)->assertEntryWithinCap($entry->fresh());
    }

    public function test_all_linked_furimadeck_purchases_are_counted_through_the_current_transaction_date(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 99_999_998);
        $this->purchaseEntry($user, $review, '2026-10-02', 1, 'CAP-001');
        $laterEntry = $this->purchaseEntry($user, $review, '2026-10-03', 2, 'CAP-002');

        $this->expectException(RuntimeException::class);
        (new FurimaDeckInvoiceTransitionCapService)->assertEntryWithinCap($laterEntry);
    }

    public function test_missing_or_foreign_user_review_blocks_an_unqualified_taxable_purchase(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $foreignReview = $this->review($otherUser, '2026-10-01', '2027-09-30', 0);
        $entry = $this->purchaseEntry($user, $foreignReview, '2026-10-02', 1000);

        $this->expectException(RuntimeException::class);
        (new FurimaDeckInvoiceTransitionCapService)->assertEntryWithinCap($entry);
    }

    public function test_incomplete_external_history_blocks_and_counterparty_name_is_encrypted(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 0, false);
        $entry = $this->purchaseEntry($user, $review, '2026-10-02', 1000);
        $storedName = DB::connection('furimadeck')->table('accounting_purchase_cap_reviews')->where('id', $review->id)->value('counterparty_name');

        $this->assertNotSame('テスト仕入先', $storedName);
        $this->expectException(RuntimeException::class);
        (new FurimaDeckInvoiceTransitionCapService)->assertEntryWithinCap($entry);
    }

    public function test_manual_purchase_reconciliation_does_not_treat_different_product_tax_evidence_as_already_counted(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 100_000_000);
        $entry = $this->purchaseEntry($user, $review, '2026-10-02', 1);
        $product = $entry->product;
        $productEvidence = (array) $product->purchase_evidence;
        $productEvidence['purchase_unit_cost']['invoice_issuer'] = 'qualified';
        $product->update(['purchase_evidence' => $productEvidence]);

        $this->expectException(RuntimeException::class);
        (new FurimaDeckInvoiceTransitionCapService)->assertManualPurchaseEvidenceWithinCap(
            (int) $user->id,
            $product->fresh(),
            '2026-10-02',
            ['purchase_unit_cost' => $this->evidence($review)],
        );
    }

    public function test_manual_purchase_evidence_aggregates_multiple_expenses_before_applying_the_supplier_cap(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 99_999_800);
        $product = $user->products()->create([
            'internal_sku' => 'CAP-MANUAL-MULTI-001',
            'product_name' => '複数費目上限確認テスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-02',
            'purchase_unit_cost' => 100,
            'purchase_quantity' => 1,
            'purchase_shipping_cost' => 100,
            'other_purchase_expense' => 100,
            'quantity_available' => 1,
            'purchase_evidence' => [],
        ]);
        $evidence = $this->evidence($review);

        try {
            (new FurimaDeckInvoiceTransitionCapService)->assertManualPurchaseEvidenceWithinCap(
                (int) $user->id,
                $product,
                '2026-10-02',
                [
                    'purchase_unit_cost' => $evidence,
                    'purchase_shipping_cost' => $evidence,
                    'other_purchase_expense' => $evidence,
                ],
            );
            $this->fail('The combined three expense lines exceed the supplier cap and must be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('上限', $exception->getMessage());
        }
    }

    public function test_prior_manual_purchase_reconciliations_are_included_in_supplier_cap_cumulative(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 99_999_999);
        $priorProduct = $user->products()->create([
            'internal_sku' => 'CAP-MANUAL-PRIOR-001',
            'product_name' => '過去照合済み商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-02',
            'purchase_accounting_enabled' => true,
            'purchase_unit_cost' => 1,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_evidence' => [],
        ]);
        $snapshotHash = app(FurimaDeckAccountingPurchaseReconciliationService::class)->snapshotHash($priorProduct);
        $priorEvidence = [
            'transaction_date' => '2026-10-02',
            'purchase_amount' => 1,
            'expense_evidence' => ['purchase_unit_cost' => $this->evidence($review)],
            'product_snapshot_hash' => $snapshotHash,
        ];
        ManualProductPurchaseReconciliation::query()->create([
            'user_id' => $user->id,
            'product_id' => $priorProduct->id,
            'provider' => 'freee',
            'product_snapshot_hash' => $snapshotHash,
            'evidence_history' => [$priorEvidence],
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
        ]);

        $currentProduct = $user->products()->create([
            'internal_sku' => 'CAP-MANUAL-CURRENT-001',
            'product_name' => '今回照合する商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-03',
            'purchase_unit_cost' => 1,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_evidence' => [],
        ]);

        try {
            (new FurimaDeckInvoiceTransitionCapService)->assertManualPurchaseEvidenceWithinCap(
                (int) $user->id,
                $currentProduct,
                '2026-10-03',
                ['purchase_unit_cost' => $this->evidence($review)],
            );
            $this->fail('A previous manual reconciliation plus this purchase exceed the supplier cap by one yen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('上限', $exception->getMessage());
        }
    }

    public function test_antique_dealer_special_case_purchase_cost_is_not_added_to_transition_cap_cumulative(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 99_999_999);
        $evidence = $this->evidence($review);
        $user->products()->create([
            'internal_sku' => 'CAP-ANTIQUE-001',
            'product_name' => '古物商特例対象商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-02',
            'purchase_unit_cost' => 1,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_evidence' => ['purchase_unit_cost' => $evidence],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => 'TEST-LICENSE',
                'counterparty_name' => 'テスト仕入先',
                'counterparty_address' => '東京都テスト区',
                'confirmed_at' => now()->toIso8601String(),
            ],
        ]);
        $currentEntry = $this->purchaseEntry($user, $review, '2026-10-03', 1, 'CAP-REGULAR-001');

        app(FurimaDeckInvoiceTransitionCapService::class)->assertEntryWithinCap($currentEntry);
    }

    public function test_taxable_purchase_cannot_bypass_cap_review_with_not_applicable_issuer(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 0);
        $entry = $this->purchaseEntry($user, $review, '2026-10-02', 1000);
        $product = $entry->product;
        $evidence = (array) $product->purchase_evidence;
        $evidence['purchase_unit_cost']['invoice_issuer'] = 'not_applicable';
        $product->update(['purchase_evidence' => $evidence]);

        try {
            (new FurimaDeckInvoiceTransitionCapService)->assertEntryWithinCap($entry->fresh());
            $this->fail('A taxable purchase with a non-applicable issuer must be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('発行者区分', $exception->getMessage());
        }
    }

    public function test_taxable_evidence_cannot_skip_supplier_memo_with_not_applicable_issuer(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 0);
        $evidence = $this->evidence($review);
        $evidence['invoice_issuer'] = 'not_applicable';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('発行者区分');
        (new FurimaDeckInvoiceTransitionCapService)->accountingMemoForEvidence($user->id, '2026-10-02', $evidence);
    }

    public function test_journal_memo_contains_counterparty_and_transaction_date_relief_rate(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 0);
        $evidence = $this->evidence($review);

        $memo = app(FurimaDeckInvoiceTransitionCapService::class)->accountingMemoForEvidence($user->id, '2026-10-02', $evidence);

        $this->assertSame('仕入先:テスト仕入先 / 70%控除対象', $memo);
    }

    public function test_transition_memo_respects_transaction_date_not_period_start(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2025-01-01', '2025-12-31', 0);
        $evidence = $this->evidence($review);

        $memo = app(FurimaDeckInvoiceTransitionCapService::class)->accountingMemoForEvidence($user->id, '2025-12-31', $evidence);

        $this->assertSame('仕入先:テスト仕入先 / 80%控除対象', $memo);
    }

    public function test_purchase_csv_description_contains_supplier_and_transaction_date_relief_rate(): void
    {
        $user = User::factory()->create();
        $review = $this->review($user, '2026-10-01', '2027-09-30', 0);
        $entry = $this->purchaseEntry($user, $review, '2026-10-02', 1000);
        $entry->update([
            'expense_breakdown' => [
                ...$entry->expense_breakdown,
                'payment_sources' => ['freee' => '1'],
            ],
        ]);

        $rows = app(FurimaDeckAccountingVendorCsvService::class)->journalRows(
            new Collection([$entry->fresh()]),
            ['inventory' => '商品', 'purchase_payment_1' => '普通預金'],
        );

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('仕入先:テスト仕入先', $rows[0]['description']);
        $this->assertStringContainsString('70%控除対象', $rows[0]['description']);
    }

    private function review(User $user, string $from, string $to, int $externalAmount, bool $historyComplete = true): AccountingPurchaseCapReview
    {
        return $user->accountingPurchaseCapReviews()->create([
            'period_from' => $from,
            'period_to' => $to,
            'counterparty_fingerprint' => app(FurimaDeckInvoiceTransitionCapService::class)->counterpartyFingerprint('テスト仕入先'),
            'counterparty_name' => 'テスト仕入先',
            'external_qualifying_purchase_amount' => $externalAmount,
            'reviewed_through' => $to,
            'furimadeck_history_complete' => true,
            'external_history_complete' => $historyComplete,
            'reviewed_at' => now(),
        ]);
    }

    private function purchaseEntry(User $user, AccountingPurchaseCapReview $review, string $date, int $amount, string $sku = 'CAP-001'): AccountingEntry
    {
        $evidence = $this->evidence($review);
        $product = $user->products()->create([
            'internal_sku' => $sku,
            'product_name' => '上限確認テスト商品',
            'condition' => 'used',
            'purchase_date' => $date,
            'purchase_accounting_enabled' => true,
            'purchase_unit_cost' => $amount,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_evidence' => ['purchase_unit_cost' => $evidence],
        ]);

        return $user->accountingEntries()->create([
            'sale_id' => null,
            'product_id' => $product->id,
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => $date,
            'management_id' => $sku,
            'title' => '上限確認テスト商品',
            'sale_amount' => 0,
            'purchase_cost' => $amount,
            'furimadeck_actual_profit' => 0,
            'source_status' => 'confirmed',
            'sync_status' => 'not_synced',
            'expense_breakdown' => [
                'purchase_quantity' => 1,
                'purchase_unit_cost' => $amount,
                'purchase_shipping_cost' => 0,
                'other_purchase_expense' => 0,
                'payment_sources' => ['freee' => '1'],
                'evidence' => ['purchase_unit_cost' => $evidence],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function evidence(AccountingPurchaseCapReview $review): array
    {
        return [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'unqualified',
            'date_matches_entry_confirmed' => true,
            'reviewed_at' => now()->toIso8601String(),
            'cap_review_id' => $review->id,
        ];
    }
}
