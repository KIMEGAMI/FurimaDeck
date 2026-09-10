<?php

use App\Http\Controllers\Admin\BulkMailController;
use App\Http\Controllers\Admin\GrowthController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AuctionItemController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\MaintenanceLoginController;
use App\Http\Controllers\CategorySalesController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FurimaDeckAccountController;
use App\Http\Controllers\FurimaDeckActivityController;
use App\Http\Controllers\FurimaDeckBillingController;
use App\Http\Controllers\FurimaDeckDashboardController;
use App\Http\Controllers\FurimaDeckExportController;
use App\Http\Controllers\FurimaDeckSaleController;
use App\Http\Controllers\FurimaDeckStripeWebhookController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\MarketingPageController;
use App\Http\Controllers\NoticeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductCsvImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SeoController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SubscriptionCheckoutController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionPortalController;
use App\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.redirect');

Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');

Route::post('/stripe/webhook', StripeWebhookController::class)->middleware('furupro.legacy')->name('stripe.webhook');
Route::post('/furimadeck/stripe/webhook', FurimaDeckStripeWebhookController::class)->name('furimadeck.stripe.webhook');

Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/service-worker.js', [PwaController::class, 'serviceWorker'])->name('pwa.service-worker');

Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('seo.sitemap');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('seo.robots');
Route::get('/llms.txt', [SeoController::class, 'llms'])->name('seo.llms');

Route::get('/terms', [LegalPageController::class, 'terms'])->name('legal.terms');
Route::get('/privacy', [LegalPageController::class, 'privacy'])->name('legal.privacy');
Route::get('/commercial-transactions', [LegalPageController::class, 'commercial'])->name('legal.commercial');
Route::get('/faq', [LegalPageController::class, 'faq'])->name('legal.faq');
Route::get('/contact', [ContactController::class, 'create'])->name('legal.contact');
Route::post('/contact', [ContactController::class, 'store'])->name('legal.contact.store');

Route::get('/features', [MarketingPageController::class, 'features'])->name('marketing.features');
Route::get('/pricing', [MarketingPageController::class, 'pricing'])->name('marketing.pricing');
Route::get('/use-cases', [MarketingPageController::class, 'useCases'])->name('marketing.use-cases');

Route::get('/maintenance-login', MaintenanceLoginController::class)->middleware('furupro.legacy')->name('maintenance.login');

