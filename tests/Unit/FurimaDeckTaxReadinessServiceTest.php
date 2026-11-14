<?php

namespace Tests\Unit;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\FurimaDeckTaxReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckTaxReadinessServiceTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_report_period_uses_inclusive_end_date_and_excludes_the_next_day(): void
    {
        $user = User::factory()->create();
        $marketplace = Marketplace::query()->create(['code' => 'period-test', 'name' => '期間テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $product = $user->products()->create(['internal_sku' => 'PERIOD-001', 'product_name' => '期間商品', 'condition' => 'used', 'purchase_unit_cost' => 0, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        foreach (['2026-06-15 12:00:00' => 3000, '2026-06-30 12:00:00' => 4000, '2026-07-01 00:00:00' => 5000] as $soldAt => $amount) {
            $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => $amount, 'sold_at' => $soldAt, 'status' => 'completed', 'cost_basis' => 0, 'sales_fee' => 0, 'shipping_fee' => 0, 'net_profit' => $amount]);
        }

        $report = app(FurimaDeckTaxReadinessService::class)->reportPeriod($user, CarbonImmutable::parse('2026-06-01', config('app.timezone')), CarbonImmutable::parse('2026-07-01', config('app.timezone')));

        $this->assertSame(2, $report['sales_count']);
        $this->assertSame(7000, $report['sales_amount']);
    }

    public function test_annual_report_includes_both_year_boundaries_and_excludes_adjacent_years(): void
    {
        $user = User::factory()->create();
        $marketplace = Marketplace::query()->create([
            'code' => 'annual-boundary-test',
            'name' => '年次境界テスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'ANNUAL-001',
            'product_name' => '年次境界商品',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        foreach ([
            '2025-12-31 23:59:59' => 10000,
            '2026-01-01 00:00:00' => 20000,
            '2026-06-15 12:00:00' => 30000,
            '2026-12-31 23:59:59' => 40000,
            '2027-01-01 00:00:00' => 50000,
        ] as $soldAt => $amount) {
            $user->sales()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => 1,
                'sold_price' => $amount,
                'sold_at' => $soldAt,
                'status' => 'completed',
                'cost_basis' => 0,
                'sales_fee' => 0,
                'shipping_fee' => 0,
                'net_profit' => $amount,
            ]);
        }

        $report = app(FurimaDeckTaxReadinessService::class)->report($user, 2026);

        $this->assertSame(3, $report['sales_count']);
        $this->assertSame(90000, $report['sales_amount']);
        $this->assertSame(2026, $report['year']);
    }

    public function test_annual_report_applies_japan_timezone_to_utc_boundary_timestamps(): void
    {
        config()->set('app.timezone', 'Asia/Tokyo');

        $user = User::factory()->create();
        $marketplace = Marketplace::query()->create([
            'code' => 'timezone-boundary-test',
            'name' => 'タイムゾーン境界テスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'TIMEZONE-001',
            'product_name' => 'タイムゾーン境界商品',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);

        foreach ([
            [CarbonImmutable::parse('2025-12-31 15:00:00', 'UTC'), 1000],
            [CarbonImmutable::parse('2026-12-31 15:00:00', 'UTC'), 2000],
        ] as [$soldAt, $amount]) {
            $user->sales()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => 1,
                'sold_price' => $amount,
                'sold_at' => $soldAt,
                'status' => 'completed',
                'cost_basis' => 0,
                'sales_fee' => 0,
                'shipping_fee' => 0,
                'net_profit' => $amount,
            ]);
        }

        $report = app(FurimaDeckTaxReadinessService::class)->report($user, 2026);

        $this->assertSame(1, $report['sales_count']);
        $this->assertSame(1000, $report['sales_amount']);
    }

    public function test_report_distinguishes_zero_amounts_from_null_fields_and_excludes_returns(): void
    {
        $user = User::factory()->create();
        $marketplace = Marketplace::query()->create(['code' => 'tax-test', 'name' => '税務テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $supplier = $user->suppliers()->create(['user_id' => $user->id, 'name' => '仕入先', 'type' => 'store']);
        $complete = $user->products()->create(['internal_sku' => 'TAX-001', 'product_name' => '完全データ', 'condition' => 'used', 'purchase_date' => '2026-01-01', 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 0, 'supplier_id' => $supplier->id, 'inventory_status' => 'out_of_stock']);
        $missing = $user->products()->create(['internal_sku' => 'TAX-002', 'product_name' => '不足データ', 'condition' => 'used', 'purchase_unit_cost' => 0, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        $fields = ['marketplace_id' => $marketplace->id, 'quantity' => 1, 'sold_price' => 1000, 'status' => 'completed', 'sold_at' => '2026-06-01 12:00:00', 'cost_basis' => 0, 'sales_fee' => 0, 'shipping_fee' => 0, 'purchase_shipping_cost' => 0, 'packing_cost' => 0, 'repair_cost' => 0, 'cleaning_cost' => 0, 'other_expense' => 0, 'net_profit' => 1000];
        $user->sales()->create($fields + ['product_id' => $complete->id, 'internal_sku_snapshot' => $complete->internal_sku, 'product_name_snapshot' => $complete->product_name]);
        $user->sales()->create(array_merge($fields, ['product_id' => $missing->id, 'internal_sku_snapshot' => $missing->internal_sku, 'product_name_snapshot' => $missing->product_name, 'sold_price' => 0, 'status' => 'completed']));
        $user->sales()->create(array_merge($fields, ['product_id' => $complete->id, 'internal_sku_snapshot' => $complete->internal_sku, 'product_name_snapshot' => $complete->product_name, 'status' => 'returned']));

        $report = app(FurimaDeckTaxReadinessService::class)->report($user, 2026);

        $this->assertSame(2, $report['sales_count']);
        $this->assertSame(1000, $report['sales_amount']);
        $this->assertSame(1, $report['checks']['purchase_date']['incomplete']);
        $this->assertSame(1, $report['checks']['supplier']['incomplete']);
        $this->assertSame(0, $report['checks']['sales_fee']['incomplete']);
        $this->assertCount(2, $report['source_review_items']);
        $missingItem = $report['source_review_items']->firstWhere('management_id', 'TAX-002');
        $this->assertContains('仕入日', $missingItem['missing']);
        $this->assertContains('仕入先', $missingItem['missing']);
        $this->assertContains('販売手数料', $missingItem['zero']);
        $this->assertNotContains('販売手数料', $missingItem['missing']);
        $this->assertSame('not_available', $report['accounting_sync']['status']);

        $sale = $user->sales()->where('product_id', $complete->id)->firstOrFail();
        AccountingConnection::query()->create(['user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'encrypted-token', 'status' => 'connected']);
        AccountingEntry::query()->create([
            'user_id' => $user->id,
            'sale_id' => $sale->id,
            'transaction_date' => '2026-06-01',
            'management_id' => $complete->internal_sku,
            'title' => $complete->product_name,
            'sale_amount' => 1000,
            'furimadeck_actual_profit' => 1000,
            'source_status' => 'confirmed',
            'sync_status' => 'not_synced',
        ]);
        $connectedReport = app(FurimaDeckTaxReadinessService::class)->report($user, 2026);
        $this->assertSame('review', $connectedReport['accounting_sync']['status']);
    }

    public function test_pending_sale_is_not_counted_as_a_completed_sale(): void
    {
        $user = User::factory()->create();
        $marketplace = Marketplace::query()->create([
            'code' => 'pending-test', 'name' => '未確定テスト',
            'base_url' => 'https://example.test', 'fee_type' => 'percentage',
            'default_fee_rate' => 0, 'is_active' => true,
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'PENDING-001', 'product_name' => '未確定の販売',
            'condition' => 'used', 'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1, 'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1, 'sold_price' => 5000,
            'sold_at' => '2026-06-01 12:00:00', 'status' => 'pending',
            'cost_basis' => 1000, 'sales_fee' => 500,
            'shipping_fee' => 0, 'net_profit' => 3500,
        ]);

        $report = app(FurimaDeckTaxReadinessService::class)->report($user, 2026);

        $this->assertSame(0, $report['sales_count']);
        $this->assertSame(0, $report['sales_amount']);
    }

    public function test_source_review_does_not_read_a_product_owned_by_another_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $product = $otherUser->products()->create([
            'internal_sku' => 'OTHER-001', 'product_name' => '他人の商品',
            'condition' => 'used', 'purchase_date' => '2026-01-01',
            'purchase_unit_cost' => 1000, 'purchase_quantity' => 1,
            'quantity_available' => 0, 'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'cross-user-test', 'name' => '所有者テスト',
            'base_url' => 'https://example.test', 'fee_type' => 'percentage',
            'default_fee_rate' => 0, 'is_active' => true,
        ]);
        $user->sales()->create([
            'product_id' => $product->id, 'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => 'OWN-SALE', 'product_name_snapshot' => '本人の売上',
            'quantity' => 1, 'sold_price' => 2000,
            'sold_at' => '2026-06-01', 'status' => 'completed',
            'cost_basis' => 1000, 'net_profit' => 1000,
        ]);

        $item = app(FurimaDeckTaxReadinessService::class)->report($user, 2026)['source_review_items']->first();

        $this->assertNull($item['product_id']);
        $this->assertContains('仕入日', $item['missing']);
        $this->assertContains('仕入先', $item['missing']);
    }
}
