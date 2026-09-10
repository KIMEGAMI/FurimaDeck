<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ListingProfitEstimator;
use Tests\TestCase;

class ListingProfitEstimatorTest extends TestCase
{
    public function test_estimate_includes_product_cost_marketplace_fee_and_shipping_fee(): void
    {
        $product = new Product(['purchase_unit_cost' => 3000]);

        $estimate = app(ListingProfitEstimator::class)->estimate($product, 2, 10000, '10.00', 750);

        $this->assertSame(2250, $estimate['expected_profit']);
        $this->assertSame(22.5, $estimate['expected_margin']);
    }

    public function test_estimate_truncates_fractional_yen_for_fee_calculation(): void
    {
        $product = new Product(['purchase_unit_cost' => 0]);

        $estimate = app(ListingProfitEstimator::class)->estimate($product, 1, 101, '10.00', 0);

        $this->assertSame(91, $estimate['expected_profit']);
    }
}
