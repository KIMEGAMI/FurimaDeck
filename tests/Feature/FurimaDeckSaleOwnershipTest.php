<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckSaleOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
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

    public function test_another_user_cannot_read_or_change_a_sale(): void
    {
        $owner = $this->premiumUser();
        $otherUser = $this->premiumUser();
        $product = $owner->products()->create([
            'internal_sku' => 'SALE-OWNER-'.uniqid(),
            'product_name' => '所有者の販売商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => Product::UNITS_PER_PRODUCT_ID,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-owner-'.uniqid(),
            'name' => '所有者分離テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $sale = $owner->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => Product::UNITS_PER_PRODUCT_ID,
            'sold_price' => 3000,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 2000,
        ]);

        $this->actingAs($otherUser)
            ->get(route('furimadeck-sales.edit', $sale))
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->put(route('furimadeck-sales.update', $sale), [])
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->post(route('furimadeck-sales.cancel', $sale), ['reason' => '不正な取消'])
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->patch(route('furimadeck-sales.status', $sale), ['status' => 'shipped'])
            ->assertNotFound();
        $this->actingAs($otherUser)
            ->post(route('furimadeck-sales.return', $sale), [
                'reason' => '不正な返品',
                'refund_amount' => 3000,
                'return_shipping_fee' => 0,
                'restock' => true,
            ])
            ->assertNotFound();

        $this->assertSame($owner->id, $sale->fresh()->user_id);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame(3000, (int) $sale->fresh()->sold_price);
        $this->assertSame(0, (int) $product->fresh()->quantity_available);
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
    }
}
