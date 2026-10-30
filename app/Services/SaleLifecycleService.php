<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Listing;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaleLifecycleService
{
    private const ACCOUNTING_CORRECTION_BLOCKING_STATUSES = [
        'queued', 'sending', 'synced', 'sync_unknown', 'legacy_unknown', 'csv_pending',
        AccountingEntrySync::STATUS_CSV_IMPORTED,
        AccountingEntrySync::STATUS_MANUALLY_RECONCILED,
    ];

    private const ACCOUNTING_CORRECTION_BLOCK_MESSAGE = 'この販売記録は会計サービスへ送信・取込済み、送信中、または処理結果が未確定のため、金額を直接修正できません。外部帳簿との不一致や二重計上を防ぐため、会計サービス側の仕訳を確認してから訂正してください。売上の取消・再登録が必要な場合、取消・返品の調整は会計データ画面の照合用CSVから登録してください。外部仕訳は自動変更されません。';

    public function __construct(
        private readonly SaleProfitCalculator $profitCalculator,
        private readonly FurimaDeckAccountingEntryService $accountingEntries,
    ) {}

    /** @param array<string, mixed> $values */
    public function record(User $user, array $values): Sale
    {
        return DB::transaction(function () use ($user, $values): Sale {
            $product = Product::query()->whereKey($values['product_id'])->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (! $product->purchase_info_confirmed) {
                throw new RuntimeException('売上を記録する前に、会計データ画面で仕入情報を入力・確認してください。');
            }
            $quantity = (int) $values['quantity'];

            if ($quantity > $product->quantity_available) {
                throw new RuntimeException('販売数が利用可能な在庫数を超えています。');
            }

            $listing = $this->lockedListing($user, $product, $values['listing_id'] ?? null);
            $marketplaceId = $listing?->marketplace_id ?? (int) $values['marketplace_id'];
            $profitValues = collect($values)->only([
                'sold_price', 'cost_basis', 'sales_fee', 'shipping_fee', 'purchase_shipping_cost',
                'packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense',
            ])->map(fn ($value) => (int) ($value ?? 0))->all();
            if (! array_key_exists('cost_basis', $values)) {
                $profitValues['cost_basis'] = app(FurimaDeckAccountingEntryService::class)->costBasisForNewSale($user, $product);
            }
            if ($product->purchase_accounting_enabled) {
                $profitValues['cost_basis'] = app(FurimaDeckAccountingEntryService::class)->costBasisForNewSale($user, $product);
                $profitValues['purchase_shipping_cost'] = 0;
            }
            $expenseDetails = app(FurimaDeckSaleExpenseAccountingService::class)->normalize(
                $user,
                $profitValues,
                (array) ($values['accounting_expense_details'] ?? []),
            );

            $sale = $user->sales()->create($profitValues + [
                'product_id' => $product->id,
                'listing_id' => $listing?->id,
                'marketplace_id' => $marketplaceId,
                'sales_fee_rate' => (float) ($values['sales_fee_rate'] ?? 0),
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => $quantity,
                'sold_at' => $values['sold_at'],
                'status' => Sale::STATUSES[1],
                'accounting_expense_details' => $expenseDetails,
                'net_profit' => $this->profitCalculator->calculate($profitValues),
            ]);

            $product->decrement('quantity_available', $quantity);
            $product->refresh();

            $product->syncInventoryStatus();
            $product->save();

            if ($listing !== null) {
                $listing->update(['status' => 'sold', 'ended_at' => now()]);
            }

            $this->accountingEntries->recordSale($user, $sale);

            return $sale;
        });
    }

    public function cancel(User $user, Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($user, $sale, $reason): Sale {
            $lockedSale = Sale::query()->whereKey($sale->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedSale->status, ['cancelled', 'returned'], true)) {
                throw new RuntimeException('この取引はすでに取り消し済みです。');
            }
            $this->assertNoAccountingSendInProgress($user, $lockedSale);
            if ($lockedSale->status !== 'pending') {
                $product = Product::query()->whereKey($lockedSale->product_id)->lockForUpdate()->firstOrFail();
                $product->increment('quantity_available', $lockedSale->quantity);
                $product->syncInventoryStatus();
                $product->save();
            }
            $lockedSale->update([
                'status' => 'cancelled', 'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'cancelled_from_pending' => $lockedSale->status === 'pending',
            ]);

            return $lockedSale;
        });
    }

    public function returnSale(User $user, Sale $sale, string $reason, int $refundAmount, int $returnShippingFee, bool $restock): Sale
    {
        return DB::transaction(function () use ($user, $sale, $reason, $refundAmount, $returnShippingFee, $restock): Sale {
            $lockedSale = Sale::query()->whereKey($sale->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedSale->status, Sale::VALID_SOLD_STATUSES, true)) {
                throw new RuntimeException('この取引は返品処理できません。');
            }
            $this->assertNoAccountingSendInProgress($user, $lockedSale);
            $restockedQuantity = $restock ? $lockedSale->quantity : 0;
            if ($restock) {
                $product = Product::query()->whereKey($lockedSale->product_id)->lockForUpdate()->firstOrFail();
                $product->increment('quantity_available', $restockedQuantity);
                $product->syncInventoryStatus();
                $product->save();
            }
            $lockedSale->update(['status' => 'returned', 'returned_at' => now(), 'return_reason' => $reason, 'refund_amount' => $refundAmount, 'return_shipping_fee' => $returnShippingFee, 'restocked_quantity' => $restockedQuantity]);

            return $lockedSale;
        });
    }

    public function advanceStatus(User $user, Sale $sale, string $status): Sale
    {
        return DB::transaction(function () use ($user, $sale, $status): Sale {
            $lockedSale = Sale::query()->whereKey($sale->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            $allowedTransitions = [
                'awaiting_shipment' => 'shipped',
                'shipped' => 'completed',
            ];
            if (($allowedTransitions[$lockedSale->status] ?? null) !== $status) {
                throw new RuntimeException('この取引状態では選択した処理を実行できません。');
            }

            $timestamps = $status === 'shipped'
                ? ['shipped_at' => now()]
                : ['completed_at' => now()];
            $lockedSale->update(['status' => $status, ...$timestamps]);

            return $lockedSale;
        });
    }

    /** @param array<string, int|string> $values */
    public function correctAmounts(User $user, Sale $sale, array $values): Sale
    {
        return DB::transaction(function () use ($user, $sale, $values): Sale {
            $lockedSale = Sale::query()->whereKey($sale->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (! in_array($lockedSale->status, Sale::VALID_SOLD_STATUSES, true)) {
                throw new RuntimeException('取消・返品済みの販売記録は金額を修正できません。');
            }
            $this->assertNoAccountingSendInProgress($user, $lockedSale);
            $entry = AccountingEntry::query()->where('user_id', $user->id)
                ->where('sale_id', $lockedSale->id)->lockForUpdate()->first();
            if ($this->hasAccountingCorrectionEvidence($entry)) {
                throw new RuntimeException(self::ACCOUNTING_CORRECTION_BLOCK_MESSAGE);
            }
            if (! hash_equals($lockedSale->recordVersionToken(), (string) $values['record_version'])) {
                throw new RuntimeException('別の操作で販売記録が更新されました。再読み込みして確認してください。');
            }

            $amountKeys = [
                'sold_price', 'cost_basis', 'sales_fee', 'shipping_fee', 'purchase_shipping_cost',
                'packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense',
            ];
            $amounts = [];
            foreach ($amountKeys as $key) {
                $amounts[$key] = (int) $values[$key];
            }
            $product = $lockedSale->product;
            if ($product?->purchase_accounting_enabled) {
                $capitalizedCost = app(FurimaDeckAccountingEntryService::class)->costBasisForNewSale($user, $product);
                if ($amounts['cost_basis'] !== $capitalizedCost || $amounts['purchase_shipping_cost'] !== 0) {
                    throw new RuntimeException('仕入時に在庫計上した取得原価と送料は販売時に変更できません。ほかの販売費用を確認してください。');
                }
            }
            $existingExpenseDetails = (array) ($lockedSale->accounting_expense_details ?? []);
            foreach (FurimaDeckSaleExpenseAccountingService::EXPENSES as $field => $_label) {
                if ((int) $lockedSale->{$field} === $amounts[$field]) {
                    continue;
                }
                $detail = (array) ($existingExpenseDetails[$field] ?? []);
                unset($detail['reviewed_at']);
                $detail['date_matches_entry_confirmed'] = false;
                $existingExpenseDetails[$field] = $detail;
            }
            $expenseDetails = app(FurimaDeckSaleExpenseAccountingService::class)->normalize(
                $user,
                $amounts,
                [],
                $existingExpenseDetails,
            );
            $saleUpdates = [
                ...$amounts,
                'accounting_expense_details' => $expenseDetails,
                'sold_at' => $values['sold_at'],
                'net_profit' => $this->profitCalculator->calculate($amounts),
            ];
            if (array_key_exists('marketplace_id', $values) && array_key_exists('sales_fee_rate', $values)) {
                $saleUpdates['marketplace_id'] = (int) $values['marketplace_id'];
                $saleUpdates['sales_fee_rate'] = (float) $values['sales_fee_rate'];
                $saleUpdates['listing_id'] = $values['listing_id'] ?? null;
            }
            $lockedSale->fill($saleUpdates);
            if (! $lockedSale->isDirty()) {
                return $lockedSale;
            }
            $lockedSale->save();

            $this->accountingEntries->recordSale($user, $lockedSale);

            return $lockedSale;
        });
    }

    public function amountCorrectionBlockReason(User $user, Sale $sale): ?string
    {
        $entry = $user->accountingEntries()->where('sale_id', $sale->id)->first();

        return $this->hasAccountingCorrectionEvidence($entry)
            ? self::ACCOUNTING_CORRECTION_BLOCK_MESSAGE
            : null;
    }

    private function lockedListing(User $user, Product $product, int|string|null $listingId): ?Listing
    {
        if ($listingId === null || $listingId === '') {
            return null;
        }

        $listing = Listing::query()->whereKey($listingId)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

        if ($listing->product_id !== $product->id || ! in_array($listing->status, ['ready', 'active'], true)) {
            throw new RuntimeException('選択した出品準備データは販売確定に使用できません。');
        }

        return $listing;
    }

    private function assertNoAccountingSendInProgress(User $user, Sale $sale): void
    {
        $inProgress = AccountingEntry::query()
            ->where('user_id', $user->id)
            ->where('sale_id', $sale->id)
            ->whereHas('providerSyncs', fn ($query) => $query->whereIn('status', ['queued', 'sending', 'csv_pending']))
            ->exists();

        if ($inProgress) {
            throw new RuntimeException('会計サービスへの送信またはCSV取込の結果が未確定です。結果を確定してから、取消・返品・金額修正を行ってください。');
        }
    }

    private function hasAccountingCorrectionEvidence(?AccountingEntry $entry): bool
    {
        if ($entry === null) {
            return false;
        }

        if (filled($entry->external_transaction_id)
            || in_array($entry->sync_status, self::ACCOUNTING_CORRECTION_BLOCKING_STATUSES, true)) {
            return true;
        }

        return $entry->providerSyncs()->where(function ($query): void {
            $query->whereIn('status', self::ACCOUNTING_CORRECTION_BLOCKING_STATUSES)
                ->orWhereNotNull('external_transaction_id')
                ->orWhereNotNull('external_import_id');
        })->exists();
    }
}
