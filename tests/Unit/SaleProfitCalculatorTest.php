<?php

namespace Tests\Unit;

use App\Services\SaleProfitCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SaleProfitCalculatorTest extends TestCase
{
    #[Test]
    public function it_calculates_net_profit_from_all_sale_costs(): void
    {
        $profit = app(SaleProfitCalculator::class)->calculate([
            'sold_price' => 10000,
            'cost_basis' => 3000,
            'sales_fee' => 1000,
            'shipping_fee' => 750,
            'purchase_shipping_cost' => 500,
            'packing_cost' => 200,
            'repair_cost' => 100,
            'cleaning_cost' => 50,
            'other_expense' => 20,
        ]);

        $this->assertSame(4380, $profit);
    }

    #[Test]
    public function it_preserves_a_loss_as_a_negative_profit(): void
    {
        $profit = app(SaleProfitCalculator::class)->calculate([
            'sold_price' => 1000,
            'cost_basis' => 1200,
        ]);

        $this->assertSame(-200, $profit);
    }

    #[Test]
    public function it_rejects_negative_or_non_integer_monetary_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SaleProfitCalculator::class)->calculate([
            'sold_price' => 1000,
            'sales_fee' => -1,
        ]);
    }
}
