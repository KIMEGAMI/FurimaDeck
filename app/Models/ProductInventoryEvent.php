<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductInventoryEvent extends Model
{
    public const TYPE_INITIAL_PURCHASE = 'initial_purchase';

    public const TYPE_PURCHASE_INFO_CHANGED = 'purchase_info_changed';

    public const TYPE_SALE = 'sale';

    public const TYPE_SALE_CANCELLED = 'sale_cancelled';

    public const TYPE_RETURN = 'return';

    public const TYPE_INVENTORY_ADJUSTMENT = 'inventory_adjustment';

    public const TYPE_MIGRATION_OPENING_BALANCE = 'migration_opening_balance';

    protected $connection = 'furimadeck';

    protected $fillable = [
        'user_id',
        'product_id',
        'event_type',
        'quantity_delta',
        'source_type',
        'source_id',
        'idempotency_key',
        'occurred_at',
        'before_state',
        'after_state',
        'actor_user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'source_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'before_state' => 'encrypted:array',
            'after_state' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function eventTypeLabel(): string
    {
        return match ($this->event_type) {
            self::TYPE_INITIAL_PURCHASE => '初回仕入・在庫登録',
            self::TYPE_PURCHASE_INFO_CHANGED => '仕入情報の変更',
            self::TYPE_SALE => '販売',
            self::TYPE_SALE_CANCELLED => '販売取消',
            self::TYPE_RETURN => '返品',
            self::TYPE_INVENTORY_ADJUSTMENT => '在庫調整',
            self::TYPE_MIGRATION_OPENING_BALANCE => '台帳移行時の開始残高',
            default => '在庫履歴',
        };
    }
}
