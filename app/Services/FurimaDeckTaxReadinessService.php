<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

class FurimaDeckTaxReadinessService
{
    private const VALID_STATUSES = Sale::VALID_SOLD_STATUSES;

    /** @return array<string, mixed> */
    public function report(User $user, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));

        return $this->reportPeriod($user, $from, $from->addYear(), (string) $year);
    }

    /** @return array<string, mixed> */
    public function reportPeriod(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive, ?string $label = null): array
    {
        $entryFields = ['sold_at', 'sold_price', 'cost_basis', 'sales_fee', 'shipping_fee', 'other_expense', 'purchase_date', 'supplier', 'management_id'];
        $completeCounts = array_fill_keys($entryFields, 0);
        $salesCount = 0;
        $confirmedCount = 0;
        $salesAmount = 0;
        $profit = 0;
        $sourceReviewOffset = (LengthAwarePaginator::resolveCurrentPage('source_review_page') - 1)
            * (int) config('furimadeck.accounting.entries_per_page');
        $sourceReviewItems = collect();
        $sourceReviewCount = 0;
        $salesQuery = $user->sales()->with(['product' => fn ($query) => $query->where('user_id', $user->id)])
            ->whereIn('status', self::VALID_STATUSES)
            ->where('sold_at', '>=', $from)
            ->where('sold_at', '<', $toExclusive);

        $salesQuery->chunkById((int) config('furimadeck.accounting.query_chunk_size'), function ($sales) use ($user, $entryFields, $sourceReviewOffset, &$completeCounts, &$salesCount, &$confirmedCount, &$salesAmount, &$profit, &$sourceReviewItems, &$sourceReviewCount): void {
            foreach ($sales as $sale) {
                $salesCount++;
                $salesAmount += $sale->sold_price ?? 0;
                $profit += $sale->net_profit ?? 0;
                $product = $sale->product?->user_id === $user->id ? $sale->product : null;
                $fieldState = [
                    'sold_at' => $sale->sold_at !== null,
                    'sold_price' => $sale->sold_price !== null,
                    'cost_basis' => $sale->cost_basis !== null,
                    'sales_fee' => $sale->sales_fee !== null,
                    'shipping_fee' => $sale->shipping_fee !== null,
                    'other_expense' => $sale->other_expense !== null,
                    'purchase_date' => $product?->purchase_date !== null,
                    'supplier' => $product?->supplier_id !== null,
                    'management_id' => filled($sale->internal_sku_snapshot),
                ];
                $missing = [];
                $zero = [];
                foreach (['sold_price' => '売価', 'cost_basis' => '原価', 'sales_fee' => '販売手数料', 'shipping_fee' => '送料', 'other_expense' => 'その他費用'] as $field => $fieldLabel) {
                    if ($sale->{$field} === null) {
                        $missing[] = $fieldLabel;
                    } elseif ($sale->{$field} === 0) {
                        $zero[] = $fieldLabel;
                    }
                }
                if (! $fieldState['purchase_date']) {
                    $missing[] = '仕入日';
                }
                if (! $fieldState['supplier']) {
                    $missing[] = '仕入先';
                }
                if (! $fieldState['management_id']) {
                    $missing[] = '管理ID';
                }

                $allFieldsComplete = true;
                foreach ($entryFields as $field) {
                    if ($fieldState[$field]) {
                        $completeCounts[$field]++;
                    } else {
                        $allFieldsComplete = false;
                    }
                }
                if ($allFieldsComplete) {
                    $confirmedCount++;
                }

                if ($missing !== [] || $zero !== []) {
                    if ($sourceReviewCount >= $sourceReviewOffset
                        && $sourceReviewCount < $sourceReviewOffset + (int) config('furimadeck.accounting.entries_per_page')) {
                        $sourceReviewItems->push([
                            'sale_id' => $sale->id,
                            'sold_at' => $sale->sold_at,
                            'management_id' => $sale->internal_sku_snapshot,
                            'title' => $sale->product_name_snapshot,
                            'product_id' => $product?->id,
                            'missing' => $missing,
                            'zero' => $zero,
                        ]);
                    }
                    $sourceReviewCount++;
                }
            }
        });

        $allSalesCount = $user->sales()->where('sold_at', '>=', $from)->where('sold_at', '<', $toExclusive)->count();
        $validSalesCount = $user->sales()->whereIn('status', self::VALID_STATUSES)
            ->where('sold_at', '>=', $from)->where('sold_at', '<', $toExclusive)->count();
        $checks = [];
        foreach ([...$entryFields, 'sale_status'] as $field) {
            $total = $field === 'sale_status' ? $allSalesCount : $salesCount;
            $complete = $field === 'sale_status' ? $validSalesCount : $completeCounts[$field];
            $checks[$field] = [
                'total' => $total,
                'complete' => $complete,
                'incomplete' => $total - $complete,
                'status' => $total === $complete ? 'ready' : 'review',
            ];
        }
        $sourceReviewItems = new LengthAwarePaginator(
            $sourceReviewItems,
            $sourceReviewCount,
            (int) config('furimadeck.accounting.entries_per_page'),
            LengthAwarePaginator::resolveCurrentPage('source_review_page'),
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'query' => request()->query(), 'pageName' => 'source_review_page'],
        );

        return [
            'year' => $from->year,
            'period' => ['from' => $from, 'to' => $toExclusive->subDay()],
            'sales_count' => $salesCount,
            'confirmed_count' => $confirmedCount,
            'confirmation_target_count' => max(0, $salesCount - $confirmedCount),
            'sales_amount' => (int) $salesAmount,
            'profit' => (int) $profit,
            'checks' => $checks,
            'source_review_items' => $sourceReviewItems,
            'incomplete_check_count' => collect($checks)->filter(fn (array $check): bool => $check['incomplete'] > 0)->count(),
            'accounting_sync' => $this->accountingStatus($user, $from, $toExclusive),
            'period_label' => $label,
        ];
    }

    /** @return array{status: string, label: string} */
    private function accountingStatus(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $entries = fn () => $user->accountingEntries()->eligibleForAccounting()
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $toExclusive->toDateString());

        if (! $entries()->exists() || ! $user->accountingConnections()->exists()) {
            return ['status' => 'not_available', 'label' => '会計連携未設定'];
        }
        if (! $entries()->where(fn ($query) => $query->where('sync_status', '!=', 'synced')->orWhereNull('sync_status'))->exists()) {
            return ['status' => 'ready', 'label' => '会計連携済み'];
        }

        return ['status' => 'review', 'label' => '会計データ確認中'];
    }
}
