<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckOwnershipTest extends TestCase
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
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_user_cannot_open_another_users_product_or_listing_edit_screen(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $visitor = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $owner->products()->create(['internal_sku' => 'OWNER-001', 'product_name' => '所有者商品', 'condition' => 'used', 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 1, 'inventory_status' => 'in_stock']);
        $marketplace = Marketplace::query()->create(['code' => 'ownership-test', 'name' => '所有権テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $listing = $owner->listings()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'listing_title' => '所有者出品', 'status' => 'ready']);

        $this->actingAs($visitor)->get(route('products.edit', $product))->assertNotFound();
        $this->actingAs($visitor)->get(route('listings.edit', $listing))->assertNotFound();
    }

    public function test_sales_index_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $visitor = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $owner->products()->create(['internal_sku' => 'OWNER-SALE-001', 'product_name' => '他ユーザーの売上商品', 'condition' => 'used', 'purchase_unit_cost' => 1000, 'purchase_quantity' => 1, 'quantity_available' => 0, 'inventory_status' => 'out_of_stock']);
        $marketplace = Marketplace::query()->create(['code' => 'ownership-sale-test', 'name' => '売上所有権テスト', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $owner->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 3000, 'sold_at' => now(), 'status' => 'completed', 'cost_basis' => 1000, 'net_profit' => 2000]);

        $this->actingAs($visitor)->get(route('furimadeck-sales.index'))->assertOk()->assertDontSee('他ユーザーの売上商品', false);
    }
}
