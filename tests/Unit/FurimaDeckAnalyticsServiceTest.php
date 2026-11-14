<?php

namespace Tests\Unit;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use App\Services\FurimaDeckAnalyticsService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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
        $soldProduct = $this->createProduct($user, 'ANALYTICS-SOLD-001', 0, 1200);
        $cancelledProduct = $this->createProduct($user, 'ANALYTICS-CANCELLED-001', 1, 1200);
        $returnedProduct = $this->createProduct($user, 'ANALYTICS-RETURNED-001', 1, 1200);
        $this->createSale($user, $soldProduct, $marketplace, 'completed', self::INCLUDED_SALE_PRICE, self::INCLUDED_SALE_PROFIT);
        $this->createSale($user, $cancelledProduct, $marketplace, 'cancelled', self::EXCLUDED_SALE_PRICE, self::EXCLUDED_SALE_PROFIT);
        $this->createSale($user, $returnedProduct, $marketplace, 'returned', self::EXCLUDED_SALE_PRICE, self::EXCLUDED_SALE_PROFIT);

        $this->createProduct($user, 'ANALYTICS-STOCK-001', 1, 1200);
        $otherProduct = $this->createProduct($otherUser, 'ANALYTICS-OTHER-001', 1, 5000);
        $this->createSale($otherUser, $otherProduct, $marketplace, 'completed', self::EXCLUDED_SALE_PRICE, self::EXCLUDED_SALE_PROFIT);

        $summary = app(FurimaDeckAnalyticsService::class)->summary($user);

        $this->assertSame(self::INCLUDED_SALE_PRICE, $summary['sales_total']);
        $this->assertSame(self::INCLUDED_SALE_PROFIT, $summary['profit_total']);
        $this->assertSame(1, $summary['sale_count']);
        $this->assertSame(33.3, $summary['profit_margin']);
        $this->assertSame(3, $summary['inventory_quantity']);
        $this->assertSame(3600, $summary['inventory_cost']);
        $this->assertSame(1, $summary['zero_stock_product_count']);
    }

    public function test_dashboard_monthly_graph_uses_only_valid_sales_for_the_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $marketplace = Marketplace::query()->create([
            'code' => 'dashboard-calendar-marketplace',
            'name' => 'カレンダー販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $octoberProduct = $this->createProduct($user, 'CALENDAR-OCTOBER-001', 0, 2000);
        $septemberProduct = $this->createProduct($user, 'CALENDAR-SEPTEMBER-001', 0, 2000);
        $cancelledProduct = $this->createProduct($user, 'CALENDAR-CANCELLED-001', 1, 2000);
        $this->createSaleAt($user, $octoberProduct, $marketplace, 'completed', 6000, 2000, '2025-10-15 12:00:00');
        $this->createSaleAt($user, $septemberProduct, $marketplace, 'completed', 12000, 5000, '2026-09-10 12:00:00');
        $this->createSaleAt($user, $cancelledProduct, $marketplace, 'cancelled', 9000, 4000, '2026-09-10 14:00:00');
        $otherProduct = $this->createProduct($otherUser, 'CALENDAR-OTHER-001', 0, 2000);
        $this->createSaleAt($otherUser, $otherProduct, $marketplace, 'completed', 8000, 3000, '2026-09-10 16:00:00');

        $dashboard = app(FurimaDeckAnalyticsService::class)->dashboard(
            $user,
            CarbonImmutable::create(2026, 9, 1),
        );
        $september = $dashboard['monthly_stats']->firstWhere('month', 9);
        $october = $dashboard['monthly_stats']->firstWhere('key', '2025-10');
        $this->assertSame('2025-10', $dashboard['monthly_stats']->first()['key']);
        $this->assertSame('2026-09', $dashboard['monthly_stats']->last()['key']);
        $this->assertSame(6000, $october['sales']);
        $this->assertSame(2000, $october['profit']);
        $this->assertSame(12000, $september['sales']);
        $this->assertSame(5000, $september['profit']);
        $this->assertSame(1, $september['count']);
    }

    private function createProduct(User $user, string $sku, int $quantityAvailable, int $unitCost): Product
    {
        return $user->products()->create([
            'internal_sku' => $sku,
            'product_name' => $sku,
            'condition' => 'used',
            'purchase_unit_cost' => $unitCost,
            'purchase_quantity' => Product::UNITS_PER_PRODUCT_ID,
            'quantity_available' => $quantityAvailable,
            'inventory_status' => Product::inventoryStatusForQuantity($quantityAvailable),
        ]);
    }

    private function createSale(User $user, Product $product, Marketplace $marketplace, string $status, int $soldPrice, int $netProfit): void
    {
        $this->createSaleAt($user, $product, $marketplace, $status, $soldPrice, $netProfit, now());
    }

    private function createSaleAt(User $user, Product $product, Marketplace $marketplace, string $status, int $soldPrice, int $netProfit, DateTimeInterface|string $soldAt): void
    {
        $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => $soldPrice,
            'sold_at' => $soldAt,
            'status' => $status,
            'cost_basis' => 0,
            'net_profit' => $netProfit,
        ]);
    }
}
