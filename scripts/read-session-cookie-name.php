<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing')) {
    fwrite(STDERR, "Session cookie introspection is restricted to the testing environment.\n");
    exit(1);
}

$cookieName = config('session.cookie');
if (! is_string($cookieName) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $cookieName)) {
    fwrite(STDERR, "Configured session cookie name is unavailable or invalid.\n");
    exit(1);
}

fwrite(STDOUT, $cookieName."\n");
