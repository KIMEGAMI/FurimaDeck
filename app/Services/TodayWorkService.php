<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;

class TodayWorkService
{
    /** @return array<string, int> */
    public function summary(User $user): array
    {
        $longTermInventoryDays = (int) config('furimadeck.inventory.long_term_days');
        $longTermInventoryDate = now()->subDays($longTermInventoryDays)->toDateString();

        return [
            'listing_ready' => $user->products()->where('inventory_status', 'listing_ready')->count(),
            'awaiting_shipment' => $user->sales()->whereIn('status', ['pending', 'awaiting_shipment'])->count(),
            'long_term_inventory' => $user->products()
                ->where('quantity_available', '>', 0)
                ->whereNotNull('purchase_date')
                ->whereDate('purchase_date', '<=', $longTermInventoryDate)
                ->count(),
            'other_listing_checks' => Listing::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['ready', 'active'])
                ->whereHas('product.sales', fn ($query) => $query->whereNotIn('status', ['cancelled', 'returned']))
                ->count(),
        ];
    }
}
