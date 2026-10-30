<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\ManualProductPurchaseReconciliation;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class FurimaDeckAccountingEntryService
{
    public function __construct(private readonly SaleProfitCalculator $profitCalculator) {}

    /** @return Collection<int, AccountingEntry> */
    public function syncPeriod(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::connection('furimadeck')->transaction(function () use ($user, $from, $to): Collection {
            $sales = $user->sales()->with(['marketplace'])->whereIn('status', Sale::VALID_SOLD_STATUSES)
                ->where('sold_at', '>=', $from)->where('sold_at', '<', $to)
                ->lockForUpdate()
                ->get();

            return $sales->map(fn (Sale $sale): AccountingEntry => $this->syncSaleEntry($user, $sale));
        });
    }

    public function recordSale(User $user, Sale $sale): AccountingEntry
    {
        return DB::connection('furimadeck')->transaction(function () use ($user, $sale): AccountingEntry {
            $lockedSale = Sale::query()
                ->whereKey($sale->id)
                ->where('user_id', $user->id)
                ->whereIn('status', Sale::VALID_SOLD_STATUSES)
                ->with('marketplace')
                ->lockForUpdate()
                ->firstOrFail();

            return $this->syncSaleEntry($user, $lockedSale);
        });
    }

    /** @param list<int> $productIds @return Collection<int, AccountingEntry> */
    public function preparePurchaseEntries(User $user, array $productIds, ?CarbonImmutable $from = null, ?CarbonImmutable $toExclusive = null): Collection
    {
        throw new RuntimeException('既存商品の仕入情報だけでは、期首在庫や会計ソフト側の仕訳との重複を判定できません。過去分から仕入会計データは作成しません。');
    }

    public function createNewPurchaseEntry(User $user, Product $product): AccountingEntry
    {
        if ($product->user_id !== $user->id || ! $product->purchase_accounting_enabled) {
            throw new RuntimeException('新規仕入として明示されたご本人の商品だけ会計明細を作成できます。');
        }

        $attributes = $this->purchaseAttributes($product);
        $entry = $user->accountingEntries()
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($entry !== null) {
            if (! $this->matches($entry, $attributes)) {
                throw new RuntimeException('既存の仕入会計明細と商品情報が異なります。既存の明細を確認してください。');
            }

            return $entry;
        }

        $entry = new AccountingEntry($attributes);
        $entry->source_status = 'ready';
        $entry->sync_status = 'not_synced';

        return $user->accountingEntries()->save($entry);
    }

    public function refreshNewPurchaseEntry(User $user, Product $product): AccountingEntry
    {
        if ($product->user_id !== $user->id || ! $product->purchase_accounting_enabled) {
            throw new RuntimeException('新規仕入として明示されたご本人の商品だけ会計明細を更新できます。');
        }

        $attributes = $this->purchaseAttributes($product);
        $entry = $user->accountingEntries()
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();
        if ($entry === null) {
            $entry = new AccountingEntry($attributes);
            $entry->source_status = 'ready';
            $entry->sync_status = 'not_synced';

            return $user->accountingEntries()->save($entry);
        }
        if ($this->matches($entry, $attributes)) {
            return $entry;
        }

        if (filled($entry->external_transaction_id) || filled($entry->external_import_id)
            || $entry->providerSyncs()->whereIn('status', [
                'queued', 'sending', 'synced', 'sync_unknown', 'legacy_unknown', 'csv_pending', AccountingEntrySync::STATUS_CSV_IMPORTED, AccountingEntrySync::STATUS_MANUALLY_RECONCILED,
            ])->exists()) {
            throw new RuntimeException('仕入明細は送信済み・取込済み・結果不明です。外部会計サービスとの照合が終わるまで仕入情報を変更できません。');
        }

        $entry->fill($attributes);
        $entry->source_status = 'review';
        $entry->confirmed_at = null;
        $entry->save();
        $entry->refreshLegacySyncStatus();

        return $entry->refresh();
    }

    public function costBasisForNewSale(User $user, Product $product): int
    {
        if ($product->user_id !== $user->id) {
            throw new RuntimeException('販売商品の所有者を確認できません。');
        }
        if (! $product->purchase_accounting_enabled) {
            return (int) $product->purchase_unit_cost;
        }

        $product = Product::query()->whereKey($product->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        if ((int) $product->purchase_quantity !== 1) {
            throw new RuntimeException('会計対象の新規仕入は1商品1点の原価配賦を確認できるまで販売記録を作成できません。');
        }

        $entry = $user->accountingEntries()
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->where('product_id', $product->id)
            ->first();
        if ($entry === null || ! $this->isCurrent($entry)) {
            throw new RuntimeException('新規仕入の会計明細が商品情報と一致しません。仕入情報を確認するまで販売を記録できません。');
        }

        return (int) $entry->purchase_cost + (int) $entry->other_cost;
    }

    public function assertCurrent(AccountingEntry $entry): void
    {
        if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
            $product = $entry->loadMissing('product')->product;
            if ($product === null || ! $this->purchaseEvidenceIsClassified($product)) {
                throw new RuntimeException('仕入証憑の税区分・発行者区分または仕訳日との一致が未確認です。証憑を確認するまで、会計データを確定・送信できません。');
            }
        } else {
            app(FurimaDeckSaleExpenseAccountingService::class)->assertClassified($entry);
        }

        app(FurimaDeckInvoiceTransitionCapService::class)->assertEntryWithinCap($entry);

        if (! $this->isCurrent($entry)) {
            throw new RuntimeException('売上記録と会計データが一致しません。会計データを更新・確認し、送信済みの場合は外部会計ソフトと照合してください。');
        }
    }

    public function assertProviderPurchaseReconciled(AccountingEntry $saleEntry, string $provider): void
    {
        $issue = $this->providerPurchaseReconciliationIssues(collect([$saleEntry]), $provider)[$saleEntry->id] ?? null;
        if ($issue !== null) {
            throw new RuntimeException($issue);
        }
    }

    /** @param Collection<int, AccountingEntry> $entries @return array<int, string> */
    public function providerPurchaseReconciliationIssues(Collection $entries, string $provider): array
    {
        if (! in_array($provider, ['freee', 'money_forward'], true)) {
            throw new InvalidArgumentException('会計サービスが正しくありません。');
        }

        $saleEntries = $entries->filter(fn (AccountingEntry $entry): bool => $entry->entry_type === AccountingEntry::TYPE_SALE);
        $saleEntries->each(fn (AccountingEntry $entry) => $entry->loadMissing('sale'));
        $productIds = $saleEntries->map(fn (AccountingEntry $entry) => $entry->sale?->product_id === null ? null : (int) $entry->sale->product_id)
            ->filter()
            ->unique()
            ->values();
        if ($productIds->isEmpty()) {
            return $saleEntries->isEmpty() ? [] : $saleEntries->mapWithKeys(fn (AccountingEntry $entry): array => [$entry->id => '仕入元の商品を確認できません。この売上仕訳は送信・CSV出力できません。'])->all();
        }

        $purchaseEntries = AccountingEntry::query()
            ->whereIn('user_id', $saleEntries->pluck('user_id')->unique())
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->whereIn('product_id', $productIds)
            ->with(['product', 'providerSyncs'])
            ->get()
            ->keyBy(fn (AccountingEntry $entry): string => $entry->user_id.':'.$entry->product_id);
        $products = Product::query()
            ->whereIn('user_id', $saleEntries->pluck('user_id')->unique())
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy(fn (Product $product): string => $product->user_id.':'.$product->id);
        $manualReconciliations = ManualProductPurchaseReconciliation::query()
            ->whereIn('user_id', $saleEntries->pluck('user_id')->unique())
            ->whereIn('product_id', $productIds)
            ->where('provider', $provider)
            ->get()
            ->keyBy(fn (ManualProductPurchaseReconciliation $reconciliation): string => $reconciliation->user_id.':'.$reconciliation->product_id);
        $providerConnections = AccountingConnection::query()
            ->whereIn('user_id', $saleEntries->pluck('user_id')->unique())
            ->where('provider', $provider)
            ->whereIn('status', ['connected', 'expired'])
            ->get()
            ->keyBy('user_id');
        $scopeService = app(FurimaDeckAccountingProviderScopeService::class);
        $manualReconciliationService = app(FurimaDeckAccountingPurchaseReconciliationService::class);

        $issues = [];
        foreach ($saleEntries as $saleEntry) {
            $sale = $saleEntry->sale;
            if ($sale === null || $sale->user_id !== $saleEntry->user_id || $sale->product_id === null) {
                $issues[$saleEntry->id] = '仕入元の商品を確認できません。この売上仕訳は送信・CSV出力できません。';

                continue;
            }

            $purchaseEntry = $purchaseEntries->get($saleEntry->user_id.':'.$sale->product_id);
            if ($purchaseEntry === null) {
                $product = $products->get($saleEntry->user_id.':'.$sale->product_id);
                $manualReconciliation = $manualReconciliations->get($saleEntry->user_id.':'.$sale->product_id);
                if ($product === null) {
                    $issues[$saleEntry->id] = '仕入元の商品を確認できません。この売上仕訳は送信・CSV出力できません。';

                    continue;
                }
                if ($product->purchase_accounting_enabled) {
                    $issues[$saleEntry->id] = 'この商品の仕入会計明細が見つかりません。会計データの仕入画面で明細を保存し、会計サービス側の登録済み仕入記録を手動照合するまで、この売上CSVは保留です。';

                    continue;
                }
                if ($manualReconciliation === null) {
                    $issues[$saleEntry->id] = 'この商品の仕入れ・期首在庫は未照合です。会計データ内の「仕入記録の手動照合」で会計サービス側に登録済みの仕入記録を確認するまで、この売上仕訳は送信・CSV出力できません。';

                    continue;
                }

                try {
                    $manualReconciliationService->assertCurrentAndClassified($manualReconciliation, $product, $provider);
                } catch (RuntimeException $exception) {
                    $issues[$saleEntry->id] = $exception->getMessage();

                    continue;
                }
            } else {
                $manualReconciliation = $manualReconciliations->get($saleEntry->user_id.':'.$sale->product_id);
                $manualEvidenceIsCurrent = false;
                $hasUnscopedExternalReference = filled($purchaseEntry->external_transaction_id) || filled($purchaseEntry->external_import_id);
                if ($manualReconciliation !== null && $purchaseEntry->product !== null && ! $hasUnscopedExternalReference) {
                    try {
                        $manualReconciliationService->assertCurrentAndClassified($manualReconciliation, $purchaseEntry->product, $provider);
                        $manualEvidenceIsCurrent = true;
                    } catch (RuntimeException) {
                        $manualEvidenceIsCurrent = false;
                    }
                }

                $purchaseSourceIsConfirmed = $purchaseEntry->source_status === 'confirmed' && $purchaseEntry->confirmed_at !== null;
                $purchaseSourceIsReadyForManualEvidence = $purchaseEntry->source_status === 'ready' && $manualEvidenceIsCurrent;
                if ((! $purchaseSourceIsConfirmed && ! $purchaseSourceIsReadyForManualEvidence)
                    || ! $this->isCurrent($purchaseEntry) || ! $this->purchaseEvidenceIsClassified($purchaseEntry->product)) {
                    $issues[$saleEntry->id] = 'この商品の仕入れ・期首在庫は未照合です。会計サービス側の記録を確認するまで、この売上仕訳は送信・CSV出力できません。';

                    continue;
                }

                try {
                    app(FurimaDeckInvoiceTransitionCapService::class)->assertEntryWithinCap($purchaseEntry);
                } catch (RuntimeException $exception) {
                    $issues[$saleEntry->id] = $exception->getMessage();

                    continue;
                }

                $purchaseSync = $purchaseEntry->providerSync($provider);
                $connection = $providerConnections->get($saleEntry->user_id);
                $currentScopeHash = $connection === null ? null : $scopeService->fingerprintForConnection($connection, $provider);
                $scopeMatches = $currentScopeHash !== null
                    && is_string($purchaseSync->provider_account_scope_hash)
                    && hash_equals($currentScopeHash, $purchaseSync->provider_account_scope_hash);
                $providerSyncIsUnresolved = in_array($purchaseSync->status, ['queued', 'sending', 'sync_unknown', 'legacy_unknown', 'csv_pending'], true);
                $hasProviderEvidence = ($manualEvidenceIsCurrent && ! $providerSyncIsUnresolved)
                    || ($scopeMatches && match ($purchaseSync->status) {
                        'synced' => filled($purchaseSync->external_transaction_id) && $purchaseSync->verified_at !== null,
                        AccountingEntrySync::STATUS_CSV_IMPORTED => true,
                        AccountingEntrySync::STATUS_MANUALLY_RECONCILED => $purchaseSync->manual_reconciliation_evidence !== null && $purchaseSync->manual_reconciled_at !== null,
                        default => false,
                    });
                if (! $hasProviderEvidence) {
                    $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
                    $issues[$saleEntry->id] = 'この商品の仕入れ・期首在庫は'.$providerName.'側で未照合です。仕入れ記録を確認するまで、この売上仕訳は送信・CSV出力できません。';

                    continue;
                }
            }

            try {
                app(FurimaDeckSaleExpenseAccountingService::class)->assertClassified($saleEntry);
                $connection = $providerConnections->get($saleEntry->user_id);
                app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails(
                    $saleEntry,
                    $provider,
                    (array) ($connection?->settings ?? []),
                );
            } catch (RuntimeException $exception) {
                $issues[$saleEntry->id] = $exception->getMessage();
            }
        }

        return $issues;
    }

    public function isCurrent(AccountingEntry $entry): bool
    {
        if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
            $product = $entry->loadMissing('product')->product;
            if ($product === null || $product->user_id !== $entry->user_id || $product->purchase_date === null) {
                return false;
            }

            try {
                return $this->matches($entry, $this->purchaseAttributes($product));
            } catch (RuntimeException) {
                return false;
            }
        }

        $sale = $entry->loadMissing('sale.marketplace')->sale;

        return $sale !== null
            && $sale->user_id === $entry->user_id
            && in_array($sale->status, Sale::VALID_SOLD_STATUSES, true)
            && $this->profitMatches($sale)
            && $this->matches($entry, $this->attributes($sale));
    }

    private function purchaseEvidenceIsClassified(Product $product): bool
    {
        $evidence = $this->purchaseEvidenceSnapshot($product);
        if ($evidence === null) {
            return false;
        }

        foreach ($evidence as $classification) {
            if ($classification['tax_category'] === 'unknown' || $classification['invoice_issuer'] === 'unknown') {
                return false;
            }
        }

        return true;
    }

    private function profitMatches(Sale $sale): bool
    {
        try {
            return $sale->net_profit === $this->profitCalculator->calculate([
                'sold_price' => (int) $sale->sold_price,
                'cost_basis' => (int) $sale->cost_basis,
                'sales_fee' => (int) $sale->sales_fee,
                'shipping_fee' => (int) $sale->shipping_fee,
                'purchase_shipping_cost' => (int) $sale->purchase_shipping_cost,
                'packing_cost' => (int) $sale->packing_cost,
                'repair_cost' => (int) $sale->repair_cost,
                'cleaning_cost' => (int) $sale->cleaning_cost,
                'other_expense' => (int) $sale->other_expense,
            ]);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function syncSaleEntry(User $user, Sale $sale): AccountingEntry
    {
        $entry = AccountingEntry::query()->firstOrNew([
            'user_id' => $user->id,
            'entry_type' => AccountingEntry::TYPE_SALE,
            'sale_id' => $sale->id,
        ]);
        $attributes = $this->attributes($sale);
        if ($entry->exists && ! $this->matches($entry, $attributes)
            && ($entry->sync_status !== 'not_synced' || $entry->providerSyncs()
                ->whereIn('status', ['queued', 'sending', 'synced', 'sync_unknown', 'legacy_unknown', 'csv_pending', AccountingEntrySync::STATUS_CSV_IMPORTED, AccountingEntrySync::STATUS_MANUALLY_RECONCILED])->exists())) {
            throw new RuntimeException('送信済みまたは送信結果が未確定の会計データと売上記録が異なります。外部会計ソフトと照合してください。');
        }
        if (! $this->profitMatches($sale)) {
            throw new RuntimeException('売上記録の実利益が費用の合計と一致しません。売上内容を確認してください。');
        }
        if (! $entry->exists) {
            $entry->fill($attributes);
            $entry->source_status = 'ready';
            $entry->sync_status = 'not_synced';
            $entry->save();

            return $entry;
        }

        if (! $this->matches($entry, $attributes)) {
            $entry->fill($attributes);
            if ($entry->source_status === 'confirmed') {
                $entry->source_status = 'review';
                $entry->confirmed_at = null;
            }
            $entry->save();
        }

        return $entry;
    }

    /** @param array<string, mixed> $attributes */
    private function matches(AccountingEntry $entry, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $current = $key === 'transaction_date' ? $entry->transaction_date?->toDateString() : $entry->{$key};
            if ($key === 'expense_breakdown' && is_array($current) && is_array($value)) {
                if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                    $current['payment_sources'] ??= [];
                    $value['payment_sources'] ??= [];
                }
                $current = $this->canonicalize($current);
                $value = $this->canonicalize($value);
            }
            if ($current !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function attributes(Sale $sale): array
    {
        $attributes = [
            'transaction_date' => $sale->sold_at->toDateString(),
            'management_id' => $sale->internal_sku_snapshot,
            'title' => $sale->product_name_snapshot,
            'marketplace' => $sale->marketplace?->name,
            'sale_amount' => $sale->sold_price,
            'purchase_cost' => $sale->cost_basis,
            'selling_fee' => $sale->sales_fee,
            'shipping_cost' => $sale->shipping_fee,
            'other_cost' => $sale->purchase_shipping_cost + $sale->packing_cost + $sale->repair_cost + $sale->cleaning_cost + $sale->other_expense,
            'expense_breakdown' => [
                'purchase_shipping_cost' => $sale->purchase_shipping_cost,
                'packing_cost' => $sale->packing_cost,
                'repair_cost' => $sale->repair_cost,
                'cleaning_cost' => $sale->cleaning_cost,
                'other_expense' => $sale->other_expense,
            ],
            'furimadeck_actual_profit' => $sale->net_profit,
            'note' => 'FurimaDeck Sale #'.$sale->id,
        ];
        $saleExpenseDetails = (array) ($sale->accounting_expense_details ?? []);
        $evidence = [];
        foreach (FurimaDeckSaleExpenseAccountingService::EXPENSES as $field => $_label) {
            if ((int) $sale->{$field} <= 0) {
                continue;
            }
            $detail = (array) ($saleExpenseDetails[$field] ?? []);
            if ($detail !== []) {
                $evidence[$field] = array_intersect_key($detail, array_flip([
                    'document_type', 'tax_category', 'invoice_issuer', 'reviewed_at',
                    'date_matches_entry_confirmed', 'cap_review_id',
                ]));
            }
        }
        if ($evidence !== []) {
            $attributes['expense_breakdown']['sale_expenses'] = $evidence;
        }

        return $attributes;
    }

    /** @param array<string|int, mixed> $value @return array<string|int, mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->canonicalize($nested);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function purchaseAttributes(Product $product): array
    {
        if (! $product->purchase_accounting_enabled || $product->purchase_date === null) {
            throw new RuntimeException('新規仕入の証憑情報が不足しています。会計明細は作成していません。');
        }

        $unitCostTotal = (int) $product->purchase_unit_cost * (int) $product->purchase_quantity;
        $purchaseShipping = (int) $product->purchase_shipping_cost;
        $otherExpense = (int) $product->other_purchase_expense;
        $maximumAmount = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;
        if ($unitCostTotal > $maximumAmount || $purchaseShipping > $maximumAmount || $otherExpense > $maximumAmount
            || $unitCostTotal + $purchaseShipping + $otherExpense > $maximumAmount) {
            throw new RuntimeException('仕入金額の合計が会計データの上限を超えています。金額を確認してください。');
        }
        if ($unitCostTotal + $purchaseShipping + $otherExpense <= 0) {
            throw new RuntimeException('仕入金額が0円のため、仕入会計データを作成できません。');
        }
        if ((int) $product->purchase_quantity !== 1) {
            throw new RuntimeException('取得原価の正確な配賦を確認できるまで、会計対象の仕入は1商品1点に限定します。');
        }

        $purchaseEvidence = $this->purchaseEvidenceSnapshot($product);
        if ($purchaseEvidence === null) {
            throw new RuntimeException('仕入単価・仕入送料・その他仕入費の証憑情報または仕訳日との一致確認が不足しています。費用ごとに証憑を確認するまで会計明細は作成できません。');
        }

        return [
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'sale_id' => null,
            'product_id' => $product->id,
            'transaction_date' => $product->purchase_date->toDateString(),
            'management_id' => $product->internal_sku,
            'title' => $product->product_name,
            'marketplace' => null,
            'sale_amount' => 0,
            'purchase_cost' => $unitCostTotal,
            'selling_fee' => 0,
            'shipping_cost' => 0,
            'other_cost' => $purchaseShipping + $otherExpense,
            'expense_breakdown' => [
                'purchase_quantity' => (int) $product->purchase_quantity,
                'purchase_unit_cost' => (int) $product->purchase_unit_cost,
                'purchase_shipping_cost' => $purchaseShipping,
                'other_purchase_expense' => $otherExpense,
                'evidence' => $purchaseEvidence,
                'payment_sources' => (array) ($product->purchase_payment_sources ?? []),
            ],
            'purchase_special_case_data' => $this->purchaseSpecialCaseSnapshot($product, $purchaseEvidence),
            'furimadeck_actual_profit' => 0,
            'note' => 'FurimaDeck Purchase Product #'.$product->id,
        ];
    }

    /** @param array<string, array{document_type: string, tax_category: string, invoice_issuer: string, date_matches_entry_confirmed: bool, reviewed_at: string, cap_review_id?: int}> $purchaseEvidence */
    private function purchaseSpecialCaseSnapshot(Product $product, array $purchaseEvidence): ?array
    {
        $data = $product->purchase_special_case_data;
        if ($data === null) {
            return null;
        }

        if (($data['scheme'] ?? null) !== 'antique_dealer'
            || trim((string) ($data['license_number'] ?? '')) === ''
            || trim((string) ($data['counterparty_name'] ?? '')) === ''
            || trim((string) ($data['counterparty_address'] ?? '')) === ''
            || trim((string) ($data['confirmed_at'] ?? '')) === ''
            || ($purchaseEvidence['purchase_unit_cost']['invoice_issuer'] ?? null) !== 'unqualified'
            || ! in_array($purchaseEvidence['purchase_unit_cost']['tax_category'] ?? null, ['standard_10', 'reduced_8'], true)) {
            throw new RuntimeException('古物商等の特例情報が不足または証憑区分と一致しません。仕入明細を保留しました。');
        }

        app(FurimaDeckPurchaseSpecialCaseService::class)->assertConfirmations($data, $product->purchase_date?->toDateString());

        return $data;
    }

    /** @return array<string, array{document_type: string, tax_category: string, invoice_issuer: string, date_matches_entry_confirmed: bool, reviewed_at: string, cap_review_id?: int}>|null */
    private function purchaseEvidenceSnapshot(Product $product): ?array
    {
        $evidenceByExpense = (array) ($product->purchase_evidence ?? []);
        $amounts = [
            'purchase_unit_cost' => (int) $product->purchase_unit_cost * (int) $product->purchase_quantity,
            'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
            'other_purchase_expense' => (int) $product->other_purchase_expense,
        ];
        $snapshot = [];

        foreach ($amounts as $expenseKey => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $evidence = $evidenceByExpense[$expenseKey] ?? null;
            if (! is_array($evidence)
                || ! in_array($evidence['document_type'] ?? null, Product::PURCHASE_DOCUMENT_TYPES, true)
                || ! in_array($evidence['tax_category'] ?? null, Product::PURCHASE_TAX_CATEGORIES, true)
                || ! in_array($evidence['invoice_issuer'] ?? null, Product::PURCHASE_INVOICE_ISSUERS, true)
                || ($evidence['date_matches_entry_confirmed'] ?? false) !== true
                || ! is_string($evidence['reviewed_at'] ?? null)
                || trim($evidence['reviewed_at']) === '') {
                return null;
            }

            $snapshot[$expenseKey] = [
                'document_type' => $evidence['document_type'],
                'tax_category' => $evidence['tax_category'],
                'invoice_issuer' => $evidence['invoice_issuer'],
                'date_matches_entry_confirmed' => true,
                'reviewed_at' => $evidence['reviewed_at'],
                ...(isset($evidence['cap_review_id']) ? ['cap_review_id' => (int) $evidence['cap_review_id']] : []),
            ];
        }

        return $snapshot === [] ? null : $snapshot;
    }
}
