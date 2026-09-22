<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FurimaDeckAnalyticsService
{
    private const EXCLUDED_SALE_STATUSES = ['cancelled', 'returned'];

    private const MINIMUM_MONTHLY_TARGET = 50000;

    private const TARGET_GROWTH_RATE = 1.15;

    private const TARGET_ROUND_UNIT = 10000;

    private const DAYS_PER_WEEK = 7;

    private const ROLLING_MONTH_COUNT = 12;

    /** @return array<string, int|float> */
    public function summary(User $user): array
    {
        $sales = $this->salesQuery($user);
        $salesTotal = (int) $sales->sum('sold_price');
        $salesFeeTotal = (int) $this->salesQuery($user)->sum('sales_fee');
        $profitTotal = (int) $this->salesQuery($user)->sum('net_profit');
        $inventory = $user->products()->where('quantity_available', '>', 0);
        $saleCount = (int) $this->salesQuery($user)->count();
        $productCount = (int) $user->products()->count();
        $zeroStockProductCount = (int) $user->products()->where('quantity_available', 0)->count();

        return [
            'sales_total' => $salesTotal,
            'sales_fee_total' => $salesFeeTotal,
            'profit_total' => $profitTotal,
            'sale_count' => $saleCount,
            'sales_data_available' => $saleCount > 0,
            'profit_margin' => $salesTotal === 0 ? 0.0 : round(($profitTotal / $salesTotal) * 100, 1),
            'product_count' => $productCount,
            'zero_stock_product_count' => $zeroStockProductCount,
            'inventory_quantity' => (int) $inventory->sum('quantity_available'),
            'inventory_cost' => (int) $user->products()
                ->where('quantity_available', '>', 0)
                ->get(['purchase_unit_cost', 'quantity_available'])
                ->sum(fn ($product) => $product->purchase_unit_cost * $product->quantity_available),
        ];
    }

    /**
     * ダッシュボードで使う、選択月を中心とした事業指標を返します。
     *
     * @return array<string, mixed>
     */
    public function dashboard(User $user, CarbonImmutable $month): array
    {
        $staleInventoryDays = (int) config('furimadeck.inventory.long_term_days');
        $monthlyStats = $this->rollingMonthlyStats($user, $month);
        $currentMonth = $monthlyStats->firstWhere('key', $month->format('Y-m')) ?? $this->emptyMonth($month);
        $previousMonth = $this->monthSummary($user, $month->subMonth());
        $activeListings = $user->listings()
            ->where('status', 'active')
            ->get(['listing_price', 'expected_profit']);
        $monthsWithSales = $monthlyStats->filter(fn (array $row): bool => $row['sales'] > 0);
        $monthlyAverage = (int) round($monthsWithSales->avg('sales') ?? 0);
        $monthlyTarget = max(
            self::MINIMUM_MONTHLY_TARGET,
            (int) ceil(max($monthlyAverage, $currentMonth['sales']) * self::TARGET_GROWTH_RATE / self::TARGET_ROUND_UNIT) * self::TARGET_ROUND_UNIT,
        );
        $staleInventory = $user->products()
            ->where('quantity_available', '>', 0)
            ->whereHas('listings', fn ($listing) => $listing
                ->whereIn('status', Listing::STALE_INVENTORY_STATUSES)
                ->whereNotNull('listed_at')
                ->whereDate('listed_at', '<=', $month->endOfMonth()->subDays($staleInventoryDays)))
            ->whereDoesntHave('sales', fn ($sales) => $sales->whereIn('status', Sale::VALID_SOLD_STATUSES))
            ->get(['purchase_unit_cost', 'quantity_available']);

        return [
            'summary' => $this->summary($user),
            'monthly_stats' => $monthlyStats,
            'current_month' => $currentMonth,
            'monthly_target' => $monthlyTarget,
            'monthly_target_progress' => $monthlyTarget === 0 ? 0.0 : min(100.0, round(($currentMonth['sales'] / $monthlyTarget) * 100, 1)),
            'sales_trend_percent' => $this->trendPercent($currentMonth['sales'], $previousMonth['sales']),
            'profit_trend_percent' => $this->trendPercent($currentMonth['profit'], $previousMonth['profit']),
            'current_month_profit_margin' => $currentMonth['sales'] === 0 ? 0.0 : round(($currentMonth['profit'] / $currentMonth['sales']) * 100, 1),
            'current_month_average_profit' => $currentMonth['count'] === 0 ? 0 : (int) round($currentMonth['profit'] / $currentMonth['count']),
            'stale_inventory_count' => (int) $staleInventory->sum('quantity_available'),
            'stale_inventory_days' => $staleInventoryDays,
            'stale_inventory_cost' => (int) $staleInventory->sum(
                fn ($product): int => $product->purchase_unit_cost * $product->quantity_available,
            ),
            'portfolio' => [
                'product_count' => $user->products()->count(),
                'active_listing_count' => $activeListings->count(),
                'sold_count' => $this->salesQuery($user)->count(),
                'zero_stock_product_count' => (int) $user->products()->where('quantity_available', 0)->count(),
                'potential_sales' => (int) $activeListings->sum('listing_price'),
                'potential_profit' => (int) $activeListings->sum('expected_profit'),
            ],
            'can_use_premium_features' => $user->hasActiveSubscription(),
        ];
    }

    /** @return Collection<int, array{month: int, label: string, sales: int, profit: int, count: int, quantity: int}> */
    public function monthlyStats(User $user, int $year): Collection
    {
        $sales = $this->salesQuery($user)
            ->where('sold_at', '>=', CarbonImmutable::create($year, 1, 1)->startOfYear())
            ->where('sold_at', '<', CarbonImmutable::create($year, 1, 1)->addYear()->startOfYear())
            ->get(['sold_at', 'sold_price', 'sales_fee', 'net_profit', 'quantity'])
            ->groupBy(fn (Sale $sale): int => $sale->sold_at->month);

        return collect(range(1, 12))->map(function (int $month) use ($sales): array {
            /** @var Collection<int, Sale> $rows */
            $rows = $sales->get($month, collect());

            return [
                'month' => $month,
                'label' => $month.'月',
                'sales' => (int) $rows->sum('sold_price'),
                'sales_fee' => (int) $rows->sum('sales_fee'),
                'profit' => (int) $rows->sum('net_profit'),
                'count' => $rows->count(),
                'quantity' => (int) $rows->sum('quantity'),
            ];
        });
    }

    /**
     * 選択月を終点とする直近12か月の売上・実利益を返します。
     *
     * @return Collection<int, array{key: string, month: int, year: int, label: string, period_label: string, sales: int, profit: int, count: int, quantity: int}>
     */
    private function rollingMonthlyStats(User $user, CarbonImmutable $lastMonth): Collection
    {
        $firstMonth = $lastMonth->startOfMonth()->subMonths(self::ROLLING_MONTH_COUNT - 1);
        $afterLastMonth = $lastMonth->startOfMonth()->addMonth();
        $salesByMonth = $this->salesQuery($user)
            ->where('sold_at', '>=', $firstMonth)
            ->where('sold_at', '<', $afterLastMonth)
            ->get(['sold_at', 'sold_price', 'sales_fee', 'net_profit', 'quantity'])
            ->groupBy(fn (Sale $sale): string => $sale->sold_at->format('Y-m'));

        return collect(range(0, self::ROLLING_MONTH_COUNT - 1))->map(function (int $offset) use ($firstMonth, $salesByMonth): array {
            $date = $firstMonth->addMonths($offset);
            /** @var Collection<int, Sale> $rows */
            $rows = $salesByMonth->get($date->format('Y-m'), collect());

            return [
                'key' => $date->format('Y-m'),
                'month' => $date->month,
                'year' => $date->year,
                'label' => $date->format('Y-m'),
                'period_label' => $date->format('Y年n月'),
                'sales' => (int) $rows->sum('sold_price'),
                'sales_fee' => (int) $rows->sum('sales_fee'),
                'profit' => (int) $rows->sum('net_profit'),
                'count' => $rows->count(),
                'quantity' => (int) $rows->sum('quantity'),
            ];
        });
    }

    /**
     * 月内の日別売上を、月曜始まりの週単位で返します。
     *
     * @return array<int, array<int, array{date: CarbonImmutable, in_month: bool, sales: int, profit: int, count: int}>>
     */
    public function salesCalendar(User $user, CarbonImmutable $month): array
    {
        $salesByDate = $this->salesQuery($user)
            ->where('sold_at', '>=', $month->startOfMonth())
            ->where('sold_at', '<', $month->addMonth()->startOfMonth())
            ->get(['sold_at', 'sold_price', 'net_profit'])
            ->groupBy(fn (Sale $sale): string => $sale->sold_at->toDateString());
        $cursor = $month->startOfMonth()->startOfWeek(CarbonInterface::MONDAY);
        $lastDay = $month->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY);
        $weeks = [];

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $week = [];

            for ($day = 0; $day < self::DAYS_PER_WEEK; $day++) {
                /** @var Collection<int, Sale> $daySales */
                $daySales = $salesByDate->get($cursor->toDateString(), collect());
                $week[] = [
                    'date' => $cursor,
                    'in_month' => $cursor->month === $month->month,
                    'sales' => (int) $daySales->sum('sold_price'),
                    'profit' => (int) $daySales->sum('net_profit'),
                    'count' => $daySales->count(),
                ];
                $cursor = $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    /** @return array{month: int, label: string, sales: int, profit: int, count: int, quantity: int} */
    private function monthSummary(User $user, CarbonImmutable $month): array
    {
        $sales = $this->salesQuery($user)
            ->where('sold_at', '>=', $month->startOfMonth())
            ->where('sold_at', '<', $month->addMonth()->startOfMonth())
            ->get(['sold_price', 'sales_fee', 'net_profit', 'quantity']);

        return [
            'month' => $month->month,
            'label' => $month->month.'月',
            'sales' => (int) $sales->sum('sold_price'),
            'sales_fee' => (int) $sales->sum('sales_fee'),
            'profit' => (int) $sales->sum('net_profit'),
            'count' => $sales->count(),
            'quantity' => (int) $sales->sum('quantity'),
        ];
    }

    /** @return array{month: int, label: string, sales: int, profit: int, count: int, quantity: int} */
    private function emptyMonth(CarbonImmutable $month): array
    {
        return [
            'month' => $month->month,
            'label' => $month->month.'月',
            'sales' => 0,
            'sales_fee' => 0,
            'profit' => 0,
            'count' => 0,
            'quantity' => 0,
        ];
    }

    private function trendPercent(int $current, int $previous): ?float
    {
        return $previous === 0 ? null : round((($current - $previous) / $previous) * 100, 1);
    }

    private function salesQuery(User $user)
    {
        return $user->sales()->whereNotIn('status', self::EXCLUDED_SALE_STATUSES);
    }
}
