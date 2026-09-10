<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\User;
use App\Services\SaleLifecycleService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SaleReturnLifecycleTest extends TestCase
{
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

    public function test_return_with_restock_records_refund_and_restores_inventory(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'RETURN-001',
            'product_name' => '返品テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 2,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'test-marketplace',
            'name' => 'テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 2,
            'sold_price' => 5000,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 2000,
            'net_profit' => 3000,
        ]);

        $returned = app(SaleLifecycleService::class)->returnSale($user, $sale, '購入者都合', 5000, 600, true);

        $this->assertSame('returned', $returned->status);
        $this->assertSame(5000, $returned->refund_amount);
        $this->assertSame(600, $returned->return_shipping_fee);
        $this->assertSame(2, $returned->restocked_quantity);
        $this->assertSame(2, $product->fresh()->quantity_available);
        $this->assertSame('in_stock', $product->fresh()->inventory_status);
    }

    public function test_sale_status_can_only_progress_from_awaiting_shipment_to_completed(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'STATUS-001',
            'product_name' => 'ステータス確認商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'status-marketplace',
            'name' => 'ステータス販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => now(),
            'status' => 'awaiting_shipment',
            'cost_basis' => 1000,
            'net_profit' => 2000,
        ]);

        $shipped = app(SaleLifecycleService::class)->advanceStatus($user, $sale, 'shipped');
        $completed = app(SaleLifecycleService::class)->advanceStatus($user, $shipped, 'completed');

        $this->assertSame('shipped', $shipped->status);
        $this->assertNotNull($shipped->shipped_at);
        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->completed_at);

        $this->expectException(RuntimeException::class);
        app(SaleLifecycleService::class)->advanceStatus($user, $completed, 'shipped');
    }
}
