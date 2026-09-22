<?php

namespace Tests\Unit;

use App\Models\AccountingEntry;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckAccountingEntryServiceTest extends TestCase
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

    public function test_sync_is_user_scoped_idempotent_and_preserves_zero_costs(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $marketplace = Marketplace::query()->create(['code' => 'accounting-test', 'name' => '会計テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $product = $user->products()->create(['internal_sku' => 'ACC-001', 'product_name' => '会計商品', 'condition' => 'used', 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        $otherProduct = $other->products()->create(['internal_sku' => 'ACC-OTHER', 'product_name' => '他人商品', 'condition' => 'used', 'purchase_unit_cost' => 1, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 5000, 'sold_at' => '2026-01-01 12:00:00', 'status' => 'completed', 'cost_basis' => 1000, 'sales_fee' => 0, 'shipping_fee' => 500, 'purchase_shipping_cost' => 0, 'packing_cost' => 0, 'repair_cost' => 0, 'cleaning_cost' => 0, 'other_expense' => 0, 'net_profit' => 3500]);
        $other->sales()->create(['product_id' => $otherProduct->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $otherProduct->internal_sku, 'product_name_snapshot' => $otherProduct->product_name, 'quantity' => 1, 'sold_price' => 9999, 'sold_at' => '2026-01-01 12:00:00', 'status' => 'completed', 'net_profit' => 9999]);

        $service = app(FurimaDeckAccountingEntryService::class);
        $first = $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));
        $second = $service->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(1, AccountingEntry::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, AccountingEntry::query()->where('user_id', $other->id)->count());
        $this->assertSame(3500, $first->first()->furimadeck_actual_profit);
        $this->assertSame(0, $first->first()->other_cost);
    }
}
