<?php

namespace App\Services;

use App\Models\Marketplace;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FurimaDeckSalesAnalysisService
{
    private const EXCLUDED_STATUSES = ['cancelled', 'returned'];

    /** @var array<string, string> */
    private const CROSS_MARKETPLACE_LABELS = [
        'yahoo_auctions' => 'ヤフオク',
        'mercari' => 'メルカリ',
        'rakuma' => 'ラクマ',
        'yahoo_flea_market' => 'Yahooフリマ',
        'other' => 'その他',
    ];

    public function __construct(private readonly FurimaDeckAnalyticsService $dashboardAnalytics) {}

    /** @return array<string, mixed> */
    public function report(User $user, CarbonImmutable $month): array
    {
        $sales = $this->salesForMonth($user, $month);

        return [
            'summary' => $this->summary($sales),
            'inventory' => $this->inventorySummary($user),
            'monthly_rows' => $this->dashboardAnalytics->monthlyStats($user, $month->year),
            'marketplace_rows' => $this->rowsBy($sales, fn (Sale $sale): string => $sale->marketplace?->name ?? '未設定'),
            'category_rows' => $this->rowsBy($sales, fn (Sale $sale): string => $this->categoryPath($sale->product?->category)),
        ];
    }

    /** @return array<string, mixed> */
    public function categoryReport(User $user, CarbonImmutable $month): array
    {
        $sales = $this->salesForMonth($user, $month);

        return [
            'summary' => $this->summary($sales),
            'root_rows' => $this->rowsBy($sales, fn (Sale $sale): string => $this->rootCategoryName($sale->product?->category)),
            'middle_rows' => $this->rowsBy($sales, fn (Sale $sale): string => $this->categoryNameAtDepth($sale->product?->category, 2)),
            'small_rows' => $this->rowsBy($sales, fn (Sale $sale): string => $this->categoryNameAtDepth($sale->product?->category, 3)),
        ];
    }

    /**
     * 販売先とカテゴリを横断して、どの組み合わせが成果につながったかを返します。
     *
     * @return array<string, mixed>
     */
    public function crossReport(User $user, CarbonImmutable $month): array
    {
        $sales = $this->salesForMonth($user, $month);
        $marketplaces = Marketplace::query()
            ->where(function ($query) use ($sales): void {
                $query
                    ->where('is_active', true)
                    ->orWhereIn('id', $sales->pluck('marketplace_id')->filter()->unique());
            })
            ->get(['id', 'code', 'name'])
            ->map(fn (Marketplace $marketplace): array => [
                'id' => $marketplace->id,
                'code' => $marketplace->code,
                'name' => self::CROSS_MARKETPLACE_LABELS[$marketplace->code] ?? $marketplace->name,
                'sort_order' => array_search($marketplace->code, array_keys(self::CROSS_MARKETPLACE_LABELS), true),
            ])
            ->sortBy(fn (array $marketplace): string => sprintf('%02d-%s', $marketplace['sort_order'] === false ? count(self::CROSS_MARKETPLACE_LABELS) : $marketplace['sort_order'], $marketplace['name']))
            ->values();

        $rows = $sales
            ->groupBy(fn (Sale $sale): string => $this->rootCategoryName($sale->product?->category))
            ->map(function (Collection $categorySales, string $categoryName) use ($marketplaces): array {
                $cells = $marketplaces->mapWithKeys(function (array $marketplace) use ($categorySales): array {
                    $cellSales = $categorySales->filter(
                        fn (Sale $sale): bool => ($sale->marketplace?->id ?? 0) === $marketplace['id'],
                    );

                    return [(string) $marketplace['id'] => $this->crossMetrics($cellSales)];
                })->all();

                return [
                    'name' => $categoryName,
                    'metrics' => $this->crossMetrics($categorySales),
                    'cells' => $cells,
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['metrics']['sales'])
            ->values();

        return [
            'summary' => $this->summary($sales),
            'marketplaces' => $marketplaces,
            'rows' => $rows,
            'marketplace_totals' => $marketplaces->mapWithKeys(function (array $marketplace) use ($sales): array {
                $marketplaceSales = $sales->filter(
                    fn (Sale $sale): bool => ($sale->marketplace?->id ?? 0) === $marketplace['id'],
                );

                return [(string) $marketplace['id'] => $this->crossMetrics($marketplaceSales)];
            })->all(),
            'total_metrics' => $this->crossMetrics($sales),
        ];
    }

    /** @return array<string, mixed> */
    public function marketplaceSuitabilityReport(User $user, CarbonImmutable $month): array
    {
        $sales = $this->salesForMonth($user, $month);
        $groups = $sales->groupBy(fn (Sale $sale): string => $this->rootCategoryName($sale->product?->category).'|'.($sale->marketplace?->name ?? '未設定'));

        return $groups->map(function (Collection $items, string $key): array {
            [$category, $marketplace] = explode('|', $key, 2);
            $days = $items->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();
            $salesAmount = (int) $items->sum('sold_price');
            $profits = $items->pluck('net_profit')->map(fn ($value): int => (int) $value);
            $medianProfit = $this->median($profits);
            $medianDays = $this->median($days);

            return [
                'category' => $category,
                'marketplace' => $marketplace,
                'sample_count' => $items->count(),
                'median_sale_price' => $this->median($items->pluck('sold_price')->map(fn ($value): int => (int) $value)),
                'median_profit' => $medianProfit,
                'median_profit_margin' => $salesAmount === 0 ? 0.0 : round($items->sum('net_profit') / $salesAmount * 100, 1),
                'median_sale_days' => $medianDays,
                'profit_velocity' => $medianProfit === null || $medianDays === null ? null : round($medianProfit / max(1, $medianDays), 1),
                'comparable' => $items->count() >= 3 && $medianDays !== null,
            ];
        })->sortByDesc('sample_count')->values()->all();
    }

    /** @return array<string, int> */
    private function inventorySummary(User $user): array
    {
        $products = $user->products()
            ->where('quantity_available', '>', 0)
            ->get(['quantity_available', 'purchase_unit_cost', 'inventory_status', 'purchase_date']);

        return [
            'product_count' => $products->count(),
            'stock_units' => (int) $products->sum('quantity_available'),
            'inventory_capital' => (int) $products->sum(
                fn ($product): int => (int) $product->purchase_unit_cost * (int) $product->quantity_available,
            ),
            'listed_count' => (int) $user->listings()->whereIn('status', ['ready', 'active'])->distinct('product_id')->count('product_id'),
            'purchase_date_unset' => $products->whereNull('purchase_date')->count(),
        ];
    }

    /** @return Collection<int, Sale> */
    private function salesForMonth(User $user, CarbonImmutable $month): Collection
    {
        return $user->sales()
            ->with(['marketplace:id,name', 'product.category.parent.parent', 'listing:id,listed_at'])
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->where('sold_at', '>=', $month->startOfMonth())
            ->where('sold_at', '<', $month->addMonth()->startOfMonth())
            ->get();
    }

    /** @param Collection<int, Sale> $sales
     * @return array<string, int|float>
     */
    private function summary(Collection $sales): array
    {
        $salesTotal = (int) $sales->sum('sold_price');
        $salesFeeTotal = (int) $sales->sum('sales_fee');
        $profitTotal = (int) $sales->sum('net_profit');

        return [
            'sales_total' => $salesTotal,
            'sales_fee_total' => $salesFeeTotal,
            'profit_total' => $profitTotal,
            'sale_count' => $sales->count(),
            'sales_data_available' => $sales->isNotEmpty(),
            'quantity_total' => (int) $sales->sum('quantity'),
            'profit_margin' => $salesTotal === 0 ? 0.0 : round(($profitTotal / $salesTotal) * 100, 1),
        ];
    }

    /** @param Collection<int, Sale> $sales
     * @param  callable(Sale): string  $label
     * @return Collection<int, array<string, int|string>>
     */
    private function rowsBy(Collection $sales, callable $label): Collection
    {
        $totalSales = (int) $sales->sum('sold_price');

        return $sales
            ->groupBy($label)
            ->map(function (Collection $group, string $name) use ($totalSales): array {
                $salesTotal = (int) $group->sum('sold_price');
                $salesFeeTotal = (int) $group->sum('sales_fee');
                $profitTotal = (int) $group->sum('net_profit');

                return [
                    'name' => $name,
                    'sales_total' => $salesTotal,
                    'sales_fee_total' => $salesFeeTotal,
                    'profit_total' => $profitTotal,
                    'sale_count' => $group->count(),
                    'quantity_total' => (int) $group->sum('quantity'),
                    'profit_margin' => $salesTotal === 0 ? 0.0 : round(($profitTotal / $salesTotal) * 100, 1),
                    'share' => $totalSales === 0 ? 0.0 : round(($salesTotal / $totalSales) * 100, 1),
                ];
            })
            ->sortByDesc('sales_total')
            ->values();
    }

    private function rootCategoryName(?ProductCategory $category): string
    {
        if ($category === null) {
            return '未分類';
        }

        while ($category->parent !== null) {
            $category = $category->parent;
        }

        return $category->name;
    }

    private function categoryPath(?ProductCategory $category): string
    {
        if ($category === null) {
            return '未分類';
        }

        $segments = [];
        do {
            array_unshift($segments, $category->name);
            $category = $category->parent;
        } while ($category !== null);

        return implode(' / ', $segments);
    }

    private function categoryNameAtDepth(?ProductCategory $category, int $depth): string
    {
        if ($category === null) {
            return '未分類';
        }

        $segments = [];
        do {
            array_unshift($segments, $category->name);
            $category = $category->parent;
        } while ($category !== null);

        return implode(' / ', array_slice($segments, 0, min($depth, count($segments))));
    }

    /**
     * @param  Collection<int, Sale>  $sales
     * @return array{sales: int, profit: int, sale_count: int}
     */
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

    private function crossMetrics(Collection $sales): array
    {
        $salesTotal = (int) $sales->sum('sold_price');
        $salesFeeTotal = (int) $sales->sum('sales_fee');
        $profitTotal = (int) $sales->sum('net_profit');

        return [
            'sales' => $salesTotal,
            'sales_fee' => $salesFeeTotal,
            'profit' => $profitTotal,
            'sale_count' => $sales->count(),
        ];
    }
}
