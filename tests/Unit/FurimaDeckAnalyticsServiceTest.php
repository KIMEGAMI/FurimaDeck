<?php

namespace Tests\Unit;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use App\Services\FurimaDeckAnalyticsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckAnalyticsServiceTest extends TestCase
{
    private const INCLUDED_SALE_PRICE = 10000;

    private const INCLUDED_SALE_PROFIT = 3333;

    private const EXCLUDED_SALE_PRICE = 9000;

    private const EXCLUDED_SALE_PROFIT = 9000;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');

        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_summary_is_scoped_to_the_user_and_excludes_cancelled_and_returned_sales(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $marketplace = Marketplace::query()->create([
            'code' => 'analytics-marketplace',
            'name' => '集計テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $product = $this->createProduct($user, 'ANALYTICS-001', 3, 1200);
        $this->createSale($user, $product, $marketplace, 'completed', self::INCLUDED_SALE_PRICE, self::INCLUDED_SALE_PROFIT);
        $this->createSale($user, $product, $marketplace, 'cancelled', self::EXCLUDED_SALE_PRICE, self::EXCLUDED_SALE_PROFIT);
        $this->createSale($user, $product, $marketplace, 'returned', self::EXCLUDED_SALE_PRICE, self::EXCLUDED_SALE_PROFIT);

        $otherProduct = $this->createProduct($otherUser, 'ANALYTICS-OTHER-001', 7, 5000);
        $this->createSale($otherUser, $otherProduct, $marketplace, 'completed', self::EXCLUDED_SALE_PRICE, self::EXCLUDED_SALE_PROFIT);

        $summary = app(FurimaDeckAnalyticsService::class)->summary($user);

        $this->assertSame(self::INCLUDED_SALE_PRICE, $summary['sales_total']);
        $this->assertSame(self::INCLUDED_SALE_PROFIT, $summary['profit_total']);
        $this->assertSame(1, $summary['sale_count']);
        $this->assertSame(33.3, $summary['profit_margin']);
        $this->assertSame(3, $summary['inventory_quantity']);
        $this->assertSame(3600, $summary['inventory_cost']);
    }

    private function createProduct(User $user, string $sku, int $quantityAvailable, int $unitCost): Product
    {
        return $user->products()->create([
            'internal_sku' => $sku,
            'product_name' => $sku,
            'condition' => 'used',
            'purchase_unit_cost' => $unitCost,
            'purchase_quantity' => $quantityAvailable,
            'quantity_available' => $quantityAvailable,
            'inventory_status' => 'in_stock',
        ]);
    }

    private function createSale(User $user, Product $product, Marketplace $marketplace, string $status, int $soldPrice, int $netProfit): void
    {
        $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => $soldPrice,
            'sold_at' => now(),
            'status' => $status,
            'cost_basis' => 0,
            'net_profit' => $netProfit,
        ]);
    }
}
