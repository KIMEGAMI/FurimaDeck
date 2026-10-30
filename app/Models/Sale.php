<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    public const STATUSES = [
        'pending',
        'awaiting_shipment',
        'shipped',
        'completed',
        'cancelled',
        'returned',
    ];

    public const VALID_SOLD_STATUSES = ['awaiting_shipment', 'shipped', 'completed'];

    public const STATUS_LABELS = [
        'pending' => '処理待ち',
        'awaiting_shipment' => '発送待ち',
        'shipped' => '発送済み',
        'completed' => '取引完了',
        'cancelled' => '取消済み',
        'returned' => '返品・返金済み',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public function recordVersionToken(): string
    {
        $attributes = $this->getRawOriginal();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function setSoldAtAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['sold_at'] = $value;

            return;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $this->attributes['sold_at'] = CarbonImmutable::parse($value, $timezone)
            ->setTimezone($timezone)
            ->format($this->getDateFormat());
    }

    protected $fillable = [
        'user_id',
        'product_id',
        'listing_id',
        'marketplace_id',
        'internal_sku_snapshot',
        'product_name_snapshot',
        'quantity',
        'sold_price',
        'sold_at',
        'status',
        'cost_basis',
        'sales_fee',
        'sales_fee_rate',
        'shipping_fee',
        'purchase_shipping_cost',
        'packing_cost',
        'repair_cost',
        'cleaning_cost',
        'other_expense',
        'accounting_expense_details',
        'net_profit',
        'shipped_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'cancelled_from_pending',
        'returned_at',
        'return_reason',
        'refund_amount',
        'return_shipping_fee',
        'restocked_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sold_price' => 'integer',
            'sold_at' => 'datetime',
            'cost_basis' => 'integer',
            'sales_fee' => 'integer',
            'sales_fee_rate' => 'decimal:2',
            'shipping_fee' => 'integer',
            'purchase_shipping_cost' => 'integer',
            'packing_cost' => 'integer',
            'repair_cost' => 'integer',
            'cleaning_cost' => 'integer',
            'other_expense' => 'integer',
            'accounting_expense_details' => 'array',
            'net_profit' => 'integer',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_from_pending' => 'boolean',
            'returned_at' => 'datetime',
            'refund_amount' => 'integer',
            'return_shipping_fee' => 'integer',
            'restocked_quantity' => 'integer',
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

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    public function accountingEntry(): HasOne
    {
        return $this->hasOne(AccountingEntry::class, 'sale_id');
    }
}
