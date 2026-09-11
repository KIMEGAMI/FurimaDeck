<?php

return [
    'user_enabled' => env('FURIMADECK_DEMO_USER_ENABLED', env('DEMO_USER_ENABLED', true)),
    'user_email' => env('FURIMADECK_DEMO_USER_EMAIL') ?: env('DEMO_USER_EMAIL', env('DEMO_EMAIL')),
    'user_password' => env('FURIMADECK_DEMO_USER_PASSWORD') ?: env('DEMO_USER_PASSWORD', env('DEMO_PASSWORD')),
];
