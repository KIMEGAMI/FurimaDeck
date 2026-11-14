<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductInventoryEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductInventoryAdjustmentTest extends TestCase
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

    public function test_owner_can_adjust_stock_and_duplicate_submission_is_idempotent(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $this->productFor($user);
        $marketplace = Marketplace::query()->create([
            'code' => 'inventory-adjustment-active-listing',
            'name' => '出品掲載の注意テスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '在庫調整商品',
            'status' => 'active',
        ]);
        $token = (string) Str::uuid();
        $payload = [
            'target_quantity' => 0,
            'reason' => '棚卸しで現物が見つからないため',
            'adjustment_token' => $token,
        ];

        $this->actingAs($user)
            ->from(route('products.edit', $product))
            ->patch(route('products.inventory.adjust', $product), $payload)
            ->assertRedirect(route('products.edit', $product))
            ->assertSessionHas('success', '在庫状態を更新し、入出庫履歴に記録しました。');

        $this->actingAs($user)
            ->from(route('products.edit', $product))
            ->patch(route('products.inventory.adjust', $product), $payload)
            ->assertRedirect(route('products.edit', $product));

        $this->assertSame(0, (int) $product->fresh()->quantity_available);
        $this->assertSame('out_of_stock', $product->fresh()->inventory_status);
        $this->assertSame(1, ProductInventoryEvent::query()
            ->where('product_id', $product->id)
            ->where('event_type', ProductInventoryEvent::TYPE_INVENTORY_ADJUSTMENT)
            ->count());
        $this->assertSame(0, (int) ProductInventoryEvent::query()->where('product_id', $product->id)->sum('quantity_delta'));

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('在庫状態の調整')
            ->assertSee('この調整は外部サイトの掲載を変更しません。')
            ->assertSee(route('products.inventory.adjust', $product), false);
    }

    public function test_product_id_forms_represent_one_physical_item_and_keep_stock_out_of_normal_edit_fields(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $this->productFor($user);

        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('商品IDは現物1点を表すため、仕入数量と初期在庫は各1点です。')
            ->assertDontSee('name="purchase_quantity"', false)
            ->assertDontSee('name="quantity_available"', false)
            ->assertDontSee('name="sale_quantity"', false);

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('商品IDは現物1点を表すため、仕入数量は1点です。')
            ->assertSee('在庫状態の調整')
            ->assertSee('name="target_quantity"', false)
            ->assertDontSee('name="purchase_quantity"', false)
            ->assertDontSee('name="quantity_available"', false)
            ->assertDontSee('name="sale_quantity"', false);
    }

    public function test_inventory_adjustment_cannot_override_a_sale_history(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $this->productFor($user);
        $marketplace = Marketplace::query()->create([
            'code' => 'inventory-adjustment-test',
            'name' => '在庫調整テスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $user->sales()->create([
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

        $this->actingAs($user)
            ->from(route('products.edit', $product))
            ->patch(route('products.inventory.adjust', $product), [
                'target_quantity' => 0,
                'reason' => '販売済み商品の在庫変更を拒否',
                'adjustment_token' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('products.edit', $product))
            ->assertSessionHasErrors('target_quantity');

        $this->assertSame(1, (int) $product->fresh()->quantity_available);
        $this->assertSame(0, ProductInventoryEvent::query()
            ->where('product_id', $product->id)
            ->where('event_type', ProductInventoryEvent::TYPE_INVENTORY_ADJUSTMENT)
            ->count());
    }

    public function test_product_detail_shows_encrypted_purchase_change_history(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $oldSupplier = $user->suppliers()->create(['name' => '履歴テスト旧仕入先', 'type' => 'online_shop']);
        $newSupplier = $user->suppliers()->create(['name' => '履歴テスト新仕入先', 'type' => 'store']);
        $product = $this->productFor($user);
        $product->update([
            'purchase_date' => '2026-10-01',
            'purchase_unit_cost' => 1000,
            'supplier_id' => $oldSupplier->id,
        ]);

        $this->actingAs($user)
            ->put(route('products.update', $product), [
                'internal_sku' => $product->internal_sku,
                'product_name' => $product->product_name,
                'condition' => $product->condition,
                'purchase_date' => '2026-10-15',
                'purchase_unit_cost' => 1400,
                'purchase_shipping_cost' => 120,
                'other_purchase_expense' => 30,
                'supplier_id' => $newSupplier->id,
            ])
            ->assertRedirect(route('products.index'));

        $event = ProductInventoryEvent::query()
            ->where('product_id', $product->id)
            ->where('event_type', ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('履歴テスト旧仕入先', $event->before_state['supplier_name']);
        $this->assertSame('履歴テスト新仕入先', $event->after_state['supplier_name']);

        $stored = DB::connection('furimadeck')->table('product_inventory_events')->where('id', $event->id)->value('before_state');
        $this->assertStringNotContainsString('履歴テスト旧仕入先', (string) $stored);

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('仕入・在庫履歴')
            ->assertSee('1,000円')
            ->assertSee('1,400円')
            ->assertSee('履歴テスト旧仕入先')
            ->assertSee('履歴テスト新仕入先');
    }

    public function test_user_cannot_adjust_another_users_product(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $product = $this->productFor($owner);

        $this->actingAs($other)
            ->patch(route('products.inventory.adjust', $product), [
                'target_quantity' => 0,
                'reason' => '所有者境界の確認',
                'adjustment_token' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        $this->assertSame(1, (int) $product->fresh()->quantity_available);
    }

    private function productFor(User $user): Product
    {
        return $user->products()->create([
            'internal_sku' => 'INV-'.Str::uuid(),
            'product_name' => '在庫調整テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
    }
}
