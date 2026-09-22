<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    private const MINUTES_PER_HOUR = 60;

    private const MINUTES_PER_DAY = 1440;

    public const CONDITIONS = [
        'new',
        'unused',
        'like_new',
        'used_good',
        'used',
        'damaged',
        'junk',
    ];

    public const CONDITION_LABELS = [
        'new' => '新品',
        'unused' => '未使用',
        'like_new' => '未使用に近い',
        'used_good' => '目立った傷や汚れなし',
        'used' => 'やや傷や汚れあり',
        'damaged' => '傷や汚れあり',
        'junk' => 'ジャンク品',
    ];

    public const INVENTORY_STATUSES = ['in_stock', 'out_of_stock'];

    public const INVENTORY_STATUS_LABELS = [
        'in_stock' => '在庫あり',
        'out_of_stock' => '在庫なし',
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            $product->inventory_status = self::inventoryStatusForQuantity((int) $product->quantity_available);
        });
    }

    public static function inventoryStatusForQuantity(int $quantityAvailable): string
    {
        return $quantityAvailable > 0 ? 'in_stock' : 'out_of_stock';
    }

    public function syncInventoryStatus(): self
    {
        $this->inventory_status = self::inventoryStatusForQuantity((int) $this->quantity_available);

        return $this;
    }

    public static function conditionLabel(string $condition): string
    {
        return self::CONDITION_LABELS[$condition] ?? $condition;
    }

    public static function inventoryStatusLabel(string $status): string
    {
        return self::INVENTORY_STATUS_LABELS[$status] ?? $status;
    }

    protected $fillable = [
        'internal_sku',
        'product_name',
        'category_id',
        'condition',
        'description_base',
        'purchase_date',
        'purchase_unit_cost',
        'purchase_quantity',
        'quantity_available',
        'purchase_shipping_cost',
        'other_purchase_expense',
        'supplier_id',
        'jan_ean',
        'isbn',
        'manufacturer_model_number',
        'serial_number',
        'storage_location',
        'inventory_status',
        'memo',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_unit_cost' => 'integer',
            'purchase_quantity' => 'integer',
            'quantity_available' => 'integer',
            'purchase_shipping_cost' => 'integer',
            'other_purchase_expense' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function validSales(): HasMany
    {
        return $this->hasMany(Sale::class)
            ->whereIn('status', Sale::VALID_SOLD_STATUSES)
            ->latest('sold_at');
    }

    public function scopeWithValidSaleFlag(Builder $query): Builder
    {
        return $query->withExists(['sales as has_valid_sale' => fn (Builder $sales): Builder => $sales->whereIn('status', Sale::VALID_SOLD_STATUSES)]);
    }

    public function isSoldOutByValidSale(): bool
    {
        return (int) $this->quantity_available === 0 && (bool) ($this->has_valid_sale ?? $this->sales()->whereIn('status', Sale::VALID_SOLD_STATUSES)->exists());
    }

    public function inventoryAgeLabel(): string
    {
        if ($this->created_at === null) {
            return '未設定';
        }

        $minutes = max(0, (int) $this->created_at->diffInMinutes(now()));
        if ($minutes < self::MINUTES_PER_HOUR) {
            return $minutes.'分';
        }

        if ($minutes < self::MINUTES_PER_DAY) {
            $hours = intdiv($minutes, self::MINUTES_PER_HOUR);
            $remainingMinutes = $minutes % self::MINUTES_PER_HOUR;

            return $hours.'時間'.($remainingMinutes > 0 ? $remainingMinutes.'分' : '');
        }

        $days = intdiv($minutes, self::MINUTES_PER_DAY);
        $remainingHours = intdiv($minutes % self::MINUTES_PER_DAY, self::MINUTES_PER_HOUR);

        return $days.'日'.($remainingHours > 0 ? $remainingHours.'時間' : '');
    }
}
