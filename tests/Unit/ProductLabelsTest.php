<?php

namespace Tests\Unit;

use App\Models\Listing;
use App\Models\Product;
use App\Models\Sale;
use Tests\TestCase;

class ProductLabelsTest extends TestCase
{
    public function test_product_condition_and_inventory_status_have_japanese_labels(): void
    {
        $this->assertSame('未使用に近い', Product::conditionLabel('like_new'));
        $this->assertSame('出品準備完了', Product::inventoryStatusLabel('listing_ready'));
    }

    public function test_unknown_product_values_are_preserved_for_safe_display(): void
    {
        $this->assertSame('future_condition', Product::conditionLabel('future_condition'));
        $this->assertSame('future_status', Product::inventoryStatusLabel('future_status'));
    }

    public function test_listing_and_sale_statuses_have_japanese_labels(): void
    {
        $this->assertSame('出品中', Listing::statusLabel('active'));
        $this->assertSame('返品・返金済み', Sale::statusLabel('returned'));
    }
}
