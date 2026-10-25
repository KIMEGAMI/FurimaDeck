<?php

use App\Http\Controllers\ChromeExtensionActivityController;
use App\Http\Controllers\ChromeExtensionExportController;
use App\Http\Controllers\ChromeExtensionImageController;
use App\Http\Controllers\ChromeExtensionPairingController;
use App\Http\Controllers\ChromeExtensionProductController;
use App\Http\Controllers\ChromeExtensionTemplateController;
use App\Http\Middleware\AuthenticateChromeExtension;
use App\Http\Middleware\ChromeExtensionCors;
use App\Http\Middleware\EnsureFurimaDeckProductManagementEnabled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('extension/v1')
    ->middleware([ChromeExtensionCors::class, EnsureFurimaDeckProductManagementEnabled::class])
    ->group(function (): void {
        Route::options('/{path?}', fn () => response('', 204))->where('path', '.*');
        Route::post('/pairing/start', [ChromeExtensionPairingController::class, 'start'])->middleware('throttle:extension-pairing-start');
        Route::post('/pairing/complete', [ChromeExtensionPairingController::class, 'complete'])->middleware('throttle:extension-pairing-complete');

        Route::post('/pairing/revoke', [ChromeExtensionPairingController::class, 'revoke'])
            ->middleware([AuthenticateChromeExtension::class, 'throttle:extension-device-revoke']);
        Route::get('/me', fn (Request $request) => response()->json([
            'user' => ['name' => $request->attributes->get('chrome_extension_user')->name],
            'scopes' => $request->attributes->get('chrome_extension_device')->scopes,
        ]))->middleware([AuthenticateChromeExtension::class, 'throttle:extension-device-read']);
        Route::get('/activity', [ChromeExtensionActivityController::class, 'index'])
            ->middleware([AuthenticateChromeExtension::class.':activity:read', 'throttle:extension-device-read']);
        Route::get('/products', [ChromeExtensionProductController::class, 'index'])
            ->middleware([AuthenticateChromeExtension::class.':products:read', 'throttle:extension-device-read']);
        Route::get('/products/{product}/draft', [ChromeExtensionProductController::class, 'draft'])
            ->middleware([AuthenticateChromeExtension::class.':drafts:read', 'throttle:extension-device-read']);
        Route::post('/products/{product}/listing-records', [ChromeExtensionProductController::class, 'recordListing'])
            ->middleware([AuthenticateChromeExtension::class.':listings:write', 'throttle:extension-listing-write']);
        Route::get('/templates', [ChromeExtensionTemplateController::class, 'index'])
            ->middleware([AuthenticateChromeExtension::class.':templates:read', 'throttle:extension-device-read']);
        Route::post('/templates', [ChromeExtensionTemplateController::class, 'store'])
            ->middleware([AuthenticateChromeExtension::class.':templates:write', 'throttle:extension-template-write']);
        Route::patch('/templates/{template}', [ChromeExtensionTemplateController::class, 'update'])
            ->middleware([AuthenticateChromeExtension::class.':templates:write', 'throttle:extension-template-write']);
        Route::delete('/templates/{template}', [ChromeExtensionTemplateController::class, 'destroy'])
            ->middleware([AuthenticateChromeExtension::class.':templates:write', 'throttle:extension-template-write']);
        Route::get('/exports/products.csv', [ChromeExtensionExportController::class, 'products'])
            ->middleware([AuthenticateChromeExtension::class.':exports:read', 'throttle:extension-export']);
        Route::get('/exports/listings.csv', [ChromeExtensionExportController::class, 'listings'])
            ->middleware([AuthenticateChromeExtension::class.':exports:read', 'throttle:extension-export']);
        Route::get('/exports/sales.csv', [ChromeExtensionExportController::class, 'sales'])
            ->middleware([AuthenticateChromeExtension::class.':exports:read', 'throttle:extension-export']);
        Route::get('/products/{product}/images/{image}', [ChromeExtensionImageController::class, 'show'])
            ->middleware([AuthenticateChromeExtension::class.':products:read', 'throttle:extension-image-read'])
            ->name('chrome-extension.products.image');
    });
