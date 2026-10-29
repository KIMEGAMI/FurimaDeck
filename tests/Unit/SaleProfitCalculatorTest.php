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

    #[Test]
    public function it_accepts_the_signed_storage_boundaries_without_rounding(): void
    {
        $calculator = app(SaleProfitCalculator::class);

        $this->assertSame(SaleProfitCalculator::MAX_SIGNED_PROFIT, $calculator->calculate([
            'sold_price' => SaleProfitCalculator::MAX_SIGNED_PROFIT,
        ]));
        $this->assertSame(SaleProfitCalculator::MIN_SIGNED_PROFIT, $calculator->calculate([
            'sold_price' => 0,
            'cost_basis' => abs(SaleProfitCalculator::MIN_SIGNED_PROFIT),
        ]));
    }

    #[Test]
    public function it_rejects_profit_outside_the_database_range(): void
    {
        $calculator = app(SaleProfitCalculator::class);
        foreach ([
            ['sold_price' => SaleProfitCalculator::MAX_SIGNED_PROFIT + 1],
            ['sold_price' => 0, 'cost_basis' => abs(SaleProfitCalculator::MIN_SIGNED_PROFIT) + 1],
            ['sold_price' => SaleProfitCalculator::MAX_UNSIGNED_AMOUNT + 1],
        ] as $amounts) {
            try {
                $calculator->calculate($amounts);
                $this->fail('The amount must be rejected before reaching the database.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_rejects_other_sale_costs_above_the_accounting_column_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('販売時の追加費用合計が会計データの保存上限');

        app(SaleProfitCalculator::class)->calculate([
            'sold_price' => SaleProfitCalculator::MAX_UNSIGNED_AMOUNT,
            'purchase_shipping_cost' => 2_500_000_000,
            'packing_cost' => 2_500_000_000,
        ]);
    }
}