Route::get('/', HomeController::class)->name('home');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'furupro.legacy'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('furimadeck.products')->group(function () {
        Route::get('/furimadeck-dashboard', FurimaDeckDashboardController::class)->name('furimadeck-dashboard');
        Route::get('/furimadeck-billing', [FurimaDeckBillingController::class, 'index'])->name('furimadeck-billing.index');
        Route::post('/furimadeck-billing/checkout', [FurimaDeckBillingController::class, 'checkout'])->name('furimadeck-billing.checkout');
        Route::post('/furimadeck-billing/portal', [FurimaDeckBillingController::class, 'portal'])->name('furimadeck-billing.portal');
        Route::get('/furimadeck-billing/success', [FurimaDeckBillingController::class, 'success'])->name('furimadeck-billing.success');
        Route::get('/furimadeck-account', [FurimaDeckAccountController::class, 'edit'])->name('furimadeck-account.edit');
        Route::patch('/furimadeck-account', [FurimaDeckAccountController::class, 'update'])->name('furimadeck-account.update');
        Route::delete('/furimadeck-account', [FurimaDeckAccountController::class, 'destroy'])->name('furimadeck-account.destroy');
        Route::get('/furimadeck-activity', [FurimaDeckActivityController::class, 'index'])->name('furimadeck-activity.index');
        Route::middleware('furimadeck.premium')->group(function () {
            Route::get('/furimadeck-export/products', [FurimaDeckExportController::class, 'products'])->name('furimadeck-export.products');
            Route::get('/furimadeck-export/listings', [FurimaDeckExportController::class, 'listings'])->name('furimadeck-export.listings');
            Route::get('/furimadeck-export/sales', [FurimaDeckExportController::class, 'sales'])->name('furimadeck-export.sales');
            Route::get('/products/import', [ProductCsvImportController::class, 'create'])->name('products.imports.create');
            Route::post('/products/import/preview', [ProductCsvImportController::class, 'preview'])->name('products.imports.preview');
            Route::get('/products/import/{importBatch}', [ProductCsvImportController::class, 'show'])->name('products.imports.show');
            Route::post('/products/import/{importBatch}/commit', [ProductCsvImportController::class, 'commit'])->name('products.imports.commit');
            Route::get('/furimadeck-sales', [FurimaDeckSaleController::class, 'index'])->name('furimadeck-sales.index');
            Route::get('/furimadeck-sales/create', [FurimaDeckSaleController::class, 'create'])->name('furimadeck-sales.create');
            Route::post('/furimadeck-sales', [FurimaDeckSaleController::class, 'store'])->name('furimadeck-sales.store');
            Route::post('/furimadeck-sales/{sale}/cancel', [FurimaDeckSaleController::class, 'cancel'])->name('furimadeck-sales.cancel');
            Route::patch('/furimadeck-sales/{sale}/status', [FurimaDeckSaleController::class, 'advanceStatus'])->name('furimadeck-sales.status');
            Route::post('/furimadeck-sales/{sale}/return', [FurimaDeckSaleController::class, 'returnSale'])->name('furimadeck-sales.return');
        });
        Route::get('/products/category-attributes', [ProductController::class, 'categoryAttributes'])
            ->name('products.category-attributes');
        Route::get('/products/{product}/images/{image}/{variant}', [ProductController::class, 'showImage'])
            ->whereIn('variant', ['original', 'thumbnail'])
            ->name('products.images.show');
        Route::patch('/products/{product}/images/order', [ProductController::class, 'reorderImages'])->name('products.images.order');
        Route::delete('/products/{product}/images/{image}', [ProductController::class, 'destroyImage'])->name('products.images.destroy');
        Route::resource('products', ProductController::class)->except('show');
        Route::resource('listings', ListingController::class)->except('show');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
    });

    Route::middleware('furupro.legacy')->group(function () {

        Route::get('/admin/maintenance', [MaintenanceController::class, 'index'])
            ->name('admin.maintenance.index');

        Route::patch('/admin/maintenance', [MaintenanceController::class, 'update'])
            ->name('admin.maintenance.update');

        Route::post('/admin/notices', [AdminNoticeController::class, 'store'])
            ->name('admin.notices.store');

        Route::get('/admin/users', [AdminUserController::class, 'index'])
            ->name('admin.users.index');

        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])
            ->name('admin.users.destroy');

        Route::get('/admin/bulk-mail', [BulkMailController::class, 'index'])
            ->name('admin.bulk-mail.index');

        Route::post('/admin/bulk-mail', [BulkMailController::class, 'store'])
            ->name('admin.bulk-mail.store');

        Route::get('/admin/growth', [GrowthController::class, 'index'])
            ->name('admin.growth.index');

        Route::patch('/admin/growth/inquiries/{contactInquiry}', [GrowthController::class, 'handleInquiry'])
            ->name('admin.growth.inquiries.handle');

        Route::get('/notices', [NoticeController::class, 'index'])
            ->name('notices.index');

        Route::get('/notices/{notice}', [NoticeController::class, 'show'])
            ->name('notices.show');

        Route::get('/auction-items/csv-import', [AuctionItemController::class, 'csvImport'])
            ->middleware('premium')
            ->name('auction-items.csv-import');

        Route::post('/auction-items/import', [AuctionItemController::class, 'importCsv'])
            ->middleware('premium')
            ->name('auction-items.import');

        Route::post('/auction-items/import/yahoo-auctions', [AuctionItemController::class, 'importYahooAuctionCsv'])
            ->middleware('premium')
            ->name('auction-items.import.yahoo-auctions');

        Route::post('/auction-items/import/mercari-shops', [AuctionItemController::class, 'importMercariShopsCsv'])
            ->middleware('premium')
            ->name('auction-items.import.mercari-shops');

        Route::get('/auction-items/duplicates', [AuctionItemController::class, 'duplicates'])
            ->middleware('premium')
            ->name('auction-items.duplicates');

        Route::delete('/auction-items/duplicates', [AuctionItemController::class, 'deleteDuplicates'])
            ->middleware('premium')
            ->name('auction-items.duplicates.destroy');

        Route::get('/auction-items/delete-all/confirm', [AuctionItemController::class, 'confirmBulkDestroy'])
            ->name('auction-items.bulk-destroy.confirm');

        Route::delete('/auction-items/delete-all', [AuctionItemController::class, 'bulkDestroy'])
            ->name('auction-items.bulk-destroy');

        Route::get('/auction-items/unsold-alerts', [AuctionItemController::class, 'unsoldAlerts'])
            ->name('auction-items.unsold-alerts');

        Route::resource('auction-items', AuctionItemController::class);

        Route::patch('/auction-items/{auctionItem}/sold', [AuctionItemController::class, 'markAsSold'])
            ->name('auction-items.sold');

        Route::patch('/auction-items/{auctionItem}/selling', [AuctionItemController::class, 'markAsSelling'])
            ->name('auction-items.selling');

        Route::get('/sales', [SalesController::class, 'index'])
            ->middleware('premium')
            ->name('sales.index');

        Route::get('/sales/csv', [SalesController::class, 'downloadCsv'])
            ->middleware('premium')
            ->name('sales.csv');

        Route::get('/sales/backup-csv', [SalesController::class, 'downloadBackupCsv'])
            ->middleware('premium')
            ->name('sales.backup-csv');

        Route::get('/sales/restore-csv', [SalesController::class, 'downloadRestoreCsv'])
            ->middleware('premium')
            ->name('sales.restore-csv');

        Route::get('/sales/selling-csv', [SalesController::class, 'downloadSellingCsv'])
            ->middleware('premium')
            ->name('sales.selling-csv');

        Route::get('/category-sales', [CategorySalesController::class, 'index'])
            ->middleware('premium')
            ->name('category-sales.index');

        Route::get('/profile', [ProfileController::class, 'edit'])
            ->name('profile.edit');

        Route::patch('/profile', [ProfileController::class, 'update'])
            ->name('profile.update');

        Route::delete('/profile', [ProfileController::class, 'destroy'])
            ->name('profile.destroy');

        Route::get('/billing', [SubscriptionController::class, 'index'])
            ->name('subscriptions.index');

        Route::get('/premium', fn () => redirect()->route('subscriptions.index'))
            ->name('subscriptions.legacy');

        Route::post('/billing/checkout', SubscriptionCheckoutController::class)
            ->name('subscriptions.checkout');

        Route::post('/billing/portal', [SubscriptionPortalController::class, 'portal'])
            ->name('subscriptions.portal');

        Route::post('/billing/cancel-feedback', [SubscriptionPortalController::class, 'cancelFeedback'])
            ->name('subscriptions.cancel-feedback');

        Route::get('/billing/success', [SubscriptionController::class, 'success'])
            ->name('subscriptions.success');
    });
});

require __DIR__.'/auth.php';
