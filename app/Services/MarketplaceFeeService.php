<?php

namespace App\Services;

use App\Models\Marketplace;
use Illuminate\Support\Collection;

class MarketplaceFeeService
{
    private const PERCENT_SCALE = 100;

    private const RATE_SCALE = 100;

    public function rate(Marketplace $marketplace): float
    {
        $storedRate = (float) $marketplace->default_fee_rate;

        if ($storedRate > 0) {
            return $storedRate;
        }

        return (float) (config('furimadeck.marketplace_fees.rates.'.$marketplace->code) ?? 0);
    }

    public function amount(int $soldPrice, Marketplace $marketplace): int
    {
        $rateInBasisPoints = (int) round($this->rate($marketplace) * self::RATE_SCALE);

        return intdiv(max(0, $soldPrice) * $rateInBasisPoints, self::PERCENT_SCALE * self::RATE_SCALE);
    }

    /** @param Collection<int, Marketplace> $marketplaces */
    public function rates(Collection $marketplaces): array
    {
        return $marketplaces->mapWithKeys(fn (Marketplace $marketplace): array => [
            (string) $marketplace->id => $this->rate($marketplace),
        ])->all();
    }
}
