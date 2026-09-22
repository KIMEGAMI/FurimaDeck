<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Sale;
use App\Models\User;

class TodayWorkService
{
    /** @return array<string, int> */
    public function summary(User $user): array
    {
        $longTermInventoryDays = (int) config('furimadeck.inventory.long_term_days');
        $longTermInventoryDate = now()->subDays($longTermInventoryDays)->toDateString();

        return [
            'stock_check' => $user->products()->where('quantity_available', '>', 0)->count(),
            'awaiting_shipment' => $user->sales()->whereIn('status', ['pending', 'awaiting_shipment'])->count(),
            'long_term_inventory' => $user->products()
                ->where('quantity_available', '>', 0)
                ->whereHas('listings', fn ($listing) => $listing
                    ->whereIn('status', Listing::STALE_INVENTORY_STATUSES)
                    ->whereNotNull('listed_at')
                    ->whereDate('listed_at', '<=', $longTermInventoryDate))
                ->whereDoesntHave('sales', fn ($sales) => $sales->whereIn('status', Sale::VALID_SOLD_STATUSES))
                ->count(),
            'other_listing_checks' => Listing::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['ready', 'active'])
                ->whereHas('product.sales', fn ($query) => $query->whereNotIn('status', ['cancelled', 'returned']))
                ->count(),
        ];
    }
}
