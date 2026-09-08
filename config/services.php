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
    ],

    'stripe' => [
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com/v1'),
        'secret' => env('STRIPE_SECRET', env('STRIPE_SECRET_KEY')),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'subscription_price_id' => env('STRIPE_SUBSCRIPTION_PRICE_ID', env('STRIPE_PREMIUM_PRICE_ID')),
        'subscription_amount' => (int) env('STRIPE_SUBSCRIPTION_AMOUNT', env('STRIPE_PREMIUM_AMOUNT', 480)),
        'subscription_currency' => env('STRIPE_SUBSCRIPTION_CURRENCY', env('STRIPE_PREMIUM_CURRENCY', 'jpy')),
        'checkout_locale' => env('STRIPE_CHECKOUT_LOCALE', 'ja'),
        'trial_period_days' => (int) env('STRIPE_TRIAL_PERIOD_DAYS', 7),
        'portal_configuration_id' => env('STRIPE_PORTAL_CONFIGURATION_ID'),
        'subscription_product_name' => env('STRIPE_PREMIUM_PRODUCT_NAME', 'FurimaDeck Premium'),
        'subscription_product_description' => env('STRIPE_PREMIUM_PRODUCT_DESCRIPTION', 'FurimaDeck paid subscription.'),
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
