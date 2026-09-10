<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Listing extends Model
{
    public const STATUSES = [
        'draft',
        'ready',
        'active',
        'sold',
        'ended',
        'cancelled',
    ];

    public const USER_SETTABLE_STATUSES = [
        'draft',
        'ready',
        'active',
        'ended',
        'cancelled',
    ];

    public const STATUS_LABELS = [
        'draft' => '下書き',
        'ready' => '出品準備完了',
        'active' => '出品中',
        'sold' => '売却済み',
        'ended' => '終了',
        'cancelled' => '取消',
    ];

    public const SHIPPING_PAYERS = [
        'seller',
        'buyer',
    ];

    public const SHIPPING_PAYER_LABELS = [
        'seller' => '出品者負担',
        'buyer' => '購入者負担',
    ];

    public const SALE_FORMATS = [
        'fixed_price',
        'auction',
    ];

    public const SALE_FORMAT_LABELS = [
        'fixed_price' => '定額販売',
        'auction' => 'オークション',
    ];

    public const PURCHASE_APPLICATIONS = [
        'enabled',
        'disabled',
    ];

    public const PURCHASE_APPLICATION_LABELS = [
        'enabled' => '購入申請あり',
        'disabled' => '購入申請なし',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public static function shippingPayerLabel(string $shippingPayer): string
    {
        return self::SHIPPING_PAYER_LABELS[$shippingPayer] ?? $shippingPayer;
    }

    public static function saleFormatLabel(string $saleFormat): string
    {
        return self::SALE_FORMAT_LABELS[$saleFormat] ?? $saleFormat;
    }

    public static function purchaseApplicationLabel(string $purchaseApplication): string
    {
        return self::PURCHASE_APPLICATION_LABELS[$purchaseApplication] ?? $purchaseApplication;
    }

    protected $fillable = [
        'product_id',
        'marketplace_id',
        'marketplace_other_name',
        'listing_title',
        'listing_description',
        'listing_price',
        'listing_quantity',
        'expected_fee_rate',
        'expected_profit',
        'expected_margin',
        'marketplace_category',
        'marketplace_condition',
        'shipping_method',
        'shipping_payer',
        'sender_region',
        'dispatch_days',
        'shipping_size',
        'shipping_weight_grams',
        'sale_format',
        'listing_period_days',
        'return_policy',
        'purchase_application',
        'shipping_fee',
        'external_listing_url',
        'external_listing_id',
        'status',
        'listed_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'listing_price' => 'integer',
            'listing_quantity' => 'integer',
            'expected_fee_rate' => 'decimal:2',
            'expected_profit' => 'integer',
            'expected_margin' => 'decimal:2',
            'shipping_fee' => 'integer',
            'dispatch_days' => 'integer',
            'shipping_weight_grams' => 'integer',
            'listing_period_days' => 'integer',
            'listed_at' => 'datetime',
            'ended_at' => 'datetime',
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

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
