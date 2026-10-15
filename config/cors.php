<?php

$extensionId = (string) env('FURIMADECK_CHROME_EXTENSION_ID', '');
$extensionIdIsValid = preg_match('/^[a-p]{32}$/', $extensionId) === 1;
$productionWithoutExtensionId = env('APP_ENV', 'production') === 'production' && ! $extensionIdIsValid;
$extensionOriginPattern = $extensionIdIsValid
    ? '/\Achrome-extension:\/\/'.preg_quote($extensionId, '/').'\z/'
    : '/\Achrome-extension:\/\/[a-p]{32}\z/';

return [
    'paths' => ['api/extension/v1/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => $productionWithoutExtensionId ? [] : [$extensionOriginPattern],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],
    'exposed_headers' => [],
    'max_age' => (int) config('chrome_extension.cors_preflight_max_age_seconds'),
    'supports_credentials' => false,
];
