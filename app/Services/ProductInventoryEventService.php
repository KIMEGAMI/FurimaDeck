<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventoryEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ProductInventoryEventService
{
    public function recordOpening(Product $product, User $actor, string $sourceType, int $sourceId, CarbonInterface $occurredAt, string $reason): ProductInventoryEvent
    {
        $this->assertLedgerReady();
        $product = Product::query()->whereKey($product->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
        $key = $this->key($product, ProductInventoryEvent::TYPE_INITIAL_PURCHASE, $sourceType, $sourceId);
        $existing = ProductInventoryEvent::query()->where('user_id', $actor->id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        return ProductInventoryEvent::query()->create([
            'user_id' => $actor->id,
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_INITIAL_PURCHASE,
            'quantity_delta' => (int) $product->quantity_available,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'idempotency_key' => $key,
            'occurred_at' => $occurredAt,
            'before_state' => null,
            'after_state' => $this->snapshot($product),
            'actor_user_id' => $actor->id,
            'reason' => $reason,
        ]);
    }

    public function applyDelta(
        Product $product,
        int $delta,
        string $eventType,
        string $sourceType,
        int $sourceId,
        User $actor,
        CarbonInterface $occurredAt,
        string $reason,
    ): ProductInventoryEvent {
        $this->assertLedgerReady();
        if (! in_array($eventType, [
            ProductInventoryEvent::TYPE_SALE,
            ProductInventoryEvent::TYPE_SALE_CANCELLED,
            ProductInventoryEvent::TYPE_RETURN,
            ProductInventoryEvent::TYPE_INVENTORY_ADJUSTMENT,
        ], true)) {
            throw new RuntimeException('指定した在庫イベント種別は残高変更に使用できません。');
        }

        return DB::connection('furimadeck')->transaction(function () use ($product, $delta, $eventType, $sourceType, $sourceId, $actor, $occurredAt, $reason): ProductInventoryEvent {
            $lockedProduct = Product::query()->whereKey($product->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $key = $this->key($lockedProduct, $eventType, $sourceType, $sourceId);
            $existing = ProductInventoryEvent::query()->where('user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $this->assertBalanceMatchesProduct($lockedProduct);

                return $existing;
            }

            $this->ensureOpeningBalance($lockedProduct, $actor, $occurredAt);
            $before = $this->snapshot($lockedProduct);
            $nextQuantity = (int) $lockedProduct->quantity_available + $delta;
            if (! in_array($nextQuantity, [0, Product::UNITS_PER_PRODUCT_ID], true)) {
                throw new RuntimeException('商品IDごとの在庫は0点または1点です。数量を確認してください。');
            }
            $lockedProduct->update([
                'quantity_available' => $nextQuantity,
                'inventory_status' => Product::inventoryStatusForQuantity($nextQuantity),
            ]);
            $lockedProduct->refresh();

            $event = ProductInventoryEvent::query()->create([
                'user_id' => $actor->id,
                'product_id' => $lockedProduct->id,
                'event_type' => $eventType,
                'quantity_delta' => $delta,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $key,
                'occurred_at' => $occurredAt,
                'before_state' => $before,
                'after_state' => $this->snapshot($lockedProduct),
                'actor_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            $this->assertBalanceMatchesProduct($lockedProduct);

            return $event;
        });
    }

    public function adjustToQuantity(Product $product, int $targetQuantity, string $requestToken, User $actor, CarbonInterface $occurredAt, string $reason): ?ProductInventoryEvent
    {
        $this->assertLedgerReady();
        if (! in_array($targetQuantity, [0, Product::UNITS_PER_PRODUCT_ID], true)) {
            throw new RuntimeException('商品IDごとの在庫は0点または1点です。数量を確認してください。');
        }

        return DB::connection('furimadeck')->transaction(function () use ($product, $targetQuantity, $requestToken, $actor, $occurredAt, $reason): ?ProductInventoryEvent {
            $lockedProduct = Product::query()->whereKey($product->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $key = hash('sha256', implode(':', [
                'furimadeck-product-inventory-v1',
                'manual_adjustment',
                $actor->id,
                $lockedProduct->id,
                $requestToken,
            ]));
            $existing = ProductInventoryEvent::query()
                ->where('user_id', $actor->id)
                ->where('idempotency_key', $key)
                ->first();
            if ($existing !== null) {
                $this->assertBalanceMatchesProduct($lockedProduct);

                return $existing;
            }

            $this->ensureOpeningBalance($lockedProduct, $actor, $occurredAt);
            $before = $this->snapshot($lockedProduct);
            $currentQuantity = (int) $lockedProduct->quantity_available;
            $delta = $targetQuantity - $currentQuantity;
            if ($delta === 0) {
                return null;
            }

            $lockedProduct->update([
                'quantity_available' => $targetQuantity,
                'inventory_status' => Product::inventoryStatusForQuantity($targetQuantity),
            ]);
            $lockedProduct->refresh();

            $event = ProductInventoryEvent::query()->create([
                'user_id' => $actor->id,
                'product_id' => $lockedProduct->id,
                'event_type' => ProductInventoryEvent::TYPE_INVENTORY_ADJUSTMENT,
                'quantity_delta' => $delta,
                'source_type' => 'manual_adjustment',
                'source_id' => null,
                'idempotency_key' => $key,
                'occurred_at' => $occurredAt,
                'before_state' => $before,
                'after_state' => $this->snapshot($lockedProduct),
                'actor_user_id' => $actor->id,
                'reason' => $reason,
            ]);

            $this->assertBalanceMatchesProduct($lockedProduct);

            return $event;
        });
    }

    public function recordPurchaseInfoChange(Product $product, User $actor, array $beforeState, string $reason): ProductInventoryEvent
    {
        $this->assertLedgerReady();
        $lockedProduct = Product::query()->whereKey($product->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
        $this->ensureOpeningBalance($lockedProduct, $actor, now());
        $key = hash('sha256', 'furimadeck-product-inventory-v1:purchase_info:'.$lockedProduct->id.':'.Str::uuid());

        $event = ProductInventoryEvent::query()->create([
            'user_id' => $actor->id,
            'product_id' => $lockedProduct->id,
            'event_type' => ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED,
            'quantity_delta' => 0,
            'source_type' => 'product',
            'source_id' => null,
            'idempotency_key' => $key,
            'occurred_at' => now(),
            'before_state' => $beforeState,
            'after_state' => $this->snapshot($lockedProduct),
            'actor_user_id' => $actor->id,
            'reason' => $reason,
        ]);

        $this->assertBalanceMatchesProduct($lockedProduct);

        return $event;
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $product): array
    {
        return [
            'internal_sku' => (string) $product->internal_sku,
            'product_name' => (string) $product->product_name,
            'category_id' => $product->category_id === null ? null : (int) $product->category_id,
            'condition' => (string) $product->condition,
            'purchase_date' => $product->purchase_date?->toDateString(),
            'purchase_info_confirmed' => (bool) $product->purchase_info_confirmed,
            'purchase_unit_cost' => (int) $product->purchase_unit_cost,
            'purchase_quantity' => (int) $product->purchase_quantity,
            'quantity_available' => (int) $product->quantity_available,
            'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
            'other_purchase_expense' => (int) $product->other_purchase_expense,
            'supplier_id' => $product->supplier_id === null ? null : (int) $product->supplier_id,
            'supplier_name' => $product->supplier?->name,
            'inventory_status' => (string) $product->inventory_status,
        ];
    }

    private function key(Product $product, string $eventType, string $sourceType, int $sourceId): string
    {
        return hash('sha256', implode(':', [
            'furimadeck-product-inventory-v1',
            $product->user_id,
            $product->id,
            $eventType,
            $sourceType,
            $sourceId,
        ]));
    }

    private function assertLedgerReady(): void
    {
        if (! Schema::connection('furimadeck')->hasTable('product_inventory_events')) {
            throw new RuntimeException('在庫履歴テーブルが未作成です。商品データを変更せず、先に在庫履歴migrationを適用してください。');
        }
    }

    private function ensureOpeningBalance(Product $product, User $actor, CarbonInterface $occurredAt): void
    {
        $hasEvents = ProductInventoryEvent::query()
            ->where('user_id', $actor->id)
            ->where('product_id', $product->id)
            ->exists();
        if (! $hasEvents) {
            $this->recordOpening($product, $actor, 'opening_balance_repair', (int) $product->id, $occurredAt, '在庫台帳への初回残高登録');
        }

        $this->assertBalanceMatchesProduct($product);
    }

    private function assertBalanceMatchesProduct(Product $product): void
    {
        $ledgerBalance = (int) ProductInventoryEvent::query()
            ->where('user_id', $product->user_id)
            ->where('product_id', $product->id)
            ->sum('quantity_delta');

        if ($ledgerBalance !== (int) $product->quantity_available) {
            throw new RuntimeException('商品在庫と入出庫履歴が一致しません。商品データを変更せず、在庫履歴を確認してください。');
        }
    }
}
