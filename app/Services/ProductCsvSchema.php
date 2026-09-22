<?php

namespace App\Services;

final class ProductCsvSchema
{
    public const REQUIRED_PRODUCT_HEADERS = [
        'internal_sku',
        'product_name',
        'condition',
        'purchase_unit_cost',
        'purchase_quantity',
        'quantity_available',
        'inventory_status',
    ];

    public const OPTIONAL_PRODUCT_HEADERS = [
        'description_base',
        'purchase_date',
        'purchase_shipping_cost',
        'other_purchase_expense',
        'jan_ean',
        'isbn',
        'manufacturer_model_number',
        'serial_number',
        'storage_location',
        'memo',
        // Retained for compatibility with existing product-only exports.
        'category_id',
        'supplier_id',
        'parent_category',
        'category',
        'status',
    ];

    public const LISTING_HEADERS = [
        'marketplace_code',
        'listed_at',
        'listing_price',
    ];

    public const CONTROL_HEADERS = ['sale_state'];

    public const SALE_HEADERS = [
        'sale_quantity',
        'sold_price',
        'sold_at',
        'sales_fee',
        'shipping_fee',
        'sale_purchase_shipping_cost',
        'packing_cost',
        'repair_cost',
        'cleaning_cost',
        'sale_other_expense',
    ];

    public const ALL_SUPPORTED_HEADERS = [
        ...self::REQUIRED_PRODUCT_HEADERS,
        ...self::OPTIONAL_PRODUCT_HEADERS,
        ...self::LISTING_HEADERS,
        ...self::CONTROL_HEADERS,
        ...self::SALE_HEADERS,
    ];

    public const MARKETPLACE_CODES = [
        'mercari',
        'yahoo_flea_market',
        'yahoo_auctions',
        'rakuma',
        'other',
    ];

    public static function isSupported(string $header): bool
    {
        return in_array($header, self::ALL_SUPPORTED_HEADERS, true);
    }
}
