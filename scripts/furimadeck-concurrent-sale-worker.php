<?php

declare(strict_types=1);

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use App\Services\SaleLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if ($argc !== 7
    || filter_var($argv[1] ?? null, FILTER_VALIDATE_INT) === false
    || filter_var($argv[2] ?? null, FILTER_VALIDATE_INT) === false
    || filter_var($argv[3] ?? null, FILTER_VALIDATE_INT) === false
    || preg_match('/\A[a-f0-9]{32}\z/', $argv[4] ?? '') !== 1
    || ! in_array($argv[5] ?? '', ['first', 'second'], true)) {
    exit(64);
}

$projectStorageRoot = realpath(dirname(__DIR__).DIRECTORY_SEPARATOR.'storage');
$runDirectory = realpath($argv[6]);
if ($projectStorageRoot === false
    || $runDirectory === false
    || ! str_starts_with(strtolower($runDirectory.DIRECTORY_SEPARATOR), strtolower($projectStorageRoot.DIRECTORY_SEPARATOR))
    || basename($runDirectory) !== 'furimadeck-sale-concurrency-'.$argv[4]) {
    exit(65);
}

try {
    require dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
    $app = require dirname(__DIR__).DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::purge('furimadeck');
    DB::setDefaultConnection('furimadeck');
    $readyPath = $runDirectory.DIRECTORY_SEPARATOR.$argv[5].'.ready';
    if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
        exit(66);
    }

    $startPath = $runDirectory.DIRECTORY_SEPARATOR.'start';
    $deadline = microtime(true) + 30;
    while (! is_file($startPath)) {
        if (microtime(true) >= $deadline) {
            echo json_encode(['status' => 'timeout'], JSON_THROW_ON_ERROR);
            exit(67);
        }
        usleep(10_000);
    }

    $user = User::query()->findOrFail((int) $argv[1]);
    $product = Product::query()->whereKey((int) $argv[2])->where('user_id', $user->id)->firstOrFail();
    Marketplace::query()->whereKey((int) $argv[3])->firstOrFail();

    app(SaleLifecycleService::class)->record($user, [
        'product_id' => $product->id,
        'marketplace_id' => (int) $argv[3],
        'quantity' => Product::UNITS_PER_PRODUCT_ID,
        'sold_at' => now()->toDateTimeString(),
        'sold_price' => 3000,
        'cost_basis' => 1000,
        'sales_fee' => 0,
        'shipping_fee' => 0,
        'purchase_shipping_cost' => 0,
        'packing_cost' => 0,
        'repair_cost' => 0,
        'cleaning_cost' => 0,
        'other_expense' => 0,
    ]);

    echo json_encode(['status' => 'sold'], JSON_THROW_ON_ERROR);
} catch (RuntimeException $exception) {
    $errorInfo = property_exists($exception, 'errorInfo') && is_array($exception->errorInfo)
        ? $exception->errorInfo
        : [];
    $driverErrorCode = isset($errorInfo[1]) && is_numeric($errorInfo[1]) ? (int) $errorInfo[1] : null;
    echo json_encode([
        'status' => 'rejected',
        'error_class' => $exception::class,
        'driver_error_code' => $driverErrorCode,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'error_class' => $exception::class], JSON_THROW_ON_ERROR);
    exit(1);
}
