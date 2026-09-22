<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FurimaDeckAccountingEntryService
{
    private const EXCLUDED_STATUSES = ['cancelled', 'returned'];

    /** @return Collection<int, AccountingEntry> */
    public function syncPeriod(User $user, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::transaction(function () use ($user, $from, $to): Collection {
            $sales = $user->sales()->with(['marketplace'])->whereNotIn('status', self::EXCLUDED_STATUSES)
                ->where('sold_at', '>=', $from)->where('sold_at', '<', $to)->get();

            return $sales->map(fn (Sale $sale): AccountingEntry => AccountingEntry::query()->updateOrCreate(
                ['user_id' => $user->id, 'sale_id' => $sale->id],
                $this->attributes($user, $sale),
            ));
        });
    }

    /** @return array<string, mixed> */
    private function attributes(User $user, Sale $sale): array
    {
        return [
            'transaction_date' => $sale->sold_at->toDateString(),
            'management_id' => $sale->internal_sku_snapshot,
            'title' => $sale->product_name_snapshot,
            'marketplace' => $sale->marketplace?->name,
            'sale_amount' => $sale->sold_price,
            'purchase_cost' => $sale->cost_basis,
            'selling_fee' => $sale->sales_fee,
            'shipping_cost' => $sale->shipping_fee,
            'other_cost' => $sale->purchase_shipping_cost + $sale->packing_cost + $sale->repair_cost + $sale->cleaning_cost + $sale->other_expense,
            'furimadeck_actual_profit' => $sale->net_profit,
            'note' => 'FurimaDeck Sale #'.$sale->id,
            'source_status' => 'ready',
            'sync_status' => 'not_synced',
        ];
    }
}
