<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FurimaDeckAdvancedAnalyticsService
{
    private const EXCLUDED_SALE_STATUSES = ['cancelled', 'returned'];

    /** @return array<string, mixed> */
    public function report(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = $this->sales($user, $from, $to);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'sales' => $this->salesSummary($sales),
            'sale_days' => $this->saleDays($sales),
            'profit_velocity' => $this->profitVelocity($sales),
            'price_bands' => $this->priceBands($sales),
            'weekday' => $this->groupSales($sales, fn (Sale $sale): string => $sale->sold_at->isoFormat('dddd')),
            'seasonality' => $this->groupSales($sales, fn (Sale $sale): string => $sale->sold_at->format('Y-m')),
            'inventory' => $this->inventory($user),
            'supplier' => $this->supplierAnalysis($user, $sales),
            'matrix' => $this->profitMatrix($sales),
        ];
    }

    /** @return Collection<int, Sale> */
    private function sales(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $user->sales()->with(['listing:id,listed_at', 'product.supplier'])
            ->whereNotIn('status', self::EXCLUDED_SALE_STATUSES)
            ->where('sold_at', '>=', $from)->where('sold_at', '<', $to)->get();
    }

    /** @param Collection<int, Sale> $sales */
    private function salesSummary(Collection $sales): array
    {
        $amount = (int) $sales->sum('sold_price');
        $salesFee = (int) $sales->sum('sales_fee');
        $profit = (int) $sales->sum('net_profit');

        return ['sales_amount' => $amount, 'sales_fee' => $salesFee, 'profit' => $profit, 'sale_count' => $sales->count(), 'quantity' => (int) $sales->sum('quantity'), 'profit_margin' => $amount === 0 ? 0.0 : round($profit / $amount * 100, 1)];
    }

    /** @param Collection<int, Sale> $sales */
    private function saleDays(Collection $sales): array
    {
        $days = $sales->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();

        return ['average' => $days->isEmpty() ? null : round($days->avg(), 1), 'median' => $this->median($days), 'minimum' => $days->min(), 'maximum' => $days->max(), 'included_count' => $days->count(), 'excluded_count' => $sales->count() - $days->count()];
    }

    /** @param Collection<int, Sale> $sales */
    private function profitVelocity(Collection $sales): array
    {
        $values = $sales->map(function (Sale $sale): ?float {
            $days = $this->saleDaysFor($sale);

            return $days === null ? null : (int) $sale->net_profit / $days;
        })->filter(static fn (?float $value): bool => $value !== null)->values();

        return ['average' => $values->isEmpty() ? null : round($values->avg(), 1), 'median' => $this->median($values), 'included_count' => $values->count()];
    }

    /** @param Collection<int, Sale> $sales */
    private function priceBands(Collection $sales): array
    {
        $bands = [[0, 2999, '0～2,999円'], [3000, 4999, '3,000～4,999円'], [5000, 9999, '5,000～9,999円'], [10000, 19999, '10,000～19,999円'], [20000, PHP_INT_MAX, '20,000円以上']];

        return array_map(function (array $band) use ($sales): array {
            $group = $sales->filter(fn (Sale $sale): bool => $sale->sold_price >= $band[0] && $sale->sold_price <= $band[1]);
            $amount = (int) $group->sum('sold_price');

            return ['label' => $band[2], 'sale_count' => $group->count(), 'sales_amount' => $amount, 'sales_fee' => (int) $group->sum('sales_fee'), 'profit' => (int) $group->sum('net_profit'), 'profit_margin' => $amount === 0 ? 0.0 : round($group->sum('net_profit') / $amount * 100, 1), 'median_sale_days' => $this->median($group->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values())];
        }, $bands);
    }

    /** @param Collection<int, Sale> $sales */
    private function groupSales(Collection $sales, callable $label): array
    {
        return $sales->groupBy($label)->map(function (Collection $group, string $name): array {
            return ['label' => $name, 'sale_count' => $group->count(), 'sales_amount' => (int) $group->sum('sold_price'), 'sales_fee' => (int) $group->sum('sales_fee'), 'profit' => (int) $group->sum('net_profit')];
        })->values()->all();
    }

    /** @param Collection<int, Sale> $sales @return array<int, array<string, int|float|string|null>> */
    /** @param Collection<int, Sale> $sales @return array<string, mixed> */
    private function profitMatrix(Collection $sales): array
    {
        $groups = $sales->groupBy('product_id')->map(function (Collection $items): ?array {
            $amount = (int) $items->sum('sold_price');
            $days = $items->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();
            if ($days->isEmpty()) {
                return null;
            }

            $profit = (int) $items->sum('net_profit');
            $margin = $amount === 0 ? 0.0 : round($profit / $amount * 100, 1);
            $velocity = round($profit / max(1, (float) $days->median()), 1);

            return [
                'product_id' => $items->first()->product_id,
                'product_name' => $items->first()->product?->product_name ?? $items->first()->product_name_snapshot,
                'profit_margin' => $margin,
                'profit_velocity' => $velocity,
                'sample_count' => $items->count(),
            ];
        })->filter()->values();

        if ($groups->count() < 3) {
            return ['available' => false, 'reason' => 'データ不足', 'margin_median' => null, 'velocity_median' => null, 'rows' => []];
        }

        $marginMedian = $this->median($groups->pluck('profit_margin'));
        $velocityMedian = $this->median($groups->pluck('profit_velocity'));
        $rows = $groups->map(function (array $row) use ($marginMedian, $velocityMedian): array {
            $highMargin = $marginMedian !== null && $row['profit_margin'] >= $marginMedian;
            $highVelocity = $velocityMedian !== null && $row['profit_velocity'] >= $velocityMedian;
            $row['quadrant'] = match (true) {
                $highMargin && $highVelocity => '高利益率・高速回転',
                $highMargin => '高利益率・低速回転',
                $highVelocity => '低利益率・高速回転',
                default => '低利益率・低速回転',
            };

            return $row;
        })->sortByDesc(fn (array $row): float => $row['profit_velocity'])->values()->all();

        return ['available' => true, 'reason' => null, 'margin_median' => $marginMedian, 'velocity_median' => $velocityMedian, 'rows' => $rows];
    }

    private function supplierAnalysis(User $user, Collection $sales): array
    {
        $inventory = $user->products()
            ->with('supplier:id,name')
            ->where('quantity_available', '>', 0)
            ->where('inventory_status', 'in_stock')
            ->get(['supplier_id', 'purchase_unit_cost', 'quantity_available']);
        $inventoryBySupplier = $inventory->groupBy(fn ($product): string => (string) ($product->supplier?->name ?? '未設定'))
            ->map(fn (Collection $items): int => (int) $items->sum(fn ($product): int => (int) $product->purchase_unit_cost * (int) $product->quantity_available));

        return $sales->groupBy(fn (Sale $sale): string => (string) ($sale->product?->supplier?->name ?? '未設定'))
            ->map(function (Collection $items, string $name) use ($inventoryBySupplier): array {
                $amount = (int) $items->sum('sold_price');
                $days = $items->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();

                return [
                    'name' => $name,
                    'sales_amount' => $amount,
                    'sales_fee' => (int) $items->sum('sales_fee'),
                    'profit' => (int) $items->sum('net_profit'),
                    'sale_count' => $items->count(),
                    'profit_margin' => $amount === 0 ? 0.0 : round($items->sum('net_profit') / $amount * 100, 1),
                    'median_sale_days' => $this->median($days),
                    'inventory_capital' => (int) ($inventoryBySupplier[$name] ?? 0),
                ];
            })
            ->sortByDesc('sales_amount')
            ->values()
            ->all();
    }

    private function inventory(User $user): array
    {
        $today = CarbonImmutable::now();
        $products = $user->products()->where('quantity_available', '>', 0)->where('inventory_status', 'in_stock')->get(['purchase_date', 'purchase_unit_cost', 'quantity_available']);
        $result = ['product_count' => 0, 'stock_units' => 0, 'capital' => 0, 'over_30_days' => 0, 'over_60_days' => 0, 'over_90_days' => 0, 'purchase_date_unset' => 0];
        foreach ($products as $product) {
            $result['product_count']++;
            $result['stock_units'] += (int) $product->quantity_available;
            $capital = (int) $product->purchase_unit_cost * (int) $product->quantity_available;
            $result['capital'] += $capital;
            if ($product->purchase_date === null) {
                $result['purchase_date_unset'] += $capital;

                continue;
            }
            $days = $product->purchase_date->diffInDays($today, false);
            foreach ([30 => 'over_30_days', 60 => 'over_60_days', 90 => 'over_90_days'] as $threshold => $key) {
                if ($days >= $threshold) {
                    $result[$key] += $capital;
                }
            }
        }

        return $result;
    }

    private function saleDaysFor(Sale $sale): ?int
    {
        if ($sale->listing?->listed_at === null || $sale->sold_at === null) {
            return null;
        }
        $days = $sale->listing->listed_at->diffInDays($sale->sold_at, false);

        return $days < 0 ? null : max(1, $days);
    }

    /** @param Collection<int, int|float> $values */
    private function median(Collection $values): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }
        $sorted = $values->sort()->values();
        $middle = intdiv($sorted->count(), 2);

        return $sorted->count() % 2 === 1 ? (float) $sorted[$middle] : round(((float) $sorted[$middle - 1] + (float) $sorted[$middle]) / 2, 1);
    }
}
