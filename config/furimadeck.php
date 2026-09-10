<?php

return [
    'cutover_enabled' => env('FURIMADECK_CUTOVER_ENABLED', false),
    'product_management_enabled' => env('FURIMADECK_PRODUCT_MANAGEMENT_ENABLED', false),
    'product_images' => [
        'max_count' => env('FURIMADECK_PRODUCT_IMAGE_MAX_COUNT', 10),
        'max_size_kilobytes' => env('FURIMADECK_PRODUCT_IMAGE_MAX_SIZE_KILOBYTES', 2048),
        'max_dimension' => env('FURIMADECK_PRODUCT_IMAGE_MAX_DIMENSION', 2000),
        'max_source_pixels' => env('FURIMADECK_PRODUCT_IMAGE_MAX_SOURCE_PIXELS', 40000000),
        'webp_quality' => env('FURIMADECK_PRODUCT_IMAGE_WEBP_QUALITY', 80),
        'thumbnail_dimension' => env('FURIMADECK_PRODUCT_IMAGE_THUMBNAIL_DIMENSION', 400),
    ],
    'csv_import' => [
        'max_size_kilobytes' => env('FURIMADECK_CSV_IMPORT_MAX_SIZE_KILOBYTES', 2048),
        'max_rows' => env('FURIMADECK_CSV_IMPORT_MAX_ROWS', 1000),
        'max_cell_characters' => env('FURIMADECK_CSV_IMPORT_MAX_CELL_CHARACTERS', 10000),
        'preview_rows_per_page' => env('FURIMADECK_CSV_IMPORT_PREVIEW_ROWS_PER_PAGE', 100),
    ],
    'plans' => [
        'free' => [
            'product_limit' => env('FURIMADECK_FREE_PRODUCT_LIMIT', 50),
        ],
    ],
    'billing' => [
        'monthly_price_jpy' => env('FURIMADECK_MONTHLY_PRICE_JPY', 980),
        'trial_period_days' => env('FURIMADECK_TRIAL_PERIOD_DAYS', 7),
        'stripe_api_base' => env('FURIMADECK_STRIPE_API_BASE', 'https://api.stripe.com/v1'),
        'stripe_secret' => env('FURIMADECK_STRIPE_SECRET'),
        'stripe_webhook_secret' => env('FURIMADECK_STRIPE_WEBHOOK_SECRET'),
        'stripe_price_id' => env('FURIMADECK_STRIPE_PRICE_ID'),
        'stripe_portal_configuration_id' => env('FURIMADECK_STRIPE_PORTAL_CONFIGURATION_ID'),
    ],
    'ai' => [
        'provider' => env('FURIMADECK_AI_PROVIDER', 'openai'),
        'model' => env('FURIMADECK_AI_MODEL', ''),
        'monthly_cost_limit_jpy' => env('FURIMADECK_AI_MONTHLY_COST_LIMIT_JPY', 50),
        'confidence_ready_threshold' => env('FURIMADECK_AI_CONFIDENCE_READY_THRESHOLD', 0.9),
        'confidence_review_threshold' => env('FURIMADECK_AI_CONFIDENCE_REVIEW_THRESHOLD', 0.7),
    ],
    'inventory' => [
        'long_term_days' => env('FURIMADECK_LONG_TERM_INVENTORY_DAYS', 90),
    ],
];
