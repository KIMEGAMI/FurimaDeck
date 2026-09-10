<?php

namespace App\Services;

use App\Models\Product;
use InvalidArgumentException;

class ListingProfitEstimator
{
    /**
     * @return array{expected_profit: int, expected_margin: float}
     */
    public function estimate(Product $product, int $quantity, int $listingPrice, string $feeRate, int $shippingFee): array
    {
        if ($quantity < 1 || $listingPrice < 0 || $shippingFee < 0) {
            throw new InvalidArgumentException('Listing estimate values must be non-negative integers and quantity must be positive.');
        }

        $feeBasisPoints = $this->feeRateBasisPoints($feeRate);
        $costBasis = $product->purchase_unit_cost * $quantity;
        $salesFee = intdiv($listingPrice * $feeBasisPoints, 10000);
        $profit = $listingPrice - $costBasis - $salesFee - $shippingFee;

        return [
            'expected_profit' => $profit,
            'expected_margin' => $listingPrice === 0 ? 0.0 : round(($profit / $listingPrice) * 100, 2),
        ];
    }

    private function feeRateBasisPoints(string $feeRate): int
    {
        if (! preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/', $feeRate)) {
            throw new InvalidArgumentException('Expected fee rate must be a percentage with up to two decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $feeRate, 2), 2, '');

        $basisPoints = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        if ($basisPoints > 10000) {
            throw new InvalidArgumentException('Expected fee rate must not exceed 100 percent.');
        }

        return $basisPoints;
    }
}
