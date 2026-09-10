<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SaleLifecycleService
{
    public function __construct(private readonly SaleProfitCalculator $profitCalculator) {}

    /** @param array<string, int|string|null> $values */
    public function record(User $user, array $values): Sale
    {
        return DB::transaction(function () use ($user, $values): Sale {
            $product = Product::query()->whereKey($values['product_id'])->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
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

            $sale = $user->sales()->create($profitValues + [
                'product_id' => $product->id,
                'listing_id' => $listing?->id,
                'marketplace_id' => $marketplaceId,
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => $quantity,
                'sold_at' => $values['sold_at'],
                'status' => Sale::STATUSES[1],
                'net_profit' => $this->profitCalculator->calculate($profitValues),
            ]);

            $product->decrement('quantity_available', $quantity);
            $product->refresh();

            if ($product->quantity_available === 0) {
                $product->update(['inventory_status' => 'out_of_stock']);
            }

            if ($listing !== null) {
                $listing->update(['status' => 'sold', 'ended_at' => now()]);
            }

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
            $product = Product::query()->whereKey($lockedSale->product_id)->lockForUpdate()->firstOrFail();
            $product->increment('quantity_available', $lockedSale->quantity);
            $product->update(['inventory_status' => 'in_stock']);
            $lockedSale->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => $reason]);

            return $lockedSale;
        });
    }

    public function returnSale(User $user, Sale $sale, string $reason, int $refundAmount, int $returnShippingFee, bool $restock): Sale
    {
        return DB::transaction(function () use ($user, $sale, $reason, $refundAmount, $returnShippingFee, $restock): Sale {
            $lockedSale = Sale::query()->whereKey($sale->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (in_array($lockedSale->status, ['cancelled', 'returned'], true)) {
                throw new RuntimeException('この取引は返品処理できません。');
            }
            $restockedQuantity = $restock ? $lockedSale->quantity : 0;
            if ($restock) {
                $product = Product::query()->whereKey($lockedSale->product_id)->lockForUpdate()->firstOrFail();
                $product->increment('quantity_available', $restockedQuantity);
                $product->update(['inventory_status' => 'in_stock']);
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
}
