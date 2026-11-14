<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class FurimaDeckAccountingAdjustmentService
{
    public function provisionalCancellationCount(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive): int
    {
        return $user->sales()->where('status', 'cancelled')->where('cancelled_from_pending', true)
            ->where(function ($query) use ($from, $toExclusive): void {
                $query->where(fn ($dated) => $dated->where('cancelled_at', '>=', $from)->where('cancelled_at', '<', $toExclusive))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('cancelled_at')->where('sold_at', '>=', $from)->where('sold_at', '<', $toExclusive));
            })->count();
    }

    /** @return Collection<int, Sale> */
    public function forPeriod(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive): Collection
    {
        return $this->queryForPeriod($user, $from, $toExclusive)->get();
    }

    /** @return LazyCollection<int, Sale> */
    public function lazyForPeriod(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive): LazyCollection
    {
        return $this->queryForPeriod($user, $from, $toExclusive)->lazy((int) config('furimadeck.accounting.query_chunk_size'));
    }

    public function queryForPeriod(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive): HasMany
    {
        return $user->sales()->with(['marketplace', 'accountingEntry.providerSyncs'])
            ->where(function ($query) use ($from, $toExclusive): void {
                $query->where(function ($cancelled) use ($from, $toExclusive): void {
                    $cancelled->where('status', 'cancelled')->where('cancelled_from_pending', false)
                        ->where(function ($dates) use ($from, $toExclusive): void {
                            $dates->where(fn ($dated) => $dated->where('cancelled_at', '>=', $from)->where('cancelled_at', '<', $toExclusive))
                                ->orWhere(fn ($legacy) => $legacy->whereNull('cancelled_at')->where('sold_at', '>=', $from)->where('sold_at', '<', $toExclusive));
                        });
                })->orWhere(function ($returned) use ($from, $toExclusive): void {
                    $returned->where('status', 'returned')
                        ->where(function ($dates) use ($from, $toExclusive): void {
                            $dates->where(fn ($dated) => $dated->where('returned_at', '>=', $from)->where('returned_at', '<', $toExclusive))
                                ->orWhere(fn ($legacy) => $legacy->whereNull('returned_at')->where('sold_at', '>=', $from)->where('sold_at', '<', $toExclusive));
                        });
                });
            })
            ->orderBy('id');
    }
}
