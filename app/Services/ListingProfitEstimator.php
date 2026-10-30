<?php

namespace App\Services;

use App\Models\Product;
use InvalidArgumentException;
use RuntimeException;

class ListingProfitEstimator
{
    /**
     * @return array{expected_profit: int, expected_margin: float}
     */
    public function estimate(Product $product, int $quantity, int $listingPrice, string $feeRate, int $shippingFee): array
    {
        $maximumAmount = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;
        if ($quantity < 1 || $listingPrice < 0 || $listingPrice > $maximumAmount
            || $shippingFee < 0 || $shippingFee > $maximumAmount) {
            throw new InvalidArgumentException('Listing estimate values must be non-negative integers and quantity must be positive.');
        }

        $feeBasisPoints = $this->feeRateBasisPoints($feeRate);
        $unitCost = (int) $product->purchase_unit_cost;
        if ($unitCost > intdiv($maximumAmount, $quantity)) {
            throw new RuntimeException('見込み原価が会計データの保存可能範囲を超えています。商品情報を確認してください。');
        }
        $costBasis = $unitCost * $quantity;
        if ($product->purchase_accounting_enabled) {
            if ((int) $product->purchase_quantity !== 1 || $quantity !== 1) {
                throw new InvalidArgumentException('仕入会計対象の商品は1点単位の原価確認が必要です。');
            }
            $additionalCosts = (int) $product->purchase_shipping_cost + (int) $product->other_purchase_expense;
            if ($additionalCosts > $maximumAmount - $costBasis) {
                throw new RuntimeException('見込み原価が会計データの保存可能範囲を超えています。商品情報を確認してください。');
            }
            $costBasis += $additionalCosts;
        }
        $salesFee = intdiv($listingPrice * $feeBasisPoints, 10000);
        try {
            $profit = app(SaleProfitCalculator::class)->calculate([
                'sold_price' => $listingPrice,
                'cost_basis' => $costBasis,
                'sales_fee' => $salesFee,
                'shipping_fee' => $shippingFee,
            ]);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('見込み利益がデータベースの保存可能範囲を超えています。価格・原価・手数料・送料を確認してください。');
        }

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
