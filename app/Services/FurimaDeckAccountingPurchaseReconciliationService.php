<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\ManualProductPurchaseReconciliation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FurimaDeckAccountingPurchaseReconciliationService
{
    public const PROVIDERS = ['freee', 'money_forward'];

    /** @var array<string, string> */
    public const EXPENSES = [
        'purchase_unit_cost' => '商品代',
        'purchase_shipping_cost' => '仕入送料',
        'other_purchase_expense' => 'その他仕入費',
    ];

    public const ACCOUNTING_TREATMENTS = [
        'inventory' => '在庫として計上済み',
        'expense' => '仕入時に費用として計上済み',
    ];

    public function latestFor(Product $product, string $provider): ?ManualProductPurchaseReconciliation
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return null;
        }

        return ManualProductPurchaseReconciliation::query()
            ->where('user_id', $product->user_id)
            ->where('product_id', $product->id)
            ->where('provider', $provider)
            ->first();
    }

    /** @return Collection<string, ManualProductPurchaseReconciliation> */
    public function forProduct(Product $product): Collection
    {
        return ManualProductPurchaseReconciliation::query()
            ->where('user_id', $product->user_id)
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('provider');
    }

    public function isCurrent(ManualProductPurchaseReconciliation $reconciliation, Product $product, string $provider): bool
    {
        if (! ($reconciliation->user_id === $product->user_id
            && $reconciliation->product_id === $product->id
            && $reconciliation->provider === $provider
            && hash_equals($reconciliation->product_snapshot_hash, $this->snapshotHash($product)))) {
            return false;
        }

        $evidence = $reconciliation->latestEvidence();
        if ($evidence === null || ! $this->evidenceIsClassified($product, $evidence)) {
            return false;
        }
        if (! $this->evidenceMatchesCurrentAccount((int) $product->user_id, $provider, $evidence)) {
            return false;
        }

        return $this->allocationStatus($reconciliation, $product, $provider, $evidence)['is_complete'];
    }

    /** @return array<string, int> */
    public function expectedAmounts(Product $product): array
    {
        $quantity = (int) $product->purchase_quantity;
        $unitCost = (int) $product->purchase_unit_cost;
        $shipping = (int) $product->purchase_shipping_cost;
        $other = (int) $product->other_purchase_expense;
        $maximum = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;

        if ($quantity < 1 || $unitCost < 0 || $shipping < 0 || $other < 0
            || $unitCost > intdiv($maximum, $quantity)) {
            throw new RuntimeException('商品に登録された仕入数量・金額が確認できません。仕入データを確認するまで照合できません。');
        }

        $unitTotal = $unitCost * $quantity;
        if ($shipping > $maximum - $unitTotal || $other > $maximum - $unitTotal - $shipping) {
            throw new RuntimeException('商品に登録された仕入金額の合計が上限を超えています。金額を確認するまで照合できません。');
        }

        return [
            'purchase_unit_cost' => $unitTotal,
            'purchase_shipping_cost' => $shipping,
            'other_purchase_expense' => $other,
        ];
    }

    public function costOfGoodsSoldForSale(AccountingEntry $saleEntry, string $provider): int
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('会計サービスの指定が正しくありません。');
        }

        $sale = $saleEntry->loadMissing('sale.product')->sale;
        $product = $sale?->product;
        if ($sale === null || $product === null || $sale->user_id !== $saleEntry->user_id
            || $product->user_id !== $saleEntry->user_id) {
            throw new RuntimeException('販売商品の仕入れ記録を確認できません。原価仕訳を送信していません。');
        }

        if ($product->purchase_accounting_enabled) {
            return (int) $saleEntry->purchase_cost;
        }

        $reconciliation = $this->latestFor($product, $provider);
        if ($reconciliation === null) {
            throw new RuntimeException('仕入の会計上の扱いが未照合です。原価仕訳を送信していません。');
        }
        $this->assertCurrentAndClassified($reconciliation, $product, $provider);
        $evidence = $reconciliation->latestEvidence();
        if ($evidence === null) {
            throw new RuntimeException('仕入の会計上の扱いを確認できません。原価仕訳を送信していません。');
        }

        $cost = 0;
        $maximum = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;
        foreach ($this->expectedAmounts($product) as $field => $amount) {
            if ($amount === 0) {
                continue;
            }

            $treatment = data_get($evidence, 'expense_evidence.'.$field.'.accounting_treatment');
            if (! in_array($treatment, array_keys(self::ACCOUNTING_TREATMENTS), true)) {
                throw new RuntimeException(self::EXPENSES[$field].'の会計上の扱いが未確認です。原価仕訳を送信していません。');
            }
            if ($treatment === 'inventory') {
                if ($amount > $maximum - $cost) {
                    throw new RuntimeException('在庫原価が上限を超えています。原価仕訳を送信していません。');
                }
                $cost += $amount;
            }
        }

        return $cost;
    }

    /** @return array{allocated_total:int,transaction_total:int,product_count:int,is_complete:bool} */
    public function allocationStatus(
        ManualProductPurchaseReconciliation $reconciliation,
        Product $product,
        string $provider,
        ?array $currentEvidence = null,
    ): array {
        $empty = ['allocated_total' => 0, 'transaction_total' => 0, 'product_count' => 0, 'is_complete' => false];
        $evidence = $currentEvidence ?? $reconciliation->latestEvidence();
        $externalId = trim((string) data_get($evidence, 'external_transaction_id', ''));
        if ($evidence === null || $externalId === '' || ! in_array($provider, self::PROVIDERS, true)) {
            return $empty;
        }
        $accountId = $this->currentAccountId((int) $product->user_id, $provider);
        $accountScopeHashes = $accountId === null ? [] : $this->scopeService()->compatibleFingerprints($provider, $accountId);
        if ($accountId === null || $accountScopeHashes === []
            || ! $this->scopeService()->matchesManualEvidence(
                (string) data_get($evidence, 'account_scope_hash', ''),
                $provider,
                $accountId,
            )) {
            return $empty;
        }

        $externalReferenceHashes = array_map(
            fn (string $scopeHash): string => $this->externalReferenceHash($provider, $scopeHash, $externalId),
            $accountScopeHashes,
        );
        $references = DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')
            ->where('user_id', $product->user_id)
            ->where('provider', $provider)
            ->whereIn('external_transaction_id_hash', $externalReferenceHashes)
            ->get();
        if ($references->isEmpty() || $references->groupBy('product_id')->contains(fn ($rows): bool => $rows->count() > 1)) {
            return $empty;
        }

        $recordsByProduct = ManualProductPurchaseReconciliation::query()
            ->where('user_id', $product->user_id)
            ->where('provider', $provider)
            ->whereIn('product_id', $references->pluck('product_id')->all())
            ->get()
            ->keyBy(fn (ManualProductPurchaseReconciliation $record): int => (int) $record->product_id);
        $productsById = Product::query()
            ->where('user_id', $product->user_id)
            ->whereIn('id', $references->pluck('product_id')->all())
            ->get()
            ->keyBy(fn (Product $referencedProduct): int => (int) $referencedProduct->id);
        $transactionTotal = null;
        $allocatedTotal = 0;
        $transactionDate = null;
        $maximum = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;

        foreach ($references as $reference) {
            $referencedProductId = (int) $reference->product_id;
            $referencedProduct = $productsById->get($referencedProductId);
            $referencedRecord = $recordsByProduct->get($referencedProductId);
            if ($referencedProduct === null || $referencedRecord === null
                || ! hash_equals($referencedRecord->product_snapshot_hash, $this->snapshotHash($referencedProduct))) {
                return $empty;
            }

            $referenceEvidence = $referencedProductId === (int) $product->id
                ? $evidence
                : $referencedRecord->latestEvidence();
            if ($referenceEvidence === null
                || trim((string) data_get($referenceEvidence, 'external_transaction_id', '')) !== $externalId
                || ! $this->scopeService()->matchesManualEvidence(
                    (string) data_get($referenceEvidence, 'account_scope_hash', ''),
                    $provider,
                    $accountId,
                )
                || ! $this->evidenceIsClassified($referencedProduct, $referenceEvidence)) {
                return $empty;
            }

            $recordedDate = (string) data_get($referenceEvidence, 'transaction_date', '');
            if ($recordedDate === '' || ($transactionDate !== null && $transactionDate !== $recordedDate)) {
                return $empty;
            }
            $transactionDate = $recordedDate;

            if ($reference->transaction_total !== null) {
                $recordedTotal = (int) $reference->transaction_total;
                if ($recordedTotal < 1 || ($transactionTotal !== null && $transactionTotal !== $recordedTotal)) {
                    return $empty;
                }
                $transactionTotal = $recordedTotal;
            } else {
                $recordedTotal = (int) data_get($referenceEvidence, 'transaction_total', data_get($referenceEvidence, 'debit_total', 0));
                if ($recordedTotal < 1 || ($transactionTotal !== null && $transactionTotal !== $recordedTotal)) {
                    return $empty;
                }
                $transactionTotal = $recordedTotal;
            }

            $allocatedAmount = $reference->allocated_amount === null
                ? (int) data_get($referenceEvidence, 'purchase_amount', 0)
                : (int) $reference->allocated_amount;
            try {
                $expectedAmount = array_sum($this->expectedAmounts($referencedProduct));
            } catch (RuntimeException) {
                return $empty;
            }
            if ($allocatedAmount !== $expectedAmount
                || (int) data_get($referenceEvidence, 'purchase_amount', 0) !== $allocatedAmount
                || (int) data_get($referenceEvidence, 'debit_total', $transactionTotal) !== $transactionTotal
                || (int) data_get($referenceEvidence, 'credit_total', $transactionTotal) !== $transactionTotal) {
                return $empty;
            }
            if ($allocatedAmount < 1 || $allocatedAmount > $maximum - $allocatedTotal) {
                return $empty;
            }
            $allocatedTotal += $allocatedAmount;
        }

        if ($transactionTotal === null || $allocatedTotal > $transactionTotal) {
            return $empty;
        }

        return [
            'allocated_total' => $allocatedTotal,
            'transaction_total' => $transactionTotal,
            'product_count' => $references->count(),
            'is_complete' => $allocatedTotal === $transactionTotal,
        ];
    }

    /** @param array<string, mixed> $input */
    public function record(User $user, Product $product, string $provider, array $input): ManualProductPurchaseReconciliation
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('会計サービスの指定が正しくありません。');
        }
        if ((int) $product->user_id !== (int) $user->id) {
            throw new RuntimeException('手動照合はご本人の商品に限り利用できます。');
        }

        return DB::connection('furimadeck')->transaction(function () use ($user, $product, $provider, $input): ManualProductPurchaseReconciliation {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $purchaseEntry = AccountingEntry::query()
                ->where('user_id', $user->id)
                ->where('product_id', $lockedProduct->id)
                ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
                ->lockForUpdate()
                ->first();

            if ($lockedProduct->purchase_accounting_enabled) {
                if ($purchaseEntry === null) {
                    throw new RuntimeException('新規仕入の会計明細がありません。会計データの仕入画面で仕入情報を保存してから、会計サービス側の仕入記録を照合してください。');
                }

                $purchaseEntry->setRelation('product', $lockedProduct);
                if (! app(FurimaDeckAccountingEntryService::class)->isCurrent($purchaseEntry)
                    || ! in_array($purchaseEntry->source_status, ['ready', 'confirmed'], true)
                    || ($purchaseEntry->source_status === 'confirmed' && $purchaseEntry->confirmed_at === null)) {
                    throw new RuntimeException('仕入会計明細が未確認、または商品情報と一致しません。会計データの仕入明細を確認してから手動照合してください。');
                }

                if (filled($purchaseEntry->external_transaction_id) || filled($purchaseEntry->external_import_id)) {
                    throw new RuntimeException('仕入会計明細に事業者を特定できない外部記録IDがあります。誤照合を防ぐため、現在の状態では手動照合できません。');
                }

                $purchaseSync = $purchaseEntry->providerSyncs()
                    ->where('provider', $provider)
                    ->lockForUpdate()
                    ->first();
                if ($purchaseSync !== null && ! in_array($purchaseSync->status, ['not_synced', 'failed'], true)) {
                    throw new RuntimeException('この会計サービスには仕入明細の送信・取込・結果不明の記録があります。重複を防ぐため、状態を確認するまで手動照合できません。');
                }
            } elseif ($purchaseEntry !== null) {
                throw new RuntimeException('この商品には仕入会計明細があります。商品情報と明細の状態を確認してから照合してください。');
            }

            $connection = AccountingConnection::on('furimadeck')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->whereIn('status', ['connected', 'expired'])
                ->lockForUpdate()
                ->first();
            $accountId = $connection === null ? null : $this->scopeService()->accountIdForConnection($connection, $provider);
            $accountScopeHash = $this->scopeService()->fingerprint($provider, $accountId);
            if ($accountScopeHash === null) {
                throw new RuntimeException('会計サービスの接続先事業者を特定できません。先に接続を確認し、事業者が表示された状態で仕入れを照合してください。');
            }

            $amounts = $this->expectedAmounts($lockedProduct);
            $expectedTotal = array_sum($amounts);
            if ($expectedTotal < 1) {
                throw new RuntimeException('商品に仕入金額がないため、既存仕訳と照合できません。');
            }
            $transactionTotal = (int) $input['debit_total'];
            if ($transactionTotal !== (int) $input['credit_total']) {
                throw new RuntimeException('仕訳全体の借方・貸方合計が一致していません。');
            }
            if ($transactionTotal < $expectedTotal) {
                throw new RuntimeException('この商品への配分額 '.number_format($expectedTotal).'円が、仕訳全体の合計を超えています。');
            }

            $transactionDate = (string) $input['transaction_date'];
            if ($lockedProduct->purchase_date !== null && $lockedProduct->purchase_date->toDateString() !== $transactionDate) {
                throw new RuntimeException('会計サービス側の取引日は商品に登録された仕入日と一致しません。商品情報と仕訳を確認してください。');
            }

            $expenseEvidence = (array) ($input['expense_evidence'] ?? []);
            foreach ($amounts as $field => $amount) {
                if ($amount === 0) {
                    continue;
                }
                $line = (array) ($expenseEvidence[$field] ?? []);
                if (! in_array($line['document_type'] ?? null, Product::PURCHASE_DOCUMENT_TYPES, true)
                    || ! in_array($line['tax_category'] ?? null, array_diff(Product::PURCHASE_TAX_CATEGORIES, ['unknown']), true)
                    || ! in_array($line['invoice_issuer'] ?? null, array_diff(Product::PURCHASE_INVOICE_ISSUERS, ['unknown']), true)
                    || ! in_array($line['accounting_treatment'] ?? null, array_keys(self::ACCOUNTING_TREATMENTS), true)
                    || trim((string) ($line['external_tax_classification'] ?? '')) === ''
                    || ($line['date_matches_entry_confirmed'] ?? false) !== true) {
                    throw new RuntimeException(self::EXPENSES[$field].'の証憑・税区分・発行者区分・会計上の扱い・日付の確認が不足しています。');
                }
                $line['external_tax_classification'] = trim((string) $line['external_tax_classification']);
                $line['reviewed_at'] = now()->toIso8601String();
                $expenseEvidence[$field] = $line;
            }

            $snapshotHash = $this->snapshotHash($lockedProduct);
            $reconciliation = ManualProductPurchaseReconciliation::query()
                ->where('user_id', $user->id)
                ->where('product_id', $lockedProduct->id)
                ->where('provider', $provider)
                ->lockForUpdate()
                ->first();
            $externalId = trim((string) $input['external_transaction_id']);
            $compatibleScopeHashes = $this->scopeService()->compatibleFingerprints($provider, $accountId);
            $externalIdHashes = array_map(
                fn (string $scopeHash): string => $this->externalReferenceHash($provider, $scopeHash, $externalId),
                $compatibleScopeHashes,
            );
            $externalIdHash = $this->externalReferenceHash($provider, $accountScopeHash, $externalId);
            $previousProductReferences = DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->where('product_id', $lockedProduct->id)
                ->lockForUpdate()
                ->get();
            foreach ($previousProductReferences as $previousProductReference) {
                if (! in_array((string) $previousProductReference->external_transaction_id_hash, $externalIdHashes, true)) {
                    DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')
                        ->where('id', $previousProductReference->id)
                        ->delete();
                }
            }
            $references = DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->whereIn('external_transaction_id_hash', $externalIdHashes)
                ->lockForUpdate()
                ->get();
            if ($references->groupBy('product_id')->contains(fn ($rows): bool => $rows->count() > 1)) {
                throw new RuntimeException('同じ仕訳に同一商品が重複して配分されています。仕訳全体を確認するまで照合を保留しました。');
            }
            $reference = $references->firstWhere('product_id', $lockedProduct->id);

            $referencedProductIds = $references->pluck('product_id')->map(fn ($id): int => (int) $id)->all();
            $existingEvidenceByProduct = ManualProductPurchaseReconciliation::query()
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->whereIn('product_id', $referencedProductIds)
                ->get()
                ->mapWithKeys(fn (ManualProductPurchaseReconciliation $record): array => [
                    (int) $record->product_id => $record->latestEvidence() ?? [],
                ]);

            $previousTotalForCurrentProduct = null;
            $previousAllocationForCurrentProduct = null;
            foreach ($references as $existingReference) {
                $existingEvidence = $existingEvidenceByProduct->get((int) $existingReference->product_id, []);
                $existingTotal = $existingReference->transaction_total === null
                    ? (int) data_get($existingEvidence, 'transaction_total', data_get($existingEvidence, 'debit_total', 0))
                    : (int) $existingReference->transaction_total;
                $existingAllocation = $existingReference->allocated_amount === null
                    ? (int) data_get($existingEvidence, 'purchase_amount', 0)
                    : (int) $existingReference->allocated_amount;
                if ($existingTotal < 1 || $existingAllocation < 1
                    || ($existingEvidence['transaction_date'] ?? null) !== $transactionDate
                    || ! $this->scopeService()->matchesManualEvidence(
                        (string) data_get($existingEvidence, 'account_scope_hash', ''),
                        $provider,
                        $accountId,
                    )
                    || (int) data_get($existingEvidence, 'debit_total', $existingTotal) !== $existingTotal
                    || (int) data_get($existingEvidence, 'credit_total', $existingTotal) !== $existingTotal) {
                    throw new RuntimeException('同じ仕訳IDで保存済みの取引日・仕訳全体の借貸合計を確認できません。仕訳を確認してください。');
                }

                if ((int) $existingReference->product_id === (int) $lockedProduct->id) {
                    $previousTotalForCurrentProduct = $existingTotal;
                    $previousAllocationForCurrentProduct = $existingAllocation;
                } elseif ($existingTotal !== $transactionTotal) {
                    throw new RuntimeException('同じ仕訳IDで保存済みの仕訳全体の金額と一致しません。仕訳を確認してください。');
                }
            }

            $canExpandSingleProductTotal = $references->count() === 1
                && $reference !== null
                && $previousTotalForCurrentProduct !== null
                && $previousTotalForCurrentProduct === $previousAllocationForCurrentProduct
                && $transactionTotal > $previousTotalForCurrentProduct;
            if ($previousTotalForCurrentProduct !== null
                && $previousTotalForCurrentProduct !== $transactionTotal
                && ! $canExpandSingleProductTotal) {
                throw new RuntimeException('同じ仕訳IDで保存済みの仕訳全体の金額は変更できません。複数商品の配分では、全商品で同じ仕訳合計を入力してください。');
            }

            if ($reconciliation !== null && $reference !== null
                && ($reconciliation->latestEvidence()['external_transaction_id'] ?? null) === $externalId
                && $previousTotalForCurrentProduct === $transactionTotal
                && $this->isCurrent($reconciliation, $lockedProduct, $provider)) {
                throw new RuntimeException('この商品はすでに現在の仕入情報と照合済みです。重複登録はしていません。');
            }

            $allocatedByOtherProducts = 0;
            $maximum = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;
            foreach ($references as $existingReference) {
                if ((int) $existingReference->product_id === (int) $lockedProduct->id) {
                    continue;
                }

                $existingEvidence = $existingEvidenceByProduct->get((int) $existingReference->product_id, []);
                $allocation = $existingReference->allocated_amount === null
                    ? (int) data_get($existingEvidence, 'purchase_amount', 0)
                    : (int) $existingReference->allocated_amount;
                if ($allocation < 1 || $allocation > $maximum - $allocatedByOtherProducts) {
                    throw new RuntimeException('既存商品の配分額を確認できません。仕訳全体を確認するまで照合を保留しました。');
                }
                $otherProduct = Product::query()->where('user_id', $user->id)->find((int) $existingReference->product_id);
                $otherReconciliation = ManualProductPurchaseReconciliation::query()
                    ->where('user_id', $user->id)
                    ->where('product_id', $existingReference->product_id)
                    ->where('provider', $provider)
                    ->first();
                if ($otherProduct === null || $otherReconciliation === null
                    || ! hash_equals($otherReconciliation->product_snapshot_hash, $this->snapshotHash($otherProduct))
                    || ! $this->evidenceIsClassified($otherProduct, $existingEvidence)
                    || array_sum($this->expectedAmounts($otherProduct)) !== $allocation) {
                    throw new RuntimeException('配分済みの別商品情報が変更されています。先にその商品の仕入証跡を再確認してください。');
                }
                $allocatedByOtherProducts += $allocation;
            }
            if ($expectedTotal > $transactionTotal - $allocatedByOtherProducts) {
                throw new RuntimeException('この商品への配分を加えると仕訳全体の合計を超えます。商品ごとの金額配分を確認してください。');
            }

            foreach ($references as $existingReference) {
                $existingEvidence = $existingEvidenceByProduct->get((int) $existingReference->product_id, []);
                $allocatedAmount = $existingReference->allocated_amount === null
                    ? (int) data_get($existingEvidence, 'purchase_amount', 0)
                    : (int) $existingReference->allocated_amount;
                if ($allocatedAmount < 1) {
                    throw new RuntimeException('既存商品の配分額を確認できません。仕訳全体を確認するまで照合を保留しました。');
                }
                DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')
                    ->where('id', $existingReference->id)
                    ->update([
                        'transaction_total' => $transactionTotal,
                        'allocated_amount' => $allocatedAmount,
                    ]);
            }

            if ($reference === null) {
                DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')->insert([
                    'user_id' => $user->id,
                    'product_id' => $lockedProduct->id,
                    'provider' => $provider,
                    'external_transaction_id_hash' => $externalIdHash,
                    'transaction_total' => $transactionTotal,
                    'allocated_amount' => $expectedTotal,
                    'created_at' => now(),
                ]);
            } else {
                DB::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs')
                    ->where('id', $reference->id)
                    ->update([
                        'transaction_total' => $transactionTotal,
                        'allocated_amount' => $expectedTotal,
                    ]);
            }

            $evidence = [
                'external_transaction_id' => $externalId,
                'account_scope_hash' => $accountScopeHash,
                'transaction_date' => $transactionDate,
                'purchase_amount' => $expectedTotal,
                'transaction_total' => $transactionTotal,
                'debit_total' => (int) $input['debit_total'],
                'credit_total' => (int) $input['credit_total'],
                'debit_accounts' => trim((string) $input['debit_accounts']),
                'credit_accounts' => trim((string) $input['credit_accounts']),
                'expense_evidence' => $expenseEvidence,
                'reviewed_by_user_id' => $user->id,
                'reviewed_at' => now()->toIso8601String(),
                'product_snapshot_hash' => $snapshotHash,
            ];
            app(FurimaDeckInvoiceTransitionCapService::class)->assertManualPurchaseEvidenceWithinCap(
                (int) $user->id,
                $lockedProduct,
                $transactionDate,
                $expenseEvidence,
            );

            if ($reconciliation === null) {
                $reconciliation = new ManualProductPurchaseReconciliation([
                    'user_id' => $user->id,
                    'product_id' => $lockedProduct->id,
                    'provider' => $provider,
                    'product_snapshot_hash' => $snapshotHash,
                    'evidence_history' => [$evidence],
                    'reviewed_by_user_id' => $user->id,
                    'reviewed_at' => now(),
                ]);
                $reconciliation->save();
            } else {
                $history = (array) $reconciliation->evidence_history;
                $history[] = $evidence;
                $reconciliation->update([
                    'product_snapshot_hash' => $snapshotHash,
                    'evidence_history' => $history,
                    'reviewed_by_user_id' => $user->id,
                    'reviewed_at' => now(),
                ]);
            }

            return $reconciliation->refresh();
        });
    }

    public function assertCurrentAndClassified(ManualProductPurchaseReconciliation $reconciliation, Product $product, string $provider): void
    {
        if (! ($reconciliation->user_id === $product->user_id
            && $reconciliation->product_id === $product->id
            && $reconciliation->provider === $provider
            && hash_equals($reconciliation->product_snapshot_hash, $this->snapshotHash($product)))) {
            throw new RuntimeException('商品情報の変更後に再確認されていない手動照合です。仕入記録を確認するまで売上仕訳を保留しました。');
        }
        $evidence = $reconciliation->latestEvidence();
        if ($evidence === null) {
            throw new RuntimeException('手動照合の証跡を確認できません。');
        }
        if (! $this->evidenceMatchesCurrentAccount((int) $product->user_id, $provider, $evidence)) {
            throw new RuntimeException('手動照合した会計事業者が現在の接続先と一致しません。現在接続中の事業者で仕入れ記録を確認し、手動照合をやり直すまで売上仕訳を保留しました。');
        }
        if ($evidence === null || ! $this->evidenceIsClassified($product, $evidence)
            || (int) ($evidence['purchase_amount'] ?? 0) !== array_sum($this->expectedAmounts($product))) {
            throw new RuntimeException('手動照合の仕入金額が現在の商品情報と一致しません。');
        }
        $allocation = $this->allocationStatus($reconciliation, $product, $provider, $evidence);
        if (! $allocation['is_complete']) {
            if ($allocation['product_count'] > 0 && $allocation['transaction_total'] > $allocation['allocated_total']) {
                throw new RuntimeException('仕入仕訳の複数商品への配分が未完了です（'.number_format($allocation['allocated_total']).'円 / 仕訳全体 '.number_format($allocation['transaction_total']).'円、'.$allocation['product_count'].'商品）。残額を商品ごとに配分し、合計が仕訳全体と一致するまで売上仕訳を送信・CSV出力できません。');
            }

            throw new RuntimeException('この仕入仕訳に紐づく別商品の証跡・配分金額を確認できません。仕訳を確認するまで売上仕訳を保留しました。');
        }
        app(FurimaDeckInvoiceTransitionCapService::class)->assertManualPurchaseEvidenceWithinCap(
            (int) $product->user_id,
            $product,
            (string) ($evidence['transaction_date'] ?? ''),
            (array) ($evidence['expense_evidence'] ?? []),
        );
    }

    public function snapshotHash(Product $product): string
    {
        $snapshot = [
            'user_id' => (int) $product->user_id,
            'product_id' => (int) $product->id,
            'purchase_accounting_enabled' => (bool) $product->purchase_accounting_enabled,
            'internal_sku' => (string) $product->internal_sku,
            'product_name' => (string) $product->product_name,
            'purchase_date' => $product->purchase_date?->toDateString(),
            'purchase_unit_cost' => (int) $product->purchase_unit_cost,
            'purchase_quantity' => (int) $product->purchase_quantity,
            'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
            'other_purchase_expense' => (int) $product->other_purchase_expense,
            'purchase_evidence' => $product->purchase_evidence,
            'supplier_id' => $product->supplier_id === null ? null : (int) $product->supplier_id,
            'purchase_special_case_data' => $product->purchase_special_case_data,
        ];

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function currentAccountId(int $userId, string $provider): ?string
    {
        $connection = $this->currentAccountConnection($userId, $provider);

        return $connection === null ? null : $this->scopeService()->accountIdForConnection($connection, $provider);
    }

    private function currentAccountConnection(int $userId, string $provider): ?AccountingConnection
    {
        return AccountingConnection::on('furimadeck')
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->whereIn('status', ['connected', 'expired'])
            ->first();
    }

    private function scopeService(): FurimaDeckAccountingProviderScopeService
    {
        return app(FurimaDeckAccountingProviderScopeService::class);
    }

    private function evidenceMatchesCurrentAccount(int $userId, string $provider, array $evidence): bool
    {
        $accountId = $this->currentAccountId($userId, $provider);
        $recordedScopeHash = (string) data_get($evidence, 'account_scope_hash', '');

        return $accountId !== null
            && $this->scopeService()->matchesManualEvidence($recordedScopeHash, $provider, $accountId);
    }

    private function externalReferenceHash(string $provider, string $accountScopeHash, string $externalId): string
    {
        return hash('sha256', $provider."\0".$accountScopeHash."\0".$externalId);
    }

    /** @param array<string, mixed> $evidence */
    private function evidenceIsClassified(Product $product, array $evidence): bool
    {
        try {
            foreach ($this->expectedAmounts($product) as $field => $amount) {
                if ($amount === 0) {
                    continue;
                }

                $line = (array) data_get($evidence, 'expense_evidence.'.$field, []);
                if (! in_array($line['document_type'] ?? null, Product::PURCHASE_DOCUMENT_TYPES, true)
                    || ! in_array($line['tax_category'] ?? null, array_diff(Product::PURCHASE_TAX_CATEGORIES, ['unknown']), true)
                    || ! in_array($line['invoice_issuer'] ?? null, array_diff(Product::PURCHASE_INVOICE_ISSUERS, ['unknown']), true)
                    || ! in_array($line['accounting_treatment'] ?? null, array_keys(self::ACCOUNTING_TREATMENTS), true)
                    || trim((string) ($line['external_tax_classification'] ?? '')) === ''
                    || ($line['date_matches_entry_confirmed'] ?? false) !== true) {
                    return false;
                }
            }
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
