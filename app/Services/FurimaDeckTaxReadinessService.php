<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FurimaDeckTaxReadinessService
{
    private const VALID_STATUSES = ['pending', 'awaiting_shipment', 'shipped', 'completed'];

    /** @return array<string, mixed> */
    public function report(User $user, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));

        return $this->reportPeriod($user, $from, $from->addYear(), (string) $year);
    }

    /** @return array<string, mixed> */
    public function reportPeriod(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive, ?string $label = null): array
    {
        $periodSales = $user->sales()->with(['product', 'listing'])
            ->whereIn('status', self::VALID_STATUSES)
            ->where('sold_at', '>=', $from)
            ->where('sold_at', '<', $toExclusive)
            ->get();
        $allSales = $user->sales()->where('sold_at', '>=', $from)->where('sold_at', '<', $toExclusive)->get();

        $checks = [
            'sold_at' => $this->check($periodSales, fn (Sale $sale): bool => $sale->sold_at !== null),
            'sold_price' => $this->check($periodSales, fn (Sale $sale): bool => $sale->sold_price !== null),
            'cost_basis' => $this->check($periodSales, fn (Sale $sale): bool => $sale->cost_basis !== null),
            'sales_fee' => $this->check($periodSales, fn (Sale $sale): bool => $sale->sales_fee !== null),
            'shipping_fee' => $this->check($periodSales, fn (Sale $sale): bool => $sale->shipping_fee !== null),
            'other_expense' => $this->check($periodSales, fn (Sale $sale): bool => $sale->other_expense !== null),
            'purchase_date' => $this->check($periodSales, fn (Sale $sale): bool => $sale->product?->purchase_date !== null),
            'supplier' => $this->check($periodSales, fn (Sale $sale): bool => $sale->product?->supplier_id !== null),
            'management_id' => $this->check($periodSales, fn (Sale $sale): bool => filled($sale->internal_sku_snapshot)),
            'sale_status' => $this->check($allSales, fn (Sale $sale): bool => in_array($sale->status, self::VALID_STATUSES, true)),
        ];
        $completeSales = $periodSales->filter(fn (Sale $sale): bool => collect($checks)->every(fn (array $check, string $key): bool => $key === 'sale_status' || $this->saleCheck($sale, $key)))->count();

        return [
            'year' => $from->year,
            'period' => ['from' => $from, 'to' => $toExclusive->subDay()],
            'sales_count' => $periodSales->count(),
            'confirmed_count' => $completeSales,
            'confirmation_target_count' => max(0, $periodSales->count() - $completeSales),
            'sales_amount' => (int) $periodSales->sum('sold_price'),
            'profit' => (int) $periodSales->sum('net_profit'),
            'checks' => $checks,
            'incomplete_check_count' => collect($checks)->filter(fn (array $check): bool => $check['incomplete'] > 0)->count(),
            'accounting_sync' => $this->accountingStatus($user, $from, $toExclusive),
            'period_label' => $label,
        ];
    }

    /** @return array{status: string, label: string} */
    private function accountingStatus(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $entries = $user->accountingEntries()->eligibleForAccounting()
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $toExclusive->toDateString())
            ->get(['sync_status', 'source_status']);

        if ($entries->isEmpty() || ! $user->accountingConnections()->exists()) {
            return ['status' => 'not_available', 'label' => '会計連携未設定'];
        }
        if ($entries->every(fn (AccountingEntry $entry): bool => $entry->sync_status === 'synced')) {
            return ['status' => 'ready', 'label' => '会計連携済み'];
        }

        return ['status' => 'review', 'label' => '会計データ確認中'];
    }

    /** @param Collection<int, Sale> $sales */
    private function check(Collection $sales, callable $isComplete): array
    {
        $complete = $sales->filter($isComplete)->count();
        $total = $sales->count();

        return ['total' => $total, 'complete' => $complete, 'incomplete' => $total - $complete, 'status' => $total === $complete ? 'ready' : 'review'];
    }

    private function saleCheck(Sale $sale, string $key): bool
    {
        return match ($key) {
            'sold_at' => $sale->sold_at !== null,
            'sold_price' => $sale->sold_price !== null,
            'cost_basis' => $sale->cost_basis !== null,
            'sales_fee' => $sale->sales_fee !== null,
            'shipping_fee' => $sale->shipping_fee !== null,
            'other_expense' => $sale->other_expense !== null,
            'purchase_date' => $sale->product?->purchase_date !== null,
            'supplier' => $sale->product?->supplier_id !== null,
            'management_id' => filled($sale->internal_sku_snapshot),
            default => true,
        };
    }
}
