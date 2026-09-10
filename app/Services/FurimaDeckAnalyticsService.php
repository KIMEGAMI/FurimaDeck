<?php

namespace App\Services;

use App\Models\User;

class FurimaDeckAnalyticsService
{
    /** @return array<string, int|float> */
    public function summary(User $user): array
    {
        $sales = $user->sales()->whereNotIn('status', ['cancelled', 'returned']);
        $salesTotal = (int) $sales->sum('sold_price');
        $profitTotal = (int) $user->sales()->whereNotIn('status', ['cancelled', 'returned'])->sum('net_profit');
        $inventory = $user->products()->where('quantity_available', '>', 0);

        return [
            'sales_total' => $salesTotal,
            'profit_total' => $profitTotal,
            'sale_count' => (int) $user->sales()->whereNotIn('status', ['cancelled', 'returned'])->count(),
            'profit_margin' => $salesTotal === 0 ? 0.0 : round(($profitTotal / $salesTotal) * 100, 1),
            'inventory_quantity' => (int) $inventory->sum('quantity_available'),
            'inventory_cost' => (int) $user->products()
                ->where('quantity_available', '>', 0)
                ->get(['purchase_unit_cost', 'quantity_available'])
                ->sum(fn ($product) => $product->purchase_unit_cost * $product->quantity_available),
        ];
    }
}
