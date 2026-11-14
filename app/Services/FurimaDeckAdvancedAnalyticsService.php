<?php

namespace App\Services;

use App\Models\ProductInventoryEvent;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

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
            'brand' => $this->brandAnalysis($sales),
            'weekday' => $this->groupSales($sales, fn (Sale $sale): string => $sale->sold_at->isoFormat('dddd')),
            'seasonality' => $this->seasonalityAnalysis($sales),
            'inventory' => $this->inventory($user),
            'supplier' => $this->supplierAnalysis($user, $sales),
            'matrix' => $this->profitMatrix($user, $sales),
        ];
    }

    /** @return Collection<int, Sale> */
    private function sales(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $user->sales()->with([
            'listing:id,listed_at',
            'product.supplier',
            'product.attributeValues.categoryAttribute',
            'product.category.parent.parent',
        ])
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

    /** @param Collection<int, Sale> $sales */
    private function brandAnalysis(Collection $sales): array
    {
        return $sales->groupBy(fn (Sale $sale): string => $this->brandFor($sale))
            ->map(function (Collection $items, string $brand): array {
                $amount = (int) $items->sum('sold_price');

                return [
                    'name' => $brand,
                    'sales_amount' => $amount,
                    'sale_count' => $items->count(),
                    'profit' => (int) $items->sum('net_profit'),
                    'profit_margin' => $amount === 0 ? 0.0 : round($items->sum('net_profit') / $amount * 100, 1),
                    'median_sale_days' => $this->median($items->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values()),
                    'average_sold_price' => $items->isEmpty() ? 0 : (int) round($amount / $items->count()),
                ];
            })
            ->sortByDesc('sales_amount')
            ->values()
            ->all();
    }

    private function brandFor(Sale $sale): string
    {
        foreach ($sale->product?->attributeValues ?? [] as $attributeValue) {
            $attribute = $attributeValue->categoryAttribute;
            if (strtolower((string) $attribute?->key) !== 'brand') {
                continue;
            }

            $value = trim((string) $attributeValue->value_text);
            if ($value !== '') {
                return $value;
            }
        }

        return '未設定';
    }

    /** @param Collection<int, Sale> $sales */
    private function seasonalityAnalysis(Collection $sales): array
    {
        $months = $sales->groupBy(fn (Sale $sale): string => $sale->sold_at->format('Y-m'));
        $isReference = $months->count() < 12;

        return $months->map(function (Collection $items, string $month) use ($isReference): array {
            $amount = (int) $items->sum('sold_price');
            $genres = $items->groupBy(fn (Sale $sale): string => $this->genreFor($sale))
                ->map(fn (Collection $genreSales, string $genre): array => [
                    'name' => $genre,
                    'sale_count' => $genreSales->count(),
                    'sales_amount' => (int) $genreSales->sum('sold_price'),
                    'profit' => (int) $genreSales->sum('net_profit'),
                ])
                ->sortByDesc('sales_amount')
                ->values()
                ->all();

            return [
                'label' => $month,
                'sale_count' => $items->count(),
                'sales_amount' => $amount,
                'profit' => (int) $items->sum('net_profit'),
                'profit_margin' => $amount === 0 ? 0.0 : round($items->sum('net_profit') / $amount * 100, 1),
                'genres' => $genres,
                'is_reference' => $isReference,
            ];
        })->values()->all();
    }

    private function genreFor(Sale $sale): string
    {
        $category = $sale->product?->category;
        if ($category === null) {
            return '未設定';
        }

        $path = collect([$category->parent?->parent?->name, $category->parent?->name, $category->name])
            ->filter(fn (?string $name): bool => filled($name))
            ->values();

        return $path->isEmpty() ? '未設定' : $path->implode(' > ');
    }

    /** @param Collection<int, Sale> $sales @return array<string, mixed> */
    private function profitMatrix(User $user, Collection $sales): array
    {
        $productGroups = $sales->groupBy(fn (Sale $sale): string => $sale->product_id === null
            ? 'sale:'.$sale->id
            : 'product:'.$sale->product_id);
        $productRows = $productGroups->map(function (Collection $items): ?array {
            $sale = $items->first();
            $name = $sale->product?->product_name ?? $sale->product_name_snapshot ?? '商品名未設定';

            return $this->matrixGroupRow($items, $name, $sale->product_id === null ? null : (int) $sale->product_id);
        })->filter()->values();
        $productMatrix = $this->classifyMatrixGroups($productRows);

        $supplierSnapshots = $this->saleSupplierSnapshots($user, $sales);
        $supplierKey = fn (Sale $sale): string => $this->supplierKey($this->supplierForSale($sale, $supplierSnapshots)['supplier_id']);
        $supplierName = fn (Sale $sale): string => $this->supplierForSale($sale, $supplierSnapshots)['supplier_name'] ?? '未設定';

        $productMatrix['dimensions'] = [
            'genre' => $this->profitMatrixForDimension($sales, fn (Sale $sale): string => $this->genreFor($sale)),
            'brand' => $this->profitMatrixForDimension($sales, fn (Sale $sale): string => $this->brandFor($sale)),
            'supplier' => $this->profitMatrixForDimension($sales, $supplierKey, $supplierName),
        ];

        return $productMatrix;
    }

    /** @param Collection<int, Sale> $sales @return array<string, mixed> */
    private function profitMatrixForDimension(Collection $sales, callable $key, ?callable $label = null): array
    {
        $groups = $sales->groupBy($key)->map(function (Collection $items) use ($key, $label): ?array {
            $name = $label === null ? (string) $key($items->first()) : (string) $label($items->first());

            return $this->matrixGroupRow($items, $name, null);
        })->filter()->values();

        return $this->classifyMatrixGroups($groups);
    }

    /** @param Collection<int, Sale> $items @return array<string, mixed>|null */
    private function matrixGroupRow(Collection $items, string $name, ?int $productId): ?array
    {
        $days = $items->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();
        if ($days->isEmpty()) {
            return null;
        }

        $amount = (int) $items->sum('sold_price');
        $profit = (int) $items->sum('net_profit');

        return [
            'product_id' => $productId,
            'product_name' => $name,
            'profit_margin' => $amount === 0 ? 0.0 : round($profit / $amount * 100, 1),
            'profit_velocity' => round($profit / max(1, (float) $days->median()), 1),
            'sample_count' => $items->count(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $groups @return array<string, mixed> */
    private function classifyMatrixGroups(Collection $groups): array
    {
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

    /** @param array<int, array<string, mixed>> $snapshots @return array{supplier_id: int|null, supplier_name: string|null} */
    private function supplierForSale(Sale $sale, array $snapshots): array
    {
        $snapshot = $snapshots[(int) $sale->id] ?? null;

        return [
            'supplier_id' => is_array($snapshot) && array_key_exists('supplier_id', $snapshot)
                ? ($snapshot['supplier_id'] === null ? null : (int) $snapshot['supplier_id'])
                : ($sale->product?->supplier_id === null ? null : (int) $sale->product?->supplier_id),
            'supplier_name' => is_array($snapshot) && array_key_exists('supplier_name', $snapshot)
                ? ($snapshot['supplier_name'] === null ? null : (string) $snapshot['supplier_name'])
                : $sale->product?->supplier?->name,
        ];
    }

    private function supplierAnalysis(User $user, Collection $sales): array
    {
        $inventory = $user->products()
            ->with('supplier:id,name')
            ->get(['id', 'supplier_id', 'purchase_unit_cost', 'quantity_available']);
        $rows = [];
        foreach ($inventory as $product) {
            $supplierId = $product->supplier_id === null ? null : (int) $product->supplier_id;
            $key = $this->supplierKey($supplierId);
            $rows[$key] ??= $this->emptySupplierRow($supplierId, $product->supplier?->name);
            $rows[$key]['purchase_count']++;
            if ((int) $product->quantity_available > 0) {
                $rows[$key]['inventory_capital'] += (int) $product->purchase_unit_cost * (int) $product->quantity_available;
            }
        }

        $saleSupplierSnapshots = $this->saleSupplierSnapshots($user, $sales);
        foreach ($sales as $sale) {
            $snapshot = $saleSupplierSnapshots[(int) $sale->id] ?? null;
            $supplierId = is_array($snapshot) && array_key_exists('supplier_id', $snapshot)
                ? ($snapshot['supplier_id'] === null ? null : (int) $snapshot['supplier_id'])
                : ($sale->product?->supplier_id === null ? null : (int) $sale->product->supplier_id);
            $supplierName = is_array($snapshot) && array_key_exists('supplier_name', $snapshot)
                ? ($snapshot['supplier_name'] === null ? null : (string) $snapshot['supplier_name'])
                : $sale->product?->supplier?->name;
            $key = $this->supplierKey($supplierId);
            $rows[$key] ??= $this->emptySupplierRow($supplierId, $supplierName);
            if ($supplierName !== null && $supplierName !== '') {
                $rows[$key]['name'] = $supplierName;
            }
            $rows[$key]['sales_amount'] += (int) $sale->sold_price;
            $rows[$key]['sales_fee'] += (int) $sale->sales_fee;
            $rows[$key]['sale_cost'] += (int) $sale->cost_basis;
            $rows[$key]['profit'] += (int) $sale->net_profit;
            $rows[$key]['sale_count']++;
            $rows[$key]['sale_days'][] = $this->saleDaysFor($sale);
        }

        foreach ($rows as &$row) {
            $row['profit_margin'] = $row['sales_amount'] === 0 ? 0.0 : round($row['profit'] / $row['sales_amount'] * 100, 1);
            $row['median_sale_days'] = $this->median(collect($row['sale_days'])->filter()->values());
            unset($row['sale_days']);
        }
        unset($row);

        return collect($rows)->sortByDesc('sales_amount')->values()->all();
    }

    /** @param Collection<int, Sale> $sales @return array<int, array<string, mixed>> */
    private function saleSupplierSnapshots(User $user, Collection $sales): array
    {
        if ($sales->isEmpty() || ! Schema::connection('furimadeck')->hasTable('product_inventory_events')) {
            return [];
        }

        return ProductInventoryEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', ProductInventoryEvent::TYPE_SALE)
            ->where('source_type', 'sale')
            ->whereIn('source_id', $sales->pluck('id')->map(fn ($id): int => (int) $id)->all())
            ->get(['source_id', 'before_state'])
            ->mapWithKeys(fn (ProductInventoryEvent $event): array => [(int) $event->source_id => (array) $event->before_state])
            ->all();
    }

    private function supplierKey(?int $supplierId): string
    {
        return $supplierId === null ? 'unset' : 'supplier:'.$supplierId;
    }

    /** @return array<string, mixed> */
    private function emptySupplierRow(?int $supplierId, ?string $name): array
    {
        return [
            'supplier_id' => $supplierId,
            'name' => filled($name) ? $name : '未設定',
            'purchase_count' => 0,
            'sales_amount' => 0,
            'sales_fee' => 0,
            'sale_cost' => 0,
            'profit' => 0,
            'sale_count' => 0,
            'profit_margin' => 0.0,
            'median_sale_days' => null,
            'inventory_capital' => 0,
            'sale_days' => [],
        ];
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
