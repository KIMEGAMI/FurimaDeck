<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\ProductInventoryEvent;
use App\Models\User;
use App\Services\ProductInventoryEventService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductInventoryEventMigrationTest extends TestCase
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

    public function test_migration_backfills_a_single_encrypted_opening_event_per_product_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LEDGER-OPENING-001',
            'product_name' => '台帳初期化商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-01',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'purchase_shipping_cost' => 150,
            'other_purchase_expense' => 20,
            'inventory_status' => 'out_of_stock',
        ]);

        $migration = require database_path('migrations/furimadeck/2026_11_06_000100_create_product_inventory_events.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, ProductInventoryEvent::query()->where('product_id', $product->id)->count());
        $event = ProductInventoryEvent::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(ProductInventoryEvent::TYPE_MIGRATION_OPENING_BALANCE, $event->event_type);
        $this->assertSame(0, $event->quantity_delta);
        $this->assertSame(1200, $event->after_state['purchase_unit_cost']);
        $this->assertSame(150, $event->after_state['purchase_shipping_cost']);
        $this->assertSame(0, $event->after_state['quantity_available']);
        $storedSnapshot = DB::connection('furimadeck')
            ->table('product_inventory_events')
            ->where('id', $event->id)
            ->value('after_state');
        $this->assertStringNotContainsString('purchase_unit_cost', (string) $storedSnapshot);
        $this->assertSame(0, (int) $product->fresh()->quantity_available);
    }

    public function test_migration_aborts_before_creating_events_when_product_quantity_violates_single_item_rule(): void
    {
        $user = User::factory()->create();
        $user->products()->create([
            'internal_sku' => 'LEDGER-INVALID-001',
            'product_name' => '数量逸脱商品',
            'condition' => 'used',
            'purchase_quantity' => 2,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $migration = require database_path('migrations/furimadeck/2026_11_06_000100_create_product_inventory_events.php');
        try {
            $migration->up();
            $this->fail('Expected the migration preflight to reject grouped inventory.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('商品1件', $exception->getMessage());
        }

        $this->assertSame(0, DB::connection('furimadeck')->table('product_inventory_events')->count());
        $this->assertSame(2, (int) $user->products()->firstOrFail()->purchase_quantity);
    }

    public function test_migration_aborts_before_creating_events_when_listing_quantity_violates_single_item_rule(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LEDGER-LISTING-001',
            'product_name' => '出品数量逸脱商品',
            'condition' => 'used',
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'ledger-single-item-marketplace',
            'name' => '台帳テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品数量逸脱商品',
            'listing_quantity' => 2,
        ]);

        $migration = require database_path('migrations/furimadeck/2026_11_06_000100_create_product_inventory_events.php');
        try {
            $migration->up();
            $this->fail('Expected the migration preflight to reject a grouped listing.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('出品1件', $exception->getMessage());
        }

        $this->assertSame(0, DB::connection('furimadeck')->table('product_inventory_events')->count());
        $this->assertSame(2, (int) $user->listings()->firstOrFail()->listing_quantity);
    }

    public function test_inventory_changes_are_atomic_idempotent_and_keep_the_single_item_balance(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LEDGER-TRANSITION-001',
            'product_name' => '在庫イベント遷移テスト',
            'condition' => 'used',
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $ledger = app(ProductInventoryEventService::class);
        $opening = $ledger->recordOpening($product, $user, 'product', (int) $product->id, now(), '初期在庫');
        $sale = $ledger->applyDelta($product, -1, ProductInventoryEvent::TYPE_SALE, 'sale', 321, $user, now(), '販売');
        $duplicate = $ledger->applyDelta($product, -1, ProductInventoryEvent::TYPE_SALE, 'sale', 321, $user, now(), '販売再試行');

        $this->assertSame($sale->id, $duplicate->id);
        $this->assertSame(0, (int) $product->fresh()->quantity_available);
        $this->assertSame(0, (int) ProductInventoryEvent::query()->where('product_id', $product->id)->sum('quantity_delta'));

        $return = $ledger->applyDelta($product, 1, ProductInventoryEvent::TYPE_RETURN, 'sale_return', 321, $user, now(), '返品再入庫');
        $this->assertSame(ProductInventoryEvent::TYPE_INITIAL_PURCHASE, $opening->event_type);
        $this->assertSame(ProductInventoryEvent::TYPE_RETURN, $return->event_type);
        $this->assertSame(1, (int) $product->fresh()->quantity_available);
        $this->assertSame(1, (int) ProductInventoryEvent::query()->where('product_id', $product->id)->sum('quantity_delta'));
    }
}
