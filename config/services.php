<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL') ? rtrim(env('APP_URL'), '/').'/auth/google/callback' : null),
        'direct_connection' => env('GOOGLE_HTTP_DIRECT_CONNECTION', false),
    ],

    'stripe' => [
        'api_base' => env('FURIMADECK_STRIPE_API_BASE', 'https://api.stripe.com/v1'),
        'secret' => env('FURIMADECK_STRIPE_SECRET'),
        'webhook_secret' => env('FURIMADECK_STRIPE_WEBHOOK_SECRET'),
        'subscription_price_id' => env('FURIMADECK_STRIPE_PRICE_ID'),
        'subscription_amount' => (int) env('FURIMADECK_MONTHLY_PRICE_JPY', 980),
        'subscription_currency' => env('FURIMADECK_STRIPE_CURRENCY', 'jpy'),
        'checkout_locale' => env('FURIMADECK_STRIPE_CHECKOUT_LOCALE', 'ja'),
        'trial_period_days' => (int) env('FURIMADECK_TRIAL_PERIOD_DAYS', 7),
        'portal_configuration_id' => env('FURIMADECK_STRIPE_PORTAL_CONFIGURATION_ID'),
        'subscription_product_name' => env('FURIMADECK_STRIPE_PRODUCT_NAME', 'FurimaDeck Premium'),
        'subscription_product_description' => env('FURIMADECK_STRIPE_PRODUCT_DESCRIPTION', 'FurimaDeck paid subscription.'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
