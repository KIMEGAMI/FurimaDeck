<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaleCancellationRegressionTest extends TestCase
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
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_owner_can_cancel_a_sale_and_restore_inventory(): void
    {
        [$user, $product, $sale] = $this->saleFixture();

        $response = $this->actingAs($user)->from(route('furimadeck-sales.index'))->post(
            route('furimadeck-sales.cancel', $sale),
            ['reason' => '購入者都合'],
        );

        $response->assertRedirect(route('furimadeck-sales.index'));
        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertSame(1, $product->fresh()->quantity_available);
        $this->assertSame('in_stock', $product->fresh()->inventory_status);
    }

    public function test_other_user_cannot_cancel_a_sale(): void
    {
        [$user, $product, $sale] = $this->saleFixture();
        $otherUser = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);

        $this->actingAs($otherUser)
            ->post(route('furimadeck-sales.cancel', $sale), ['reason' => '不正操作'])
            ->assertNotFound();

        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame(0, $product->fresh()->quantity_available);
    }

    /** @return array{0: User, 1: Product, 2: Sale} */
    private function saleFixture(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create([
            'internal_sku' => 'CANCEL-'.uniqid(),
            'product_name' => '取消回帰テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'cancel-marketplace-'.uniqid(),
            'name' => '取消テスト販売先',
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
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 2000,
        ]);

        return [$user, $product, $sale];
    }
}
