<?php

return [
    'extension_id' => env('FURIMADECK_CHROME_EXTENSION_ID', ''),
    'pairing_lifetime_minutes' => 10,
    'access_token_lifetime_days' => 7,
    'product_page_size' => 20,
    'activity_page_size' => 20,
    'cors_preflight_max_age_seconds' => 600,
    'platforms' => [
        'mercari' => ['enabled' => (bool) env('FURIMADECK_EXTENSION_PLATFORM_MERCARI_ENABLED', true), 'name' => 'メルカリ', 'listing_url' => 'https://jp.mercari.com/sell/create'],
        'rakuma' => ['enabled' => (bool) env('FURIMADECK_EXTENSION_PLATFORM_RAKUMA_ENABLED', true), 'name' => 'ラクマ', 'listing_url' => 'https://fril.jp/item/new'],
        'yahoo_flea_market' => ['enabled' => (bool) env('FURIMADECK_EXTENSION_PLATFORM_YAHOO_FLEA_MARKET_ENABLED', true), 'name' => 'Yahoo!フリマ', 'listing_url' => 'https://paypayfleamarket-sec.yahoo.co.jp/item/add?from=sellTop'],
        'yahoo_auctions' => ['enabled' => (bool) env('FURIMADECK_EXTENSION_PLATFORM_YAHOO_AUCTIONS_ENABLED', true), 'name' => 'Yahoo!オークション', 'listing_url' => 'https://auctions.yahoo.co.jp/sell/jp/show/submit?category=0'],
        'mercari_shops' => ['enabled' => (bool) env('FURIMADECK_EXTENSION_PLATFORM_MERCARI_SHOPS_ENABLED', true), 'name' => 'メルカリShops'],
    ],
    'rate_limits' => [
        'pairing_start_per_minute' => 12,
        'pairing_complete_per_minute' => 12,
        'device_reads_per_minute' => 60,
        'image_reads_per_minute' => 120,
        'device_revokes_per_minute' => 30,
        'listing_writes_per_minute' => 10,
        'template_writes_per_minute' => 10,
        'exports_per_minute' => 5,
    ],
];
