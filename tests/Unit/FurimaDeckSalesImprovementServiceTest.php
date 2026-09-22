<?php

namespace Tests\Unit;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use App\Services\FurimaDeckSalesImprovementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckSalesImprovementServiceTest extends TestCase
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

    public function test_stagnant_and_relist_candidates_use_their_documented_boundaries(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $stagnant = $this->product($user, 'IMPROVE-001', '2026-06-21');
        $user->listings()->create(['product_id' => $stagnant->id, 'marketplace_id' => $marketplace->id, 'listing_title' => '滞留', 'listing_price' => 5000, 'status' => 'active', 'listed_at' => '2026-07-21 12:00:00']);
        $actions = app(FurimaDeckSalesImprovementService::class)->actions($user, CarbonImmutable::parse('2026-09-19 12:00:00'));
        $this->assertCount(1, $actions['stagnant_stock']);
        $this->assertSame('high', $actions['stagnant_stock'][0]['priority']);
        $this->assertCount(1, $actions['relist']);
        $this->assertSame(60, $actions['relist'][0]['stagnant_days']);
    }

    public function test_rebuy_requires_three_valid_sales_and_ignores_cancelled_sales(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $product = $this->product($user, 'IMPROVE-002', null);
        foreach ([1000, 1200, 1400] as $index => $price) {
            $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => $price, 'sold_at' => '2026-09-0'.($index + 1).' 12:00:00', 'status' => 'completed', 'net_profit' => 500]);
        }
        $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 9999, 'sold_at' => '2026-09-04 12:00:00', 'status' => 'cancelled', 'net_profit' => 9999]);
        $actions = app(FurimaDeckSalesImprovementService::class)->actions($user, CarbonImmutable::parse('2026-09-19 12:00:00'));
        $this->assertCount(1, $actions['rebuy']);
        $this->assertSame(3, $actions['rebuy'][0]['sample_count']);
        $this->assertSame(1500, $actions['rebuy'][0]['profit']);
    }

    public function test_future_inventory_and_listing_dates_are_not_treated_as_stagnant(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $product = $this->product($user, 'IMPROVE-FUTURE', '2026-10-01');
        $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '未来日付',
            'listing_price' => 5000,
            'status' => 'active',
            'listed_at' => '2026-10-01 12:00:00',
        ]);

        $actions = app(FurimaDeckSalesImprovementService::class)->actions($user, CarbonImmutable::parse('2026-09-19 12:00:00'));

        $this->assertSame([], $actions['stagnant_stock']);
        $this->assertSame([], $actions['relist']);
    }

    private function marketplace(): Marketplace
    {
        return Marketplace::query()->create(['code' => 'improvement-test', 'name' => '改善テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
    }

    private function product(User $user, string $sku, ?string $purchaseDate): Product
    {
        return $user->products()->create(['internal_sku' => $sku, 'product_name' => $sku, 'condition' => 'used', 'purchase_date' => $purchaseDate, 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 1, 'inventory_status' => 'listed']);
    }
}
