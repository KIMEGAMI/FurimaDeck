<?php

namespace App\Services;

use InvalidArgumentException;

class SaleProfitCalculator
{
    /**
     * @param  array<string, int>  $amounts
     */
    public function calculate(array $amounts): int
    {
        $soldPrice = $this->amount($amounts, 'sold_price');

        return $soldPrice - array_sum([
            $this->amount($amounts, 'cost_basis'),
            $this->amount($amounts, 'sales_fee'),
            $this->amount($amounts, 'shipping_fee'),
            $this->amount($amounts, 'purchase_shipping_cost'),
            $this->amount($amounts, 'packing_cost'),
            $this->amount($amounts, 'repair_cost'),
            $this->amount($amounts, 'cleaning_cost'),
            $this->amount($amounts, 'other_expense'),
        ]);
    }

    /**
     * @param  array<string, int>  $amounts
     */
    private function amount(array $amounts, string $key): int
    {
        $value = $amounts[$key] ?? 0;

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException($key.' must be a non-negative integer.');
        }

        return $value;
    }
}
