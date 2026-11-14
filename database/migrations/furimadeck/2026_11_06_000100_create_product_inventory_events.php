<?php

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SNAPSHOT_FIELDS = [
        'internal_sku',
        'product_name',
        'category_id',
        'condition',
        'purchase_date',
        'purchase_info_confirmed',
        'purchase_accounting_enabled',
        'purchase_document_type',
        'purchase_tax_category',
        'purchase_invoice_issuer',
        'purchase_payment_sources',
        'purchase_evidence',
        'purchase_special_case_data',
        'purchase_document_reviewed_at',
        'purchase_unit_cost',
        'purchase_quantity',
        'quantity_available',
        'purchase_shipping_cost',
        'other_purchase_expense',
        'supplier_id',
    ];

    public function up(): void
    {
        $connection = DB::connection('furimadeck');

        $invalidProducts = $connection->table('products')
            ->where(function ($query): void {
                $query->where('purchase_quantity', '!=', Product::UNITS_PER_PRODUCT_ID)
                    ->orWhereNotIn('quantity_available', [0, Product::UNITS_PER_PRODUCT_ID])
                    ->orWhere(function ($status): void {
                        $status->where('quantity_available', '>', 0)->where('inventory_status', '!=', 'in_stock');
                    })
                    ->orWhere(function ($status): void {
                        $status->where('quantity_available', 0)->where('inventory_status', '!=', 'out_of_stock');
                    });
            })
            ->count();
        $invalidSales = $connection->table('sales')
            ->where('quantity', '!=', Product::UNITS_PER_PRODUCT_ID)
            ->count();
        $invalidListings = $connection->table('listings')
            ->where('listing_quantity', '!=', Product::UNITS_PER_PRODUCT_ID)
            ->count();

        if ($invalidProducts > 0 || $invalidSales > 0 || $invalidListings > 0) {
            throw new RuntimeException(sprintf(
                '商品在庫台帳を作成できません。数量が確定仕様と異なる商品%d件・販売%d件・出品%d件を読み取り確認してください。データは変更していません。',
                $invalidProducts,
                $invalidSales,
                $invalidListings,
            ));
        }

        // Decrypt existing protected purchase evidence before creating any schema or rows.
        $this->validateSnapshots($connection);

        if (! Schema::connection('furimadeck')->hasTable('product_inventory_events')) {
            Schema::connection('furimadeck')->create('product_inventory_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event_type', 40);
                $table->smallInteger('quantity_delta');
                $table->string('source_type', 40)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->char('idempotency_key', 64);
                $table->timestamp('occurred_at');
                $table->longText('before_state')->nullable();
                $table->longText('after_state')->nullable();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'idempotency_key'], 'product_inventory_events_user_key_unique');
                $table->index(['user_id', 'product_id', 'occurred_at'], 'product_inventory_events_product_time_index');
                $table->index(['user_id', 'event_type', 'occurred_at'], 'product_inventory_events_type_time_index');
                $table->index(['source_type', 'source_id'], 'product_inventory_events_source_index');
            });
        }

        $this->backfillOpeningBalances($connection);
        $this->assertBackfillMatchesProducts($connection);
    }

    public function down(): void
    {
        $connection = DB::connection('furimadeck');
        if (Schema::connection('furimadeck')->hasTable('product_inventory_events')
            && $connection->table('product_inventory_events')->exists()) {
            throw new RuntimeException('在庫イベントが存在するため、安全のため台帳migrationは戻せません。データを削除せず、アプリケーション側の切り戻し手順を使ってください。');
        }

        Schema::connection('furimadeck')->dropIfExists('product_inventory_events');
    }

    private function validateSnapshots($connection): void
    {
        $connection->table('products')
            ->select(array_merge(['id'], self::SNAPSHOT_FIELDS))
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $this->snapshotFor($product);
                }
            });
    }

    private function backfillOpeningBalances($connection): void
    {
        $occurredAt = now();
        $connection->table('products')
            ->select(array_merge(['id', 'user_id', 'created_at', 'updated_at'], self::SNAPSHOT_FIELDS))
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($connection, $occurredAt): void {
                $events = [];
                foreach ($products as $product) {
                    $events[] = [
                        'user_id' => (int) $product->user_id,
                        'product_id' => (int) $product->id,
                        'event_type' => 'migration_opening_balance',
                        'quantity_delta' => (int) $product->quantity_available,
                        'source_type' => 'legacy_opening',
                        'source_id' => (int) $product->id,
                        'idempotency_key' => hash('sha256', 'furimadeck-legacy-opening-v1:'.$product->id),
                        'occurred_at' => $occurredAt,
                        'before_state' => null,
                        'after_state' => Crypt::encryptString(json_encode($this->snapshotFor($product), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                        'actor_user_id' => null,
                        'reason' => '既存商品を移行した時点の在庫・仕入情報',
                        'created_at' => $occurredAt,
                        'updated_at' => $occurredAt,
                    ];
                }

                if ($events !== []) {
                    $connection->table('product_inventory_events')->insertOrIgnore($events);
                }
            });
    }

    private function snapshotFor(object $product): array
    {
        $snapshot = [];
        foreach (self::SNAPSHOT_FIELDS as $field) {
            $value = $product->{$field};
            if (in_array($field, ['purchase_payment_sources', 'purchase_evidence'], true)) {
                $value = $value === null ? null : json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
            } elseif ($field === 'purchase_special_case_data' && $value !== null) {
                $value = json_decode(Crypt::decryptString((string) $value), true, flags: JSON_THROW_ON_ERROR);
            }
            $snapshot[$field] = $value;
        }

        return $snapshot;
    }

    private function assertBackfillMatchesProducts($connection): void
    {
        $productCount = (int) $connection->table('products')->count();
        $openingCount = (int) $connection->table('product_inventory_events')
            ->where('event_type', 'migration_opening_balance')
            ->count();
        $mismatchedBalances = $connection->table('products')
            ->leftJoin('product_inventory_events', function ($join): void {
                $join->on('product_inventory_events.product_id', '=', 'products.id')
                    ->on('product_inventory_events.user_id', '=', 'products.user_id');
            })
            ->where('product_inventory_events.event_type', 'migration_opening_balance')
            ->select('products.id', 'products.quantity_available', 'product_inventory_events.quantity_delta')
            ->get()
            ->filter(fn ($row): bool => (int) $row->quantity_available !== (int) $row->quantity_delta)
            ->count();

        if ($productCount !== $openingCount || $mismatchedBalances !== 0) {
            throw new RuntimeException('在庫台帳の初期記録件数または残高が既存商品と一致しません。商品データは変更していません。');
        }
    }
};
