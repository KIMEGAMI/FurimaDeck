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
    private const VALID_SALE_STATUSES = ['pending', 'awaiting_shipment', 'shipped', 'completed'];

    /** @return array<string, array<int, array<string, mixed>>> */
    public function actions(User $user, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now();
        $products = $user->products()->with(['category.parent.parent', 'listings.marketplace', 'sales'])->get();
        $sales = $user->sales()->with(['product.category.parent.parent', 'listing.marketplace'])->whereIn('status', self::VALID_SALE_STATUSES)->get();

        return [
            'stagnant_stock' => $this->stagnantStock($products, $today),
            'price_review' => $this->priceReview($products, $sales, $today),
            'relist' => $this->relist($products, $today),
            'marketplace_change' => $this->marketplaceChange($products, $sales, $today),
            'rebuy' => $this->rebuy($sales),
        ];
    }

    /** @param Collection<int, Product> $products */
    private function stagnantStock(Collection $products, CarbonImmutable $today): array
    {
        $thresholds = config('furimadeck.improvement.stagnant_days', [30, 60, 90]);

        return $products->filter(function (Product $product): bool {
            return $product->quantity_available > 0
                && $product->purchase_date !== null
                && ! $product->sales->contains(fn (Sale $sale): bool => in_array($sale->status, Sale::VALID_SOLD_STATUSES, true));
        })->map(function (Product $product) use ($today, $thresholds): array {
            $days = $product->purchase_date->diffInDays($today, false);
            $level = $days < 0 ? null : ($days >= $thresholds[2] ? 'high' : ($days >= $thresholds[1] ? 'medium' : ($days >= $thresholds[0] ? 'notice' : null)));

            return ['product_id' => $product->id, 'product_name' => $product->product_name, 'stagnant_days' => $days, 'priority' => $level, 'purchase_amount' => (int) $product->purchase_unit_cost * (int) $product->quantity_available];
        })->filter(static fn (array $item): bool => $item['priority'] !== null)->values()->all();
    }

    /** @param Collection<int, Product> $products
     * @param  Collection<int, Sale>  $sales
     */
    private function priceReview(Collection $products, Collection $sales, CarbonImmutable $today): array
    {
        $minimum = (int) config('furimadeck.improvement.price_review_min_sales', 5);
        $deviation = (float) config('furimadeck.improvement.price_review_deviation_percent', 10);

        return $products->flatMap(function (Product $product) use ($sales, $minimum, $deviation, $today): Collection {
            $listing = $product->listings->first(fn (Listing $listing): bool => $listing->status === 'active' && $listing->listing_price !== null);
            if ($listing === null || $listing->listed_at === null) {
                return collect();
            }
            $comparables = $sales->filter(fn (Sale $sale): bool => $sale->product?->category_id === $product->category_id && $sale->sold_price > 0);
            if ($comparables->count() < $minimum) {
                return collect();
            }
            $median = $this->median($comparables->pluck('sold_price'));
            if ($median === null || abs($listing->listing_price - $median) / $median * 100 < $deviation) {
                return collect();
            }

            return collect([['product_id' => $product->id, 'product_name' => $product->product_name, 'current_price' => (int) $listing->listing_price, 'median_sold_price' => $median, 'sample_count' => $comparables->count(), 'stagnant_days' => (int) $listing->listed_at->diffInDays($today, false), 'reason' => '価格見直しの参考']]);
        })->values()->all();
    }

    /** @param Collection<int, Product> $products */
    private function relist(Collection $products, CarbonImmutable $today): array
    {
        $days = (int) config('furimadeck.improvement.relist_after_days', 60);

        return $products->flatMap(function (Product $product) use ($today, $days): Collection {
            $listing = $product->listings->first(fn (Listing $listing): bool => $listing->status === 'active' && $listing->listed_at !== null && (int) $listing->listed_at->diffInDays($today, false) >= $days);

            return $listing === null ? collect() : collect([['product_id' => $product->id, 'product_name' => $product->product_name, 'stagnant_days' => (int) $listing->listed_at->diffInDays($today, false), 'reason' => '再出品を検討']]);
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
            $listing = $product->listings->first(fn (Listing $item): bool => $item->status === 'active' && $item->listed_at !== null && $item->listed_at->diffInDays($today, false) >= $days);
            if ($listing === null) {
                return collect();
            }
            $comparables = $sales->filter(fn (Sale $sale): bool => $sale->product?->category_id === $product->category_id && $sale->listing?->marketplace_id !== $listing->marketplace_id && $sale->listing?->listed_at !== null && $sale->sold_at !== null && $sale->listing->listed_at->diffInDays($sale->sold_at, false) >= 0);
            $group = $comparables->groupBy('marketplace_id')->map(fn (Collection $items): array => ['marketplace_id' => $items->first()->listing->marketplace_id, 'median_days' => $this->median($items->map(fn (Sale $sale): int => max(1, $sale->listing->listed_at->diffInDays($sale->sold_at, false)))), 'count' => $items->count()])->filter(fn (array $item): bool => $item['count'] >= $minimum)->sortBy('median_days')->first();
            if ($group === null || $group['median_days'] === null) {
                return collect();
            }
            $currentDays = (int) $listing->listed_at->diffInDays($today, false);
            if ($group['median_days'] >= $currentDays || (($currentDays - $group['median_days']) / $currentDays * 100) < $difference) {
                return collect();
            }

            return collect([['product_id' => $product->id, 'product_name' => $product->product_name, 'sample_count' => $group['count'], 'comparison_median_days' => $group['median_days'], 'reason' => '出品先変更を検討']]);
        })->values()->all();
    }

    /** @param Collection<int, Sale> $sales */
    private function rebuy(Collection $sales): array
    {
        $minimum = (int) config('furimadeck.improvement.rebuy_min_sales', 3);

        return $sales->groupBy(fn (Sale $sale): string => (string) ($sale->product?->category_id ?? 'unset'))->map(function (Collection $items) use ($minimum): ?array {
            if ($items->count() < $minimum) {
                return null;
            }
            $profit = (int) $items->sum('net_profit');
            $amount = (int) $items->sum('sold_price');

            return ['category_id' => $items->first()->product?->category_id, 'sample_count' => $items->count(), 'profit' => $profit, 'profit_margin' => $amount === 0 ? 0.0 : round($profit / $amount * 100, 1), 'reason' => '再仕入れ候補'];
        })->filter()->values()->all();
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
