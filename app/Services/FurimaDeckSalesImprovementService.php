<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FurimaDeckSalesImprovementService
{
    private const IN_PROGRESS_SALE_STATUSES = ['pending', 'awaiting_shipment', 'shipped'];

    /** @return array<string, array<int, array<string, mixed>>> */
    public function actions(User $user, ?CarbonImmutable $today = null, ?int $stagnantDays = null): array
    {
        $today ??= CarbonImmutable::now();
        $stagnantDays ??= (int) config('furimadeck.improvement.stagnant_days.0');
        $products = $user->products()->with([
            'category.parent.parent',
            'attributeValues.categoryAttribute',
            'listings.marketplace',
            'sales',
        ])->get();
        $sales = $user->sales()->with([
            'product.category.parent.parent',
            'product.supplier',
            'product.attributeValues.categoryAttribute',
            'listing.marketplace',
        ])->whereIn('status', Sale::VALID_SOLD_STATUSES)->get();

        return [
            'stagnant_stock' => $this->stagnantStock($products, $sales, $today, $stagnantDays),
            'price_review' => $this->priceReview($products, $sales, $today),
            'relist' => $this->relist($products, $today),
            'marketplace_change' => $this->marketplaceChange($products, $sales, $today),
            'rebuy' => $this->rebuy($sales),
        ];
    }

    /** @param Collection<int, Product> $products */
    private function stagnantStock(Collection $products, Collection $sales, CarbonImmutable $today, int $minimumDays): array
    {
        $thresholds = config('furimadeck.improvement.stagnant_days', [30, 60, 90]);

        return $products->flatMap(function (Product $product) use ($today, $thresholds, $sales, $minimumDays): Collection {
            if (! $this->canRecommendListingChanges($product)) {
                return collect();
            }

            return $product->listings
                ->filter(fn (Listing $listing): bool => $listing->status === 'active' && $listing->listed_at !== null)
                ->map(function (Listing $listing) use ($product, $sales, $today, $thresholds, $minimumDays): ?array {
                    $days = (int) $listing->listed_at->diffInDays($today, false);
                    $level = $days < $minimumDays ? null : ($days >= $thresholds[2] ? 'high' : ($days >= $thresholds[1] ? 'medium' : 'notice'));
                    if ($level === null) {
                        return null;
                    }
                    $comparables = $this->comparablesFor($product, $sales);
                    $saleDays = $comparables->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();

                    return [
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'marketplace' => $listing->marketplace?->name ?? '未設定',
                        'current_price' => $listing->listing_price === null ? null : (int) $listing->listing_price,
                        'stagnant_days' => max(0, $days),
                        'priority' => $level,
                        'purchase_amount' => (int) $product->purchase_unit_cost * (int) $product->quantity_available,
                        'comparison_sample_count' => $comparables->count(),
                        'comparable_median_sale_days' => $this->median($saleDays),
                        'comparable_median_sold_price' => $this->median($comparables->pluck('sold_price')->map(fn ($price): int => (int) $price)),
                    ];
                })
                ->filter()
                ->values();
        })->values()->all();
    }

    /** @param Collection<int, Product> $products
     * @param  Collection<int, Sale>  $sales
     */
    private function priceReview(Collection $products, Collection $sales, CarbonImmutable $today): array
    {
        $minimum = (int) config('furimadeck.improvement.price_review_min_sales', 5);
        $deviation = (float) config('furimadeck.improvement.price_review_deviation_percent', 10);
        $minimumStagnantDays = (int) (config('furimadeck.improvement.stagnant_days')[0] ?? 30);

        return $products->flatMap(function (Product $product) use ($sales, $minimum, $deviation, $minimumStagnantDays, $today): Collection {
            if (! $this->canRecommendListingChanges($product)) {
                return collect();
            }

            return $product->listings
                ->filter(fn (Listing $listing): bool => $listing->status === 'active' && $listing->listing_price !== null && $listing->listed_at !== null)
                ->map(function (Listing $listing) use ($product, $sales, $minimum, $deviation, $minimumStagnantDays, $today): ?array {
                    $stagnantDays = (int) $listing->listed_at->diffInDays($today, false);
                    if ($stagnantDays < $minimumStagnantDays) {
                        return null;
                    }
                    $comparables = $this->comparablesFor($product, $sales)->filter(fn (Sale $sale): bool => (int) $sale->sold_price > 0);
                    if ($comparables->count() < $minimum) {
                        return null;
                    }
                    $median = $this->median($comparables->pluck('sold_price')->map(fn ($price): int => (int) $price));
                    if ($median === null || $median <= 0 || abs((int) $listing->listing_price - $median) / $median * 100 < $deviation) {
                        return null;
                    }
                    $saleDays = $comparables->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();
                    $marketplace = $listing->marketplace?->name ?? '未設定';

                    return [
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'marketplace' => $marketplace,
                        'current_price' => (int) $listing->listing_price,
                        'purchase_amount' => (int) $product->purchase_unit_cost,
                        'median_sold_price' => $median,
                        'price_range_min' => (int) $comparables->min('sold_price'),
                        'price_range_max' => (int) $comparables->max('sold_price'),
                        'sample_count' => $comparables->count(),
                        'median_sale_days' => $this->median($saleDays),
                        'stagnant_days' => max(0, $stagnantDays),
                        'reason' => sprintf('出品後%d日、比較可能なSOLD実績%d件の売価中央値は%d円です。', max(0, $stagnantDays), $comparables->count(), (int) $median),
                    ];
                })
                ->filter()
                ->values();
        })->values()->all();
    }

    /** @param Collection<int, Product> $products */
    private function relist(Collection $products, CarbonImmutable $today): array
    {
        $days = (int) config('furimadeck.improvement.relist_after_days', 60);

        return $products->flatMap(function (Product $product) use ($today, $days): Collection {
            if (! $this->canRecommendListingChanges($product)) {
                return collect();
            }

            return $product->listings
                ->filter(fn (Listing $listing): bool => $listing->status === 'active' && $listing->listed_at !== null && (int) $listing->listed_at->diffInDays($today, false) >= $days)
                ->map(fn (Listing $listing): array => [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'marketplace' => $listing->marketplace?->name ?? '未設定',
                    'current_price' => $listing->listing_price === null ? null : (int) $listing->listing_price,
                    'stagnant_days' => max(0, (int) $listing->listed_at->diffInDays($today, false)),
                    'reason' => '再出品を検討',
                ])
                ->values();
        })->values()->all();
    }

    /** @param Collection<int, Product> $products
     * @param  Collection<int, Sale>  $sales
     */
    private function marketplaceChange(Collection $products, Collection $sales, CarbonImmutable $today): array
    {
        $minimum = (int) config('furimadeck.improvement.marketplace_min_sales', 3);
        $days = (int) config('furimadeck.improvement.marketplace_change_after_days', 60);
        $difference = (float) config('furimadeck.improvement.marketplace_speed_difference_percent', 20);

        return $products->flatMap(function (Product $product) use ($sales, $today, $minimum, $days, $difference): Collection {
            if (! $this->canRecommendListingChanges($product)) {
                return collect();
            }

            return $product->listings
                ->filter(fn (Listing $listing): bool => $listing->status === 'active' && $listing->listed_at !== null && (int) $listing->listed_at->diffInDays($today, false) >= $days)
                ->flatMap(function (Listing $listing) use ($product, $sales, $minimum, $difference, $today): Collection {
                    $comparables = $this->comparablesFor($product, $sales)
                        ->filter(fn (Sale $sale): bool => $sale->listing?->marketplace_id !== $listing->marketplace_id);
                    $groups = $comparables->groupBy('marketplace_id')->map(function (Collection $items): array {
                        $saleDays = $items->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();

                        return [
                            'marketplace_id' => $items->first()->listing?->marketplace_id,
                            'marketplace' => $items->first()->listing?->marketplace?->name ?? '未設定',
                            'median_days' => $this->median($saleDays),
                            'count' => $saleDays->count(),
                        ];
                    })->filter(fn (array $group): bool => $group['count'] >= $minimum && $group['marketplace_id'] !== null);
                    $currentSales = $this->comparablesFor($product, $sales)
                        ->filter(fn (Sale $sale): bool => $sale->listing?->marketplace_id === $listing->marketplace_id);
                    $currentDays = $currentSales->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();
                    if ($currentDays->count() < $minimum) {
                        return collect();
                    }
                    $currentMedian = $this->median($currentDays);
                    $bestAlternative = $groups->filter(fn (array $group): bool => $group['median_days'] !== null)
                        ->sortBy('median_days')
                        ->first();
                    if ($currentMedian === null || $currentMedian <= 0 || $bestAlternative === null || $bestAlternative['median_days'] >= $currentMedian
                        || (($currentMedian - $bestAlternative['median_days']) / $currentMedian * 100) < $difference) {
                        return collect();
                    }

                    return collect([[
                        'product_id' => $product->id,
                        'product_name' => $product->product_name,
                        'current_marketplace' => $listing->marketplace?->name ?? '未設定',
                        'alternative_marketplace' => $bestAlternative['marketplace'],
                        'current_sample_count' => $currentDays->count(),
                        'sample_count' => $bestAlternative['count'],
                        'current_median_days' => $currentMedian,
                        'comparison_median_days' => $bestAlternative['median_days'],
                        'stagnant_days' => max(0, (int) $listing->listed_at->diffInDays($today, false)),
                        'reason' => sprintf('現在の出品先の販売日数中央値%d日（%d件）に対し、%sでは%d日（%d件）です。', (int) $currentMedian, $currentDays->count(), $bestAlternative['marketplace'], (int) $bestAlternative['median_days'], $bestAlternative['count']),
                    ]]);
                })
                ->values();
        })->values()->all();
    }

    /** @param Collection<int, Sale> $sales */
    private function rebuy(Collection $sales): array
    {
        $minimum = (int) config('furimadeck.improvement.rebuy_min_sales', 3);
        $userMarginMedian = $this->median($sales->map(function (Sale $sale): ?float {
            $amount = (int) $sale->sold_price;

            return $amount <= 0 ? null : round((int) $sale->net_profit / $amount * 100, 1);
        })->filter(fn (?float $value): bool => $value !== null)->values());
        $userSaleDaysMedian = $this->median($sales->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values());
        if ($userMarginMedian === null || $userSaleDaysMedian === null) {
            return [];
        }

        $groups = collect();
        foreach ($sales as $sale) {
            $product = $sale->product;
            if ($product === null) {
                continue;
            }
            $dimensions = [];
            if ($product->category_id !== null) {
                $dimensions['category:'.$product->category_id] = [
                    'dimension' => 'カテゴリ',
                    'label' => $this->categoryPath($product),
                    'category_id' => (int) $product->category_id,
                ];
            }
            $brand = $this->brandFor($product);
            if ($brand !== null) {
                $dimensions['brand:'.mb_strtolower($brand)] = ['dimension' => 'ブランド', 'label' => $brand, 'category_id' => null];
            }
            if ($product->supplier_id !== null) {
                $dimensions['supplier:'.$product->supplier_id] = [
                    'dimension' => '仕入先',
                    'label' => $product->supplier?->name ?? '仕入先未設定',
                    'category_id' => null,
                ];
            }
            foreach ($dimensions as $key => $dimension) {
                $bucket = $groups->get($key, ['dimension' => $dimension, 'sales' => collect()]);
                $bucket['sales']->push($sale);
                $groups->put($key, $bucket);
            }
        }

        return $groups->map(function (array $group) use ($minimum, $userMarginMedian, $userSaleDaysMedian): ?array {
            /** @var Collection<int, Sale> $items */
            $items = $group['sales'];
            $margins = $items->map(function (Sale $sale): ?float {
                $amount = (int) $sale->sold_price;

                return $amount <= 0 ? null : round((int) $sale->net_profit / $amount * 100, 1);
            })->filter(fn (?float $value): bool => $value !== null)->values();
            $days = $items->map(fn (Sale $sale): ?int => $this->saleDaysFor($sale))->filter()->values();
            $margin = $this->median($margins);
            $medianDays = $this->median($days);
            if ($items->count() < $minimum || $margin === null || $medianDays === null
                || $margin < $userMarginMedian || $medianDays > $userSaleDaysMedian) {
                return null;
            }
            $profit = (int) $items->sum('net_profit');
            $amount = (int) $items->sum('sold_price');

            return [
                'category_id' => $group['dimension']['category_id'],
                'dimension' => $group['dimension']['dimension'],
                'label' => $group['dimension']['label'],
                'sample_count' => $items->count(),
                'profit' => $profit,
                'profit_margin' => $amount === 0 ? 0.0 : round($profit / $amount * 100, 1),
                'median_sale_days' => $medianDays,
                'reason' => sprintf('販売実績%d件、実利益率中央値%.1f%%、販売日数中央値%d日。全体中央値（%.1f%%、%d日）以上の実績です。', $items->count(), $margin, (int) $medianDays, $userMarginMedian, (int) $userSaleDaysMedian),
            ];
        })->filter()->values()->all();
    }

    private function categoryPath(Product $product): string
    {
        $category = $product->category;
        if ($category === null) {
            return '未分類';
        }
        $path = collect([$category->parent?->parent?->name, $category->parent?->name, $category->name])
            ->filter(fn (?string $name): bool => filled($name))
            ->values();

        return $path->implode(' > ');
    }

    /** @param Collection<int, Sale> $sales @return Collection<int, Sale> */
    private function comparablesFor(Product $product, Collection $sales): Collection
    {
        $brand = $this->brandFor($product);

        return $sales->filter(function (Sale $sale) use ($product, $brand): bool {
            if ($sale->product === null || (int) $sale->product_id === (int) $product->id) {
                return false;
            }
            if ($product->category_id !== null && (int) $sale->product->category_id === (int) $product->category_id) {
                return true;
            }

            return $brand !== null && $brand === $this->brandFor($sale->product);
        });
    }

    private function brandFor(Product $product): ?string
    {
        foreach ($product->attributeValues as $attributeValue) {
            if (strtolower((string) $attributeValue->categoryAttribute?->key) === 'brand') {
                $value = trim((string) $attributeValue->value_text);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function hasSaleInProgress(Product $product): bool
    {
        return $product->sales->contains(fn (Sale $sale): bool => in_array($sale->status, self::IN_PROGRESS_SALE_STATUSES, true));
    }

    private function canRecommendListingChanges(Product $product): bool
    {
        return (int) $product->quantity_available > 0
            && ! $this->hasSaleInProgress($product)
            && ! $product->sales->contains(fn (Sale $sale): bool => in_array($sale->status, Sale::VALID_SOLD_STATUSES, true));
    }

    private function saleDaysFor(Sale $sale): ?int
    {
        if ($sale->listing?->listed_at === null || $sale->sold_at === null) {
            return null;
        }
        $days = $sale->listing->listed_at->diffInDays($sale->sold_at, false);

        return $days < 0 ? null : max(1, (int) $days);
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
