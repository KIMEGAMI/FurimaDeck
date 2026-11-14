<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductInventoryEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckFurimaDeckInventoryLedger extends Command
{
    protected $signature = 'furimadeck:check-inventory-ledger';

    protected $description = 'Read-only inventory and event-ledger consistency check without product details';

    public function handle(): int
    {
        try {
            $connection = DB::connection('furimadeck');
            $schema = Schema::connection('furimadeck');
            if (! $schema->hasTable('products') || ! $schema->hasTable('sales')
                || ! $schema->hasTable('listings') || ! $schema->hasTable('product_inventory_events')) {
                $this->error('NG: 商品・販売・出品・在庫履歴テーブルがそろっていません。');

                return self::FAILURE;
            }

            foreach ([
                'products' => ['id', 'user_id', 'purchase_quantity', 'quantity_available', 'inventory_status'],
                'sales' => ['user_id', 'product_id', 'quantity'],
                'listings' => ['user_id', 'product_id', 'listing_quantity'],
                'product_inventory_events' => ['user_id', 'product_id', 'event_type', 'quantity_delta'],
            ] as $table => $columns) {
                foreach ($columns as $column) {
                    if (! $schema->hasColumn($table, $column)) {
                        $this->error('NG: 在庫履歴チェックに必要なDBカラムがありません。');

                        return self::FAILURE;
                    }
                }
            }

            $products = $connection->table('products');
            $events = $connection->table('product_inventory_events');
            $balanceByProduct = $connection->table('product_inventory_events')
                ->select(['user_id', 'product_id'])
                ->selectRaw('SUM(quantity_delta) AS ledger_balance')
                ->whereNotNull('product_id')
                ->groupBy('user_id', 'product_id');

            $productCount = (int) $products->count();
            $eventCount = (int) $events->count();
            $productsWithoutEvents = (int) $connection->table('products')
                ->leftJoin('product_inventory_events', function ($join): void {
                    $join->on('product_inventory_events.product_id', '=', 'products.id')
                        ->on('product_inventory_events.user_id', '=', 'products.user_id');
                })
                ->whereNull('product_inventory_events.id')
                ->count();
            $balanceMismatches = (int) $connection->table('products')
                ->leftJoinSub($balanceByProduct, 'inventory_ledger', function ($join): void {
                    $join->on('inventory_ledger.product_id', '=', 'products.id')
                        ->on('inventory_ledger.user_id', '=', 'products.user_id');
                })
                ->whereNotNull('inventory_ledger.product_id')
                ->whereColumn('products.quantity_available', '<>', 'inventory_ledger.ledger_balance')
                ->count();
            $eventOwnershipMismatches = (int) $connection->table('product_inventory_events')
                ->join('products', 'products.id', '=', 'product_inventory_events.product_id')
                ->whereColumn('products.user_id', '<>', 'product_inventory_events.user_id')
                ->count();
            $saleOwnershipMismatches = (int) $connection->table('sales')
                ->leftJoin('products', 'products.id', '=', 'sales.product_id')
                ->where(function ($query): void {
                    $query->whereNull('products.id')
                        ->orWhereColumn('products.user_id', '<>', 'sales.user_id');
                })
                ->count();
            $listingOwnershipMismatches = (int) $connection->table('listings')
                ->leftJoin('products', 'products.id', '=', 'listings.product_id')
                ->where(function ($query): void {
                    $query->whereNull('products.id')
                        ->orWhereColumn('products.user_id', '<>', 'listings.user_id');
                })
                ->count();
            $validEventTypes = [
                ProductInventoryEvent::TYPE_INITIAL_PURCHASE,
                ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED,
                ProductInventoryEvent::TYPE_SALE,
                ProductInventoryEvent::TYPE_SALE_CANCELLED,
                ProductInventoryEvent::TYPE_RETURN,
                ProductInventoryEvent::TYPE_INVENTORY_ADJUSTMENT,
                ProductInventoryEvent::TYPE_MIGRATION_OPENING_BALANCE,
            ];
            $invalidEventTypes = (int) $connection->table('product_inventory_events')
                ->whereNotIn('event_type', $validEventTypes)
                ->count();
            $invalidEventDeltas = (int) $connection->table('product_inventory_events')
                ->where(function ($query): void {
                    $query->whereIn('event_type', [
                        ProductInventoryEvent::TYPE_INITIAL_PURCHASE,
                        ProductInventoryEvent::TYPE_MIGRATION_OPENING_BALANCE,
                    ])->whereNotIn('quantity_delta', [0, Product::UNITS_PER_PRODUCT_ID])
                        ->orWhere(function ($purchaseInfo): void {
                            $purchaseInfo->where('event_type', ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED)
                                ->where('quantity_delta', '<>', 0);
                        })
                        ->orWhere(function ($sale): void {
                            $sale->where('event_type', ProductInventoryEvent::TYPE_SALE)
                                ->where('quantity_delta', '<>', -Product::UNITS_PER_PRODUCT_ID);
                        })
                        ->orWhereIn('event_type', [
                            ProductInventoryEvent::TYPE_SALE_CANCELLED,
                            ProductInventoryEvent::TYPE_RETURN,
                        ])->where('quantity_delta', '<>', Product::UNITS_PER_PRODUCT_ID)
                        ->orWhere(function ($adjustment): void {
                            $adjustment->where('event_type', ProductInventoryEvent::TYPE_INVENTORY_ADJUSTMENT)
                                ->whereNotIn('quantity_delta', [-Product::UNITS_PER_PRODUCT_ID, Product::UNITS_PER_PRODUCT_ID]);
                        });
                })
                ->count();
            $invalidProductStates = (int) $connection->table('products')
                ->where(function ($query): void {
                    $query->where('purchase_quantity', '<>', Product::UNITS_PER_PRODUCT_ID)
                        ->orWhereNotIn('quantity_available', [0, Product::UNITS_PER_PRODUCT_ID])
                        ->orWhere(function ($status): void {
                            $status->where('quantity_available', '>', 0)->where('inventory_status', '<>', 'in_stock');
                        })
                        ->orWhere(function ($status): void {
                            $status->where('quantity_available', 0)->where('inventory_status', '<>', 'out_of_stock');
                        });
                })
                ->count();
            $invalidSales = (int) $connection->table('sales')->where('quantity', '<>', Product::UNITS_PER_PRODUCT_ID)->count();
            $invalidListings = (int) $connection->table('listings')->where('listing_quantity', '<>', Product::UNITS_PER_PRODUCT_ID)->count();
        } catch (Throwable) {
            $this->error('NG: 在庫履歴を読み取れません。DB接続・スキーマを確認してください。');

            return self::FAILURE;
        }

        $this->line(sprintf('商品 %d件 / 在庫イベント %d件を読み取り確認しました。', $productCount, $eventCount));

        $issues = [
            'イベント未記録の商品' => $productsWithoutEvents,
            '在庫残高の不一致' => $balanceMismatches,
            '在庫イベント所有者の不一致' => $eventOwnershipMismatches,
            '販売の商品所有者不一致' => $saleOwnershipMismatches,
            '出品の商品所有者不一致' => $listingOwnershipMismatches,
            '未対応のイベント種別' => $invalidEventTypes,
            'イベント種別と数量差分の不一致' => $invalidEventDeltas,
            '商品ID・在庫状態の数量逸脱' => $invalidProductStates,
            '販売数量の逸脱' => $invalidSales,
            '出品数量の逸脱' => $invalidListings,
        ];
        $hasIssues = false;
        foreach ($issues as $label => $count) {
            if ($count > 0) {
                $this->error('NG: '.$label.' '.$count.'件');
                $hasIssues = true;
            }
        }

        if ($hasIssues) {
            return self::FAILURE;
        }

        $this->info('在庫残高と在庫イベント台帳が一致しています。商品名・IDなどの明細は表示していません。');

        return self::SUCCESS;
    }
}
