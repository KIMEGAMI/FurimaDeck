<?php

namespace Tests\Unit;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use App\Services\FurimaDeckAdvancedAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckAdvancedAnalyticsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-19 12:00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_sale_days_uses_one_day_for_same_day_sales_and_excludes_missing_dates(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $first = $this->product($user, 'ADV-001', 1000, '2026-09-01');
        $second = $this->product($user, 'ADV-002', 1000, null);
        $listing = $user->listings()->create(['product_id' => $first->id, 'marketplace_id' => $marketplace->id, 'listing_title' => '同日', 'status' => 'active', 'listed_at' => '2026-09-10 10:00:00']);
        $user->sales()->create(['product_id' => $first->id, 'listing_id' => $listing->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $first->internal_sku, 'product_name_snapshot' => $first->product_name, 'quantity' => 1, 'sold_price' => 2999, 'sold_at' => '2026-09-10 18:00:00', 'status' => 'completed', 'net_profit' => 999]);
        $user->sales()->create(['product_id' => $second->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $second->internal_sku, 'product_name_snapshot' => $second->product_name, 'quantity' => 1, 'sold_price' => 3000, 'sold_at' => '2026-09-11 18:00:00', 'status' => 'completed', 'net_profit' => 1000]);

        $report = app(FurimaDeckAdvancedAnalyticsService::class)->report($user, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(1, $report['sale_days']['minimum']);
        $this->assertSame(1, $report['sale_days']['maximum']);
        $this->assertSame(1, $report['sale_days']['included_count']);
        $this->assertSame(1, $report['sale_days']['excluded_count']);
        $this->assertSame(999.0, $report['profit_velocity']['median']);
        $this->assertSame(2, $report['sales']['sale_count']);
        $this->assertSame(1, $report['price_bands'][0]['sale_count']);
        $this->assertSame(1, $report['price_bands'][1]['sale_count']);
    }

    public function test_profit_matrix_requires_three_comparable_products_and_classifies_by_user_medians(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        foreach ([1000, 2000, 3000] as $index => $profit) {
            $product = $this->product($user, 'MATRIX-'.($index + 1), 100, '2026-09-01');
            $listing = $user->listings()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => $product->product_name,
                'status' => 'active',
                'listed_at' => '2026-09-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).' 12:00:00',
            ]);
            $user->sales()->create([
                'product_id' => $product->id,
                'listing_id' => $listing->id,
                'marketplace_id' => $marketplace->id,
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => 1,
                'sold_price' => $profit * 2,
                'sold_at' => '2026-09-19 12:00:00',
                'status' => 'completed',
                'net_profit' => $profit,
            ]);
        }

        $report = app(FurimaDeckAdvancedAnalyticsService::class)->report(
            $user,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
        );

        $this->assertTrue($report['matrix']['available']);
        $this->assertCount(3, $report['matrix']['rows']);
        $this->assertNotNull($report['matrix']['margin_median']);
        $this->assertNotNull($report['matrix']['velocity_median']);
        $this->assertContains($report['matrix']['rows'][0]['quadrant'], ['高利益率・高速回転', '高利益率・低速回転', '低利益率・高速回転', '低利益率・低速回転']);
    }

    public function test_inventory_capital_uses_purchase_date_and_includes_exact_boundaries(): void
    {
        $user = User::factory()->create();
        $this->product($user, 'INV-030', 1000, '2026-08-20');
        $this->product($user, 'INV-060', 2000, '2026-07-21');
        $this->product($user, 'INV-090', 3000, '2026-06-21');
        $this->product($user, 'INV-UNSET', 4000, null);
        $this->product($user, 'INV-FUTURE', 500, '2026-10-01');

        $inventory = app(FurimaDeckAdvancedAnalyticsService::class)->report($user, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-01'))['inventory'];

        $this->assertSame(10500, $inventory['capital']);
        $this->assertSame(6000, $inventory['over_30_days']);
        $this->assertSame(5000, $inventory['over_60_days']);
        $this->assertSame(3000, $inventory['over_90_days']);
        $this->assertSame(4000, $inventory['purchase_date_unset']);
    }

    public function test_sale_before_listing_is_excluded_from_sale_days_and_velocity(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $product = $this->product($user, 'ADV-INVALID-DATES', 1000, '2026-09-01');
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '日時不整合',
            'status' => 'active',
            'listed_at' => '2026-09-10 10:00:00',
        ]);
        $user->sales()->create([
            'product_id' => $product->id,
            'listing_id' => $listing->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => '2026-09-09 18:00:00',
            'status' => 'completed',
            'net_profit' => 1000,
        ]);

        $report = app(FurimaDeckAdvancedAnalyticsService::class)->report(
            $user,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
        );

        $this->assertSame(1, $report['sales']['sale_count']);
        $this->assertSame(0, $report['sale_days']['included_count']);
        $this->assertSame(1, $report['sale_days']['excluded_count']);
        $this->assertNull($report['sale_days']['average']);
        $this->assertSame(0, $report['profit_velocity']['included_count']);
    }

    private function marketplace(): Marketplace
    {
        return Marketplace::query()->create(['code' => 'advanced-test', 'name' => '分析テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
    }

    private function product(User $user, string $sku, int $cost, ?string $purchaseDate): Product
    {
        return $user->products()->create(['internal_sku' => $sku, 'product_name' => $sku, 'condition' => 'used', 'purchase_date' => $purchaseDate, 'purchase_unit_cost' => $cost, 'purchase_quantity' => 1, 'quantity_available' => 1, 'inventory_status' => 'in_stock']);
    }
}
