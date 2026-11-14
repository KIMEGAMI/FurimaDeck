<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductInventoryEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CheckFurimaDeckInventoryLedgerTest extends TestCase
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

    public function test_it_reports_a_consistent_inventory_ledger_without_personal_details(): void
    {
        $product = $this->productWithQuantity(1);
        $this->recordEvent($product, 1);

        $this->artisan('furimadeck:check-inventory-ledger')
            ->expectsOutput('商品 1件 / 在庫イベント 1件を読み取り確認しました。')
            ->expectsOutput('在庫残高と在庫イベント台帳が一致しています。商品名・IDなどの明細は表示していません。')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_an_existing_product_has_no_ledger_event(): void
    {
        $this->productWithQuantity(1);

        $this->artisan('furimadeck:check-inventory-ledger')
            ->expectsOutput('NG: イベント未記録の商品 1件')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_the_event_balance_differs_from_current_inventory(): void
    {
        $product = $this->productWithQuantity(1);
        $this->recordEvent($product, 0);

        $this->artisan('furimadeck:check-inventory-ledger')
            ->expectsOutput('NG: 在庫残高の不一致 1件')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_an_event_delta_conflicts_with_its_type(): void
    {
        $product = $this->productWithQuantity(1);
        $this->recordEvent($product, 0);
        ProductInventoryEvent::query()->create([
            'user_id' => $product->user_id,
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_SALE,
            'quantity_delta' => 1,
            'source_type' => 'test',
            'source_id' => $product->id,
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            'occurred_at' => now(),
            'after_state' => [],
            'actor_user_id' => $product->user_id,
            'reason' => '合成テストデータ',
        ]);

        $this->artisan('furimadeck:check-inventory-ledger')
            ->expectsOutput('NG: イベント種別と数量差分の不一致 1件')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_an_event_type_is_not_recognized(): void
    {
        $product = $this->productWithQuantity(1);
        $this->recordEvent($product, 1);
        ProductInventoryEvent::query()->create([
            'user_id' => $product->user_id,
            'product_id' => $product->id,
            'event_type' => 'unknown_event',
            'quantity_delta' => 0,
            'source_type' => 'test',
            'source_id' => $product->id,
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            'occurred_at' => now(),
            'after_state' => [],
            'actor_user_id' => $product->user_id,
            'reason' => '合成テストデータ',
        ]);

        $this->artisan('furimadeck:check-inventory-ledger')
            ->expectsOutput('NG: 未対応のイベント種別 1件')
            ->assertExitCode(1);
    }

    public function test_it_accepts_supported_event_type_and_delta_combinations(): void
    {
        $product = $this->productWithQuantity(1);
        $events = [
            [ProductInventoryEvent::TYPE_MIGRATION_OPENING_BALANCE, 0],
            [ProductInventoryEvent::TYPE_INITIAL_PURCHASE, 1],
            [ProductInventoryEvent::TYPE_SALE, -1],
            [ProductInventoryEvent::TYPE_SALE_CANCELLED, 1],
            [ProductInventoryEvent::TYPE_SALE, -1],
            [ProductInventoryEvent::TYPE_RETURN, 1],
            [ProductInventoryEvent::TYPE_INVENTORY_ADJUSTMENT, -1],
            [ProductInventoryEvent::TYPE_INVENTORY_ADJUSTMENT, 1],
            [ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED, 0],
        ];

        foreach ($events as [$eventType, $delta]) {
            ProductInventoryEvent::query()->create([
                'user_id' => $product->user_id,
                'product_id' => $product->id,
                'event_type' => $eventType,
                'quantity_delta' => $delta,
                'source_type' => 'test',
                'source_id' => $product->id,
                'idempotency_key' => hash('sha256', (string) Str::uuid()),
                'occurred_at' => now(),
                'after_state' => [],
                'actor_user_id' => $product->user_id,
                'reason' => '合成テストデータ',
            ]);
        }

        $this->artisan('furimadeck:check-inventory-ledger')
            ->expectsOutput('商品 1件 / 在庫イベント 9件を読み取り確認しました。')
            ->expectsOutput('在庫残高と在庫イベント台帳が一致しています。商品名・IDなどの明細は表示していません。')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_a_sale_references_another_users_product(): void
    {
        $product = $this->productWithQuantity(0);
        $this->recordEvent($product, 0);
        $otherUser = User::factory()->create();
        $marketplace = $this->marketplace();

        DB::table('sales')->insert([
            'user_id' => $otherUser->id,
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => 'OTHER-OWNER',
            'product_name_snapshot' => '合成テスト商品',
            'quantity' => Product::UNITS_PER_PRODUCT_ID,
            'sold_price' => 1000,
            'sold_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('furimadeck:check-inventory-ledger')
            ->expectsOutput('NG: 販売の商品所有者不一致 1件')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_a_listing_references_another_users_product(): void
    {
        $product = $this->productWithQuantity(1);
        $this->recordEvent($product, 1);
        $otherUser = User::factory()->create();
        $marketplace = $this->marketplace();

        DB::table('listings')->insert([
            'user_id' => $otherUser->id,
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '合成テスト出品',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('furimadeck:check-inventory-ledger')
            ->expectsOutput('NG: 出品の商品所有者不一致 1件')
            ->assertExitCode(1);
    }

    private function productWithQuantity(int $quantity): Product
    {
        $user = User::factory()->create();

        return $user->products()->create([
            'internal_sku' => 'LEDGER-'.Str::uuid(),
            'product_name' => '合成テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => $quantity,
            'inventory_status' => Product::inventoryStatusForQuantity($quantity),
        ]);
    }

    private function recordEvent(Product $product, int $delta): void
    {
        ProductInventoryEvent::query()->create([
            'user_id' => $product->user_id,
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_INITIAL_PURCHASE,
            'quantity_delta' => $delta,
            'source_type' => 'test',
            'source_id' => $product->id,
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            'occurred_at' => now(),
            'after_state' => [],
            'actor_user_id' => $product->user_id,
            'reason' => '合成テストデータ',
        ]);
    }

    private function marketplace(): Marketplace
    {
        return Marketplace::query()->create([
            'code' => 'ledger-test-'.Str::uuid(),
            'name' => '台帳テスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);
    }
}
