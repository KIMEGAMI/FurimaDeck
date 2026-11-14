<?php

namespace Tests\Unit;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductCategory;
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
        $this->assertSame('medium', $actions['stagnant_stock'][0]['priority']);
        $this->assertSame(60, $actions['stagnant_stock'][0]['stagnant_days']);
        $this->assertSame('分析テスト', $actions['stagnant_stock'][0]['marketplace']);
        $this->assertSame(5000, $actions['stagnant_stock'][0]['current_price']);
        $this->assertCount(1, $actions['relist']);
        $this->assertSame(60, $actions['relist'][0]['stagnant_days']);
    }

    public function test_stagnant_stock_uses_a_manual_minimum_day_filter_and_defaults_to_thirty_days(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        foreach ([14, 30, 45, 60] as $index => $days) {
            $product = $this->product($user, 'IMPROVE-FILTER-'.($index + 1), '2026-06-01');
            $user->listings()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => $product->product_name,
                'status' => 'active',
                'listed_at' => CarbonImmutable::parse('2026-09-19 12:00:00')->subDays($days),
            ]);
        }
        $today = CarbonImmutable::parse('2026-09-19 12:00:00');
        $service = app(FurimaDeckSalesImprovementService::class);

        $default = $service->actions($user, $today);
        $manual = $service->actions($user, $today, 45);

        $this->assertSame([30, 45, 60], collect($default['stagnant_stock'])->pluck('stagnant_days')->sort()->values()->all());
        $this->assertSame([45, 60], collect($manual['stagnant_stock'])->pluck('stagnant_days')->sort()->values()->all());
    }

    public function test_rebuy_requires_three_valid_sales_and_ignores_cancelled_sales(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $category = ProductCategory::query()->create(['name' => '分析カテゴリ', 'slug' => 'improvement-rebuy-category']);
        foreach ([1000, 1200, 1400] as $index => $price) {
            $soldAt = '2026-09-0'.($index + 1).' 12:00:00';
            $product = $this->product($user, 'IMPROVE-002-'.($index + 1), null);
            $product->update(['category_id' => $category->id]);
            $listing = $user->listings()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'listing_title' => $product->product_name, 'status' => 'sold', 'listed_at' => CarbonImmutable::parse($soldAt)->subDays(5)]);
            $user->sales()->create(['product_id' => $product->id, 'listing_id' => $listing->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => $price, 'sold_at' => $soldAt, 'status' => 'completed', 'net_profit' => 500]);
        }
        $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 9999, 'sold_at' => '2026-09-04 12:00:00', 'status' => 'cancelled', 'net_profit' => 9999]);
        $actions = app(FurimaDeckSalesImprovementService::class)->actions($user, CarbonImmutable::parse('2026-09-19 12:00:00'));
        $this->assertCount(1, $actions['rebuy']);
        $this->assertSame(3, $actions['rebuy'][0]['sample_count']);
        $this->assertSame(1500, $actions['rebuy'][0]['profit']);
        $this->assertSame('カテゴリ', $actions['rebuy'][0]['dimension']);
        $this->assertSame(5.0, $actions['rebuy'][0]['median_sale_days']);
    }

    public function test_price_review_requires_thirty_day_listing_and_five_comparable_sales(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $category = ProductCategory::query()->create(['name' => '価格比較カテゴリ', 'slug' => 'improvement-price-category']);
        $target = $this->product($user, 'PRICE-TARGET', null);
        $target->update(['category_id' => $category->id]);
        $user->listings()->create(['product_id' => $target->id, 'marketplace_id' => $marketplace->id, 'listing_title' => $target->product_name, 'listing_price' => 8000, 'status' => 'active', 'listed_at' => '2026-08-01 12:00:00']);

        foreach ([4000, 5000, 5000, 5000, 6000] as $index => $price) {
            $this->recordComparableSale($user, $marketplace, $category, 'PRICE-COMP-'.($index + 1), $price, 1000, '2026-08-01 12:00:00', '2026-08-11 12:00:00');
        }

        $actions = app(FurimaDeckSalesImprovementService::class)->actions($user, CarbonImmutable::parse('2026-09-19 12:00:00'));

        $this->assertCount(1, $actions['price_review']);
        $candidate = $actions['price_review'][0];
        $this->assertSame(8000, $candidate['current_price']);
        $this->assertSame(5000.0, $candidate['median_sold_price']);
        $this->assertSame(5, $candidate['sample_count']);
        $this->assertSame(4000, $candidate['price_range_min']);
        $this->assertSame(6000, $candidate['price_range_max']);
        $this->assertSame(10.0, $candidate['median_sale_days']);
    }

    public function test_marketplace_change_requires_three_samples_on_both_sites_and_twenty_percent_faster_median(): void
    {
        $user = User::factory()->create();
        $currentMarketplace = $this->marketplace('improvement-current', '現在の出品先');
        $alternativeMarketplace = $this->marketplace('improvement-alternative', '候補の出品先');
        $category = ProductCategory::query()->create(['name' => '出品先比較カテゴリ', 'slug' => 'improvement-market-category']);
        $target = $this->product($user, 'MARKET-TARGET', null);
        $target->update(['category_id' => $category->id]);
        $user->listings()->create(['product_id' => $target->id, 'marketplace_id' => $currentMarketplace->id, 'listing_title' => $target->product_name, 'status' => 'active', 'listed_at' => '2026-06-21 12:00:00']);

        foreach (range(1, 3) as $index) {
            $this->recordComparableSale($user, $currentMarketplace, $category, 'MARKET-CURRENT-'.$index, 5000, 1000, '2026-07-01 12:00:00', '2026-07-31 12:00:00');
            $this->recordComparableSale($user, $alternativeMarketplace, $category, 'MARKET-ALT-'.$index, 5000, 1000, '2026-08-01 12:00:00', '2026-08-11 12:00:00');
        }

        $actions = app(FurimaDeckSalesImprovementService::class)->actions($user, CarbonImmutable::parse('2026-09-19 12:00:00'));

        $this->assertCount(1, $actions['marketplace_change']);
        $candidate = $actions['marketplace_change'][0];
        $this->assertSame('現在の出品先', $candidate['current_marketplace']);
        $this->assertSame('候補の出品先', $candidate['alternative_marketplace']);
        $this->assertSame(3, $candidate['current_sample_count']);
        $this->assertSame(3, $candidate['sample_count']);
        $this->assertSame(30.0, $candidate['current_median_days']);
        $this->assertSame(10.0, $candidate['comparison_median_days']);
    }

    public function test_listing_candidates_are_suppressed_while_sale_is_in_progress(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $product = $this->product($user, 'IMPROVE-IN-PROGRESS', '2026-05-01');
        $listing = $user->listings()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'listing_title' => $product->product_name, 'listing_price' => 9000, 'status' => 'active', 'listed_at' => '2026-06-01 12:00:00']);
        $user->sales()->create(['product_id' => $product->id, 'listing_id' => $listing->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 9000, 'sold_at' => '2026-09-10 12:00:00', 'status' => 'awaiting_shipment', 'net_profit' => 5000]);

        $actions = app(FurimaDeckSalesImprovementService::class)->actions($user, CarbonImmutable::parse('2026-09-19 12:00:00'));

        $this->assertSame([], $actions['stagnant_stock']);
        $this->assertSame([], $actions['price_review']);
        $this->assertSame([], $actions['relist']);
        $this->assertSame([], $actions['marketplace_change']);
    }

    public function test_sold_out_product_with_stale_active_listing_is_not_recommended_for_listing_changes(): void
    {
        $user = User::factory()->create();
        $currentMarketplace = $this->marketplace('improvement-sold-current', '販売後に残った出品先');
        $soldMarketplace = $this->marketplace('improvement-sold-market', '販売先');
        $alternativeMarketplace = $this->marketplace('improvement-sold-alternative', '比較先');
        $category = ProductCategory::query()->create(['name' => '販売済み候補除外', 'slug' => 'improvement-sold-out-category']);
        $product = $this->product($user, 'IMPROVE-SOLD-OUT', '2026-06-01');
        $product->update(['category_id' => $category->id, 'quantity_available' => 0]);
        $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $currentMarketplace->id,
            'listing_title' => $product->product_name,
            'listing_price' => 9000,
            'status' => 'active',
            'listed_at' => '2026-06-01 12:00:00',
        ]);
        $soldListing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $soldMarketplace->id,
            'listing_title' => $product->product_name,
            'status' => 'sold',
            'listed_at' => '2026-06-01 12:00:00',
        ]);
        $user->sales()->create([
            'product_id' => $product->id,
            'listing_id' => $soldListing->id,
            'marketplace_id' => $soldMarketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 9000,
            'sold_at' => '2026-06-11 12:00:00',
            'status' => 'completed',
            'net_profit' => 5000,
        ]);

        foreach (range(1, 3) as $index) {
            $this->recordComparableSale($user, $currentMarketplace, $category, 'SOLD-CURRENT-'.$index, 5000, 1000, '2026-07-01 12:00:00', '2026-07-31 12:00:00');
            $this->recordComparableSale($user, $alternativeMarketplace, $category, 'SOLD-ALT-'.$index, 5000, 1000, '2026-08-01 12:00:00', '2026-08-11 12:00:00');
        }

        $actions = app(FurimaDeckSalesImprovementService::class)->actions($user, CarbonImmutable::parse('2026-09-19 12:00:00'));

        $this->assertSame([], $actions['stagnant_stock']);
        $this->assertSame([], $actions['price_review']);
        $this->assertSame([], $actions['relist']);
        $this->assertSame([], $actions['marketplace_change']);
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

    private function marketplace(string $code = 'improvement-test', string $name = '分析テスト'): Marketplace
    {
        return Marketplace::query()->create(['code' => $code, 'name' => $name, 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
    }

    private function product(User $user, string $sku, ?string $purchaseDate): Product
    {
        return $user->products()->create(['internal_sku' => $sku, 'product_name' => $sku, 'condition' => 'used', 'purchase_date' => $purchaseDate, 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 1, 'inventory_status' => 'listed']);
    }

    private function recordComparableSale(User $user, Marketplace $marketplace, ProductCategory $category, string $sku, int $price, int $profit, string $listedAt, string $soldAt): void
    {
        $product = $this->product($user, $sku, null);
        $product->update(['category_id' => $category->id]);
        $listing = $user->listings()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'listing_title' => $sku, 'status' => 'sold', 'listed_at' => $listedAt]);
        $user->sales()->create(['product_id' => $product->id, 'listing_id' => $listing->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => $price, 'sold_at' => $soldAt, 'status' => 'completed', 'net_profit' => $profit]);
    }
}
