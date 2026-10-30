<?php

namespace App\Services;

use InvalidArgumentException;

class SaleProfitCalculator
{
    public const MAX_UNSIGNED_AMOUNT = 4_294_967_295;

    public const MAX_SIGNED_PROFIT = 2_147_483_647;

    public const MIN_SIGNED_PROFIT = -2_147_483_648;

    /**
     * @param  array<string, int>  $amounts
     */
    public function calculate(array $amounts): int
    {
        $soldPrice = $this->amount($amounts, 'sold_price');
        $purchaseShippingCost = $this->amount($amounts, 'purchase_shipping_cost');
        $packingCost = $this->amount($amounts, 'packing_cost');
        $repairCost = $this->amount($amounts, 'repair_cost');
        $cleaningCost = $this->amount($amounts, 'cleaning_cost');
        $otherExpense = $this->amount($amounts, 'other_expense');
        $accountingOtherCost = array_sum([
            $purchaseShippingCost,
            $packingCost,
            $repairCost,
            $cleaningCost,
            $otherExpense,
        ]);

        if ($accountingOtherCost > self::MAX_UNSIGNED_AMOUNT) {
            throw new InvalidArgumentException(
                '販売時の追加費用合計が会計データの保存上限（'.number_format(self::MAX_UNSIGNED_AMOUNT).'円）を超えています。費用の金額を確認してください。',
            );
        }

        $profit = $soldPrice - array_sum([
            $this->amount($amounts, 'cost_basis'),
            $this->amount($amounts, 'sales_fee'),
            $this->amount($amounts, 'shipping_fee'),
            $accountingOtherCost,
        ]);

        if ($profit < self::MIN_SIGNED_PROFIT || $profit > self::MAX_SIGNED_PROFIT) {
            throw new InvalidArgumentException('利益が保存可能な範囲を超えています。金額を確認してください。');
        }

        return $profit;
    }

    /**
     * @param  array<string, int>  $amounts
     */
    private function amount(array $amounts, string $key): int
    {
        $value = $amounts[$key] ?? 0;

        if (! is_int($value) || $value < 0 || $value > self::MAX_UNSIGNED_AMOUNT) {
            throw new InvalidArgumentException($key.' must be a non-negative integer.');
        }

        return $value;
    }
}
