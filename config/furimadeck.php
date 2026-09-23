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
        'max_size_kilobytes' => env('FURIMADECK_CSV_IMPORT_MAX_SIZE_KILOBYTES', 10240),
        'max_rows' => env('FURIMADECK_CSV_IMPORT_MAX_ROWS', 5000),
        'max_cell_characters' => env('FURIMADECK_CSV_IMPORT_MAX_CELL_CHARACTERS', 10000),
        'preview_rows_per_page' => env('FURIMADECK_CSV_IMPORT_PREVIEW_ROWS_PER_PAGE', 50),
    ],
    'plans' => [
        'free' => [
            'product_limit' => env('FURIMADECK_FREE_PRODUCT_LIMIT', 50),
        ],
    ],
    'analytics' => [
        // ジャンル別分析で表示するランキングの上限。
        'category_ranking_limit' => env('FURIMADECK_ANALYTICS_CATEGORY_RANKING_LIMIT', 10),
    ],
    'marketplace_fees' => [
        // 公式料金を基準にした初期値。個別のMarketplace.default_fee_rateが優先される。
        'rates' => [
            'mercari' => 10.0,
            'yahoo_flea_market' => 5.0,
            'yahoo_auctions' => 10.0,
            // ラクマは販売実績による段階制のため、初期値は上限率で見積もる。
            'rakuma' => 10.0,
            'other' => 0.0,
        ],
        'rounding' => 'floor',
    ],
    'improvement' => [
        'stagnant_days' => [30, 60, 90],
        'relist_after_days' => env('FURIMADECK_IMPROVEMENT_RELIST_AFTER_DAYS', 60),
        'price_review_min_sales' => env('FURIMADECK_IMPROVEMENT_PRICE_REVIEW_MIN_SALES', 5),
        'price_review_deviation_percent' => env('FURIMADECK_IMPROVEMENT_PRICE_REVIEW_DEVIATION_PERCENT', 10),
        'marketplace_min_sales' => env('FURIMADECK_IMPROVEMENT_MARKETPLACE_MIN_SALES', 3),
        'marketplace_change_after_days' => env('FURIMADECK_IMPROVEMENT_MARKETPLACE_CHANGE_AFTER_DAYS', 60),
        'marketplace_speed_difference_percent' => env('FURIMADECK_IMPROVEMENT_MARKETPLACE_SPEED_DIFFERENCE_PERCENT', 20),
        'rebuy_min_sales' => env('FURIMADECK_IMPROVEMENT_REBUY_MIN_SALES', 3),
    ],

    'accounting' => [
        'oauth' => [
            'freee' => [
                'client_id' => env('FURIMADECK_FREEE_CLIENT_ID'),
                'client_secret' => env('FURIMADECK_FREEE_CLIENT_SECRET'),
                'redirect_uri' => env('FURIMADECK_FREEE_REDIRECT_URI'),
                'authorize_url' => 'https://accounts.secure.freee.co.jp/public_api/authorize',
                'token_url' => 'https://accounts.secure.freee.co.jp/public_api/token',
                'api_base' => env('FURIMADECK_FREEE_API_BASE', 'https://api.freee.co.jp'),
                'scope' => env('FURIMADECK_FREEE_SCOPE', 'read write'),
            ],
            'money_forward' => [
                'client_id' => env('FURIMADECK_MF_CLIENT_ID'),
                'client_secret' => env('FURIMADECK_MF_CLIENT_SECRET'),
                'redirect_uri' => env('FURIMADECK_MF_REDIRECT_URI'),
                'authorize_url' => 'https://api.biz.moneyforward.com/authorize',
                'token_url' => 'https://api.biz.moneyforward.com/token',
                'api_base' => env('FURIMADECK_MF_API_BASE', 'https://api.biz.moneyforward.com'),
                'accounting_api_base' => env('FURIMADECK_MF_ACCOUNTING_API_BASE', 'https://api-accounting.moneyforward.com'),
                'scope' => env('FURIMADECK_MF_SCOPE', 'mfc/admin/tenant.read mfc/accounting/offices.read mfc/accounting/accounts.read mfc/accounting/taxes.read mfc/accounting/journal.write'),
            ],
        ],
        'freee_sync' => [
            'api_base' => env('FURIMADECK_FREEE_API_BASE', 'https://api.freee.co.jp'),
            'company_id' => env('FURIMADECK_FREEE_COMPANY_ID', ''),
            'tax_code' => env('FURIMADECK_FREEE_TAX_CODE', ''),
            'account_item_ids' => [
                'settlement' => env('FURIMADECK_FREEE_SETTLEMENT_ACCOUNT_ITEM_ID', ''),
                'sales' => env('FURIMADECK_FREEE_SALES_ACCOUNT_ITEM_ID', ''),
                'fees' => env('FURIMADECK_FREEE_FEES_ACCOUNT_ITEM_ID', ''),
                'cost' => env('FURIMADECK_FREEE_COST_ACCOUNT_ITEM_ID', ''),
                'inventory' => env('FURIMADECK_FREEE_INVENTORY_ACCOUNT_ITEM_ID', ''),
            ],
        ],
        'export_accounts' => [
            'settlement' => env('FURIMADECK_ACCOUNTING_SETTLEMENT_ACCOUNT', ''),
            'sales' => env('FURIMADECK_ACCOUNTING_SALES_ACCOUNT', ''),
            'fees' => env('FURIMADECK_ACCOUNTING_FEES_ACCOUNT', ''),
            'cost' => env('FURIMADECK_ACCOUNTING_COST_ACCOUNT', ''),
            'inventory' => env('FURIMADECK_ACCOUNTING_INVENTORY_ACCOUNT', ''),
        ],
    ],
    'billing' => [
        'monthly_price_jpy' => (int) env('FURIMADECK_MONTHLY_PRICE_JPY', env('FURIMADECK_STRIPE_PREMIUM_AMOUNT', env('STRIPE_SUBSCRIPTION_AMOUNT', env('STRIPE_PREMIUM_AMOUNT', 980)))),
        'trial_period_days' => env('FURIMADECK_TRIAL_PERIOD_DAYS', 7),
        'stripe_api_base' => env('FURIMADECK_STRIPE_API_BASE', 'https://api.stripe.com/v1'),
        'stripe_secret' => env('FURIMADECK_STRIPE_SECRET', env('STRIPE_SECRET', env('STRIPE_SECRET_KEY'))),
        'stripe_webhook_secret' => env('FURIMADECK_STRIPE_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),
        'stripe_price_id' => env('FURIMADECK_STRIPE_PRICE_ID', env('FURIMADECK_STRIPE_PREMIUM_PRICE_ID', env('STRIPE_SUBSCRIPTION_PRICE_ID', env('STRIPE_PREMIUM_PRICE_ID')))),
        'stripe_portal_configuration_id' => env('FURIMADECK_STRIPE_PORTAL_CONFIGURATION_ID'),
        'stripe_3ds_request' => env('FURIMADECK_STRIPE_3DS_REQUEST', 'automatic'),
    ],
    'ai' => [
        'provider' => env('FURIMADECK_AI_PROVIDER', 'openai'),
        'model' => env('FURIMADECK_AI_MODEL', ''),
        'monthly_cost_limit_jpy' => env('FURIMADECK_AI_MONTHLY_COST_LIMIT_JPY', 50),
        'confidence_ready_threshold' => env('FURIMADECK_AI_CONFIDENCE_READY_THRESHOLD', 0.9),
        'confidence_review_threshold' => env('FURIMADECK_AI_CONFIDENCE_REVIEW_THRESHOLD', 0.7),
    ],
    'inventory' => [
        // 商品登録日から在庫が残っている日数。ダッシュボード通知の基準でも使う。
        'long_term_days' => env('FURIMADECK_LONG_TERM_INVENTORY_DAYS', 30),
        'max_filter_days' => env('FURIMADECK_MAX_INVENTORY_AGE_FILTER_DAYS', 3650),
        'product_list_per_page' => env('FURIMADECK_PRODUCT_LIST_PER_PAGE', 100),
    ],
];
