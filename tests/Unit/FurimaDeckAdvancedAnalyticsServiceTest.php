<?php

namespace Tests\Unit;

use App\Models\CategoryAttribute;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductInventoryEvent;
use App\Models\Sale;
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

    public function test_profit_matrix_includes_genre_brand_and_supplier_dimensions(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        foreach (['衣類', '靴', 'バッグ'] as $index => $genre) {
            $category = ProductCategory::query()->create(['name' => $genre, 'slug' => 'matrix-genre-'.$index]);
            $brandAttribute = CategoryAttribute::query()->create([
                'category_id' => $category->id,
                'key' => 'brand',
                'label' => 'ブランド',
                'input_type' => 'text',
                'is_required' => false,
                'is_active' => true,
            ]);
            $supplier = $user->suppliers()->create(['name' => '仕入先'.($index + 1), 'type' => 'store']);
            $product = $this->product($user, 'MATRIX-DIM-'.$index, 100, '2026-09-01');
            $product->update(['category_id' => $category->id, 'supplier_id' => $supplier->id]);
            $product->attributeValues()->create(['category_attribute_id' => $brandAttribute->id, 'value_text' => 'ブランド'.($index + 1)]);
            $sale = $this->recordSale($user, $marketplace, $product, '2026-09-'.str_pad((string) (10 + $index), 2, '0', STR_PAD_LEFT).' 12:00:00', 1000 * ($index + 1), 300 * ($index + 1));
            if ($index === 0) {
                $historicalSupplier = $user->suppliers()->create(['name' => '販売時の仕入先', 'type' => 'store']);
                ProductInventoryEvent::query()->create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'event_type' => ProductInventoryEvent::TYPE_SALE,
                    'quantity_delta' => -1,
                    'source_type' => 'sale',
                    'source_id' => $sale->id,
                    'idempotency_key' => hash('sha256', 'matrix-supplier-'.$sale->id),
                    'occurred_at' => $sale->sold_at,
                    'before_state' => ['supplier_id' => $historicalSupplier->id, 'supplier_name' => $historicalSupplier->name],
                    'after_state' => ['supplier_id' => $supplier->id, 'supplier_name' => $supplier->name],
                    'actor_user_id' => $user->id,
                    'reason' => 'test fixture',
                ]);
            }
        }

        $matrix = app(FurimaDeckAdvancedAnalyticsService::class)->report(
            $user,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
        )['matrix'];

        foreach (['genre', 'brand', 'supplier'] as $dimension) {
            $this->assertTrue($matrix['dimensions'][$dimension]['available'], $dimension);
            $this->assertCount(3, $matrix['dimensions'][$dimension]['rows'], $dimension);
        }
        $this->assertSame('靴', $matrix['dimensions']['genre']['rows'][1]['product_name']);
        $this->assertContains('ブランド1', collect($matrix['dimensions']['brand']['rows'])->pluck('product_name'));
        $this->assertContains('販売時の仕入先', collect($matrix['dimensions']['supplier']['rows'])->pluck('product_name'));
        $this->assertNotContains('仕入先1', collect($matrix['dimensions']['supplier']['rows'])->pluck('product_name'));
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

    public function test_brand_and_monthly_genre_analysis_include_unset_values_and_reference_label(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $top = ProductCategory::query()->create(['name' => 'ファッション', 'slug' => 'analytics-fashion']);
        $middle = ProductCategory::query()->create(['parent_id' => $top->id, 'name' => 'メンズ', 'slug' => 'analytics-mens']);
        $leaf = ProductCategory::query()->create(['parent_id' => $middle->id, 'name' => '靴', 'slug' => 'analytics-shoes']);
        $brandAttribute = CategoryAttribute::query()->create([
            'category_id' => $leaf->id,
            'key' => 'brand',
            'label' => 'ブランド',
            'input_type' => 'text',
            'is_required' => false,
            'is_active' => true,
        ]);
        $branded = $this->product($user, 'BRAND-001', 1000, '2026-09-01');
        $branded->update(['category_id' => $leaf->id]);
        $branded->attributeValues()->create(['category_attribute_id' => $brandAttribute->id, 'value_text' => 'Sample Brand']);
        $unset = $this->product($user, 'BRAND-002', 500, '2026-09-01');
        $this->recordSale($user, $marketplace, $branded, '2026-09-10 12:00:00', 3000, 1000);
        $this->recordSale($user, $marketplace, $unset, '2026-09-12 12:00:00', 1000, 300);

        $report = app(FurimaDeckAdvancedAnalyticsService::class)->report(
            $user,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
        );

        $brands = collect($report['brand'])->keyBy('name');
        $this->assertSame(3000, $brands['Sample Brand']['sales_amount']);
        $this->assertSame(1000, $brands['Sample Brand']['profit']);
        $this->assertSame(3000, $brands['Sample Brand']['average_sold_price']);
        $this->assertSame(1000, $brands['未設定']['sales_amount']);
        $this->assertTrue($report['seasonality'][0]['is_reference']);
        $this->assertSame(2, $report['seasonality'][0]['sale_count']);
        $this->assertSame(4000, $report['seasonality'][0]['sales_amount']);
        $this->assertSame('ファッション > メンズ > 靴', $report['seasonality'][0]['genres'][0]['name']);
        $this->assertSame(1, $report['seasonality'][0]['genres'][0]['sale_count']);
    }

    public function test_seasonality_is_not_marked_as_reference_when_twelve_sale_months_are_available(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $firstMonth = CarbonImmutable::parse('2025-10-01');
        for ($index = 0; $index < 12; $index++) {
            $month = $firstMonth->addMonths($index);
            $product = $this->product($user, 'SEASON-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 100, '2025-09-01');
            $this->recordSale($user, $marketplace, $product, $month->format('Y-m-15 12:00:00'), 1000, 500);
        }

        $seasonality = app(FurimaDeckAdvancedAnalyticsService::class)->report(
            $user,
            $firstMonth,
            CarbonImmutable::parse('2026-10-01'),
        )['seasonality'];

        $this->assertCount(12, $seasonality);
        $this->assertFalse($seasonality[0]['is_reference']);
        $this->assertFalse($seasonality[11]['is_reference']);
    }

    public function test_supplier_analysis_includes_purchase_count_cost_and_supplier_at_sale_snapshot(): void
    {
        $user = User::factory()->create();
        $marketplace = $this->marketplace();
        $purchaseSupplier = $user->suppliers()->create(['name' => '仕入先A', 'type' => 'store']);
        $currentSupplier = $user->suppliers()->create(['name' => '仕入先B', 'type' => 'online_shop']);
        $product = $this->product($user, 'SUPPLIER-001', 800, '2026-09-01');
        $product->update(['supplier_id' => $currentSupplier->id]);
        $sale = $this->recordSale($user, $marketplace, $product, '2026-09-10 12:00:00', 3000, 500, [
            'cost_basis' => 1200,
            'sales_fee' => 200,
        ]);
        ProductInventoryEvent::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_SALE,
            'quantity_delta' => -1,
            'source_type' => 'sale',
            'source_id' => $sale->id,
            'idempotency_key' => hash('sha256', 'supplier-analytics-'.$sale->id),
            'occurred_at' => $sale->sold_at,
            'before_state' => ['supplier_id' => $purchaseSupplier->id, 'supplier_name' => $purchaseSupplier->name],
            'after_state' => ['supplier_id' => $currentSupplier->id, 'supplier_name' => $currentSupplier->name],
            'actor_user_id' => $user->id,
            'reason' => 'test fixture',
        ]);

        $supplierRows = collect(app(FurimaDeckAdvancedAnalyticsService::class)->report(
            $user,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-10-01'),
        )['supplier'])->keyBy('supplier_id');

        $historical = $supplierRows[$purchaseSupplier->id];
        $this->assertSame('仕入先A', $historical['name']);
        $this->assertSame(3000, $historical['sales_amount']);
        $this->assertSame(1200, $historical['sale_cost']);
        $this->assertSame(500, $historical['profit']);
        $this->assertSame(0, $historical['purchase_count']);

        $current = $supplierRows[$currentSupplier->id];
        $this->assertSame(1, $current['purchase_count']);
        $this->assertSame(800, $current['inventory_capital']);
        $this->assertSame(0, $current['sale_count']);
    }

    /** @param array<string, int> $overrides */
    private function recordSale(User $user, Marketplace $marketplace, Product $product, string $soldAt, int $price, int $profit, array $overrides = []): Sale
    {
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => $product->product_name,
            'status' => 'sold',
            'listed_at' => '2026-09-01 12:00:00',
        ]);

        return $user->sales()->create($overrides + [
            'product_id' => $product->id,
            'listing_id' => $listing->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => $price,
            'sales_fee' => 0,
            'cost_basis' => 0,
            'sold_at' => $soldAt,
            'status' => 'completed',
            'net_profit' => $profit,
        ]);
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
