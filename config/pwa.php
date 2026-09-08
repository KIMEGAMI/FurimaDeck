<?php

return [
    'name' => env('PWA_NAME', env('SEO_SITE_NAME', 'FurimaDeck')),
    'short_name' => env('PWA_SHORT_NAME', 'FurimaDeck'),
    'description' => env('PWA_DESCRIPTION', env('SEO_DESCRIPTION', 'フリマ販売の収益管理SaaS')),
    'theme_color' => env('PWA_THEME_COLOR', '#0f172a'),
    'background_color' => env('PWA_BACKGROUND_COLOR', '#f8fafc'),
    'display' => env('PWA_DISPLAY', 'standalone'),
    'start_url' => env('PWA_START_URL', '/'),
    'scope' => env('PWA_SCOPE', '/'),
    'cache_name' => env('PWA_CACHE_NAME', 'furimadeck-pwa-v1'),
];
