<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
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

    public const INVENTORY_STATUSES = [
        'draft',
        'in_stock',
        'listing_ready',
        'listed',
        'reserved',
        'out_of_stock',
        'archived',
    ];

    public const INVENTORY_STATUS_LABELS = [
        'draft' => '下書き',
        'in_stock' => '在庫あり',
        'listing_ready' => '出品準備完了',
        'listed' => '出品中',
        'reserved' => '取引中',
        'out_of_stock' => '在庫なし',
        'archived' => '保管済み',
    ];

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
}
