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
use App\Http\Controllers\FurimaDeckAccountingConnectionController;
use App\Http\Controllers\FurimaDeckAccountingController;
use App\Http\Controllers\FurimaDeckAccountingExportController;
use App\Http\Controllers\FurimaDeckAccountingSyncController;
use App\Http\Controllers\FurimaDeckAccountingVendorExportController;
use App\Http\Controllers\FurimaDeckAccountingWorkflowController;
use App\Http\Controllers\FurimaDeckActivityController;
use App\Http\Controllers\FurimaDeckBillingController;
use App\Http\Controllers\FurimaDeckDashboardController;
use App\Http\Controllers\FurimaDeckExportController;
use App\Http\Controllers\FurimaDeckExternalSalesImportController;
use App\Http\Controllers\FurimaDeckSaleController;
use App\Http\Controllers\FurimaDeckSalesAnalysisController;
use App\Http\Controllers\FurimaDeckSalesImprovementController;
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
        Route::get('/furimadeck-account/data/delete/confirm', [FurimaDeckAccountController::class, 'confirmDataDeletion'])->name('furimadeck-account.data-delete.confirm');
        Route::get('/furimadeck-account/data/delete-month/confirm', [FurimaDeckAccountController::class, 'confirmMonthlyDataDeletion'])->name('furimadeck-account.monthly-data-delete.confirm');
        Route::delete('/furimadeck-account/data/delete-month', [FurimaDeckAccountController::class, 'destroyMonthlyData'])->name('furimadeck-account.monthly-data-delete');
        Route::delete('/furimadeck-account/data', [FurimaDeckAccountController::class, 'destroyData'])->name('furimadeck-account.data-delete');
        Route::patch('/furimadeck-account', [FurimaDeckAccountController::class, 'update'])->name('furimadeck-account.update');
        Route::delete('/furimadeck-account', [FurimaDeckAccountController::class, 'destroy'])->name('furimadeck-account.destroy');
        Route::get('/furimadeck-activity', [FurimaDeckActivityController::class, 'index'])->name('furimadeck-activity.index');
        Route::middleware('furimadeck.premium')->group(function () {
            Route::get('/furimadeck-export/products', [FurimaDeckExportController::class, 'products'])->name('furimadeck-export.products');
            Route::get('/furimadeck-export/products/backup', [FurimaDeckExportController::class, 'productBackup'])->name('furimadeck-export.products-backup');
            Route::get('/furimadeck-export/listings', [FurimaDeckExportController::class, 'listings'])->name('furimadeck-export.listings');
            Route::get('/furimadeck-export/sales', [FurimaDeckExportController::class, 'sales'])->name('furimadeck-export.sales');
            Route::get('/furimadeck-export/sales/spec', [FurimaDeckExportController::class, 'salesSpec'])->name('furimadeck-export.sales-spec');
            Route::get('/furimadeck-export/backup/spec', [FurimaDeckExportController::class, 'backupSpec'])->name('furimadeck-export.backup-spec');
            Route::get('/furimadeck-export/restore/spec', [FurimaDeckExportController::class, 'restoreSpec'])->name('furimadeck-export.restore-spec');
            Route::get('/furimadeck-export/active-listings', [FurimaDeckExportController::class, 'activeListingsSpec'])->name('furimadeck-export.active-listings');
            Route::get('/products/import', [ProductCsvImportController::class, 'create'])->name('products.imports.create');
            Route::post('/products/import/preview', [ProductCsvImportController::class, 'preview'])->name('products.imports.preview');
            Route::post('/products/import/restore-preview', [ProductCsvImportController::class, 'restorePreview'])->name('products.imports.restore-preview');
            Route::get('/products/import/{importBatch}', [ProductCsvImportController::class, 'show'])->name('products.imports.show');
            Route::post('/products/import/{importBatch}/commit', [ProductCsvImportController::class, 'commit'])->name('products.imports.commit');
            Route::get('/furimadeck-sales', [FurimaDeckSaleController::class, 'index'])->name('furimadeck-sales.index');
            Route::get('/furimadeck-sales/create', [FurimaDeckSaleController::class, 'create'])->name('furimadeck-sales.create');
            Route::post('/furimadeck-sales', [FurimaDeckSaleController::class, 'store'])->name('furimadeck-sales.store');
            Route::post('/furimadeck-sales/{sale}/cancel', [FurimaDeckSaleController::class, 'cancel'])->name('furimadeck-sales.cancel');
            Route::patch('/furimadeck-sales/{sale}/status', [FurimaDeckSaleController::class, 'advanceStatus'])->name('furimadeck-sales.status');
            Route::post('/furimadeck-sales/{sale}/return', [FurimaDeckSaleController::class, 'returnSale'])->name('furimadeck-sales.return');
            Route::post('/furimadeck-sales/import/yahoo-auctions', [FurimaDeckExternalSalesImportController::class, 'yahooAuctions'])->name('furimadeck-sales.import.yahoo-auctions');
            Route::post('/furimadeck-sales/import/yahoo-auctions/preview', [FurimaDeckExternalSalesImportController::class, 'previewYahoo'])->name('furimadeck-sales.import.yahoo-auctions-preview');
            Route::post('/furimadeck-sales/import/mercari-shops', [FurimaDeckExternalSalesImportController::class, 'mercariShops'])->name('furimadeck-sales.import.mercari-shops');
            Route::post('/furimadeck-sales/import/mercari-shops/preview', [FurimaDeckExternalSalesImportController::class, 'previewMercari'])->name('furimadeck-sales.import.mercari-shops-preview');
            Route::get('/furimadeck-analytics', [FurimaDeckSalesAnalysisController::class, 'index'])->name('furimadeck-analytics.index');
            Route::get('/furimadeck-analytics/categories', [FurimaDeckSalesAnalysisController::class, 'categories'])->name('furimadeck-analytics.categories');
            Route::get('/furimadeck-analytics/cross', [FurimaDeckSalesAnalysisController::class, 'cross'])->name('furimadeck-analytics.cross');
            Route::get('/furimadeck-analytics/advanced', [FurimaDeckSalesAnalysisController::class, 'advanced'])->name('furimadeck-analytics.advanced');
            Route::get('/furimadeck-analytics/suitability', [FurimaDeckSalesAnalysisController::class, 'marketplaceSuitability'])->name('furimadeck-analytics.suitability');
            Route::get('/furimadeck-improvement', [FurimaDeckSalesImprovementController::class, 'index'])->name('furimadeck-improvement.index');
            Route::get('/furimadeck-accounting', [FurimaDeckAccountingController::class, 'index'])->name('furimadeck-accounting.index');
            Route::post('/furimadeck-accounting/sync', [FurimaDeckAccountingExportController::class, 'sync'])->name('furimadeck-accounting.sync');
            Route::post('/furimadeck-accounting/review', [FurimaDeckAccountingWorkflowController::class, 'review'])->name('furimadeck-accounting.review');
            Route::post('/furimadeck-accounting/confirm', [FurimaDeckAccountingWorkflowController::class, 'confirm'])->name('furimadeck-accounting.confirm');
            Route::post('/furimadeck-accounting/{accountingEntry}/send/freee', [FurimaDeckAccountingSyncController::class, 'freee'])->name('furimadeck-accounting.send.freee');
            Route::post('/furimadeck-accounting/{accountingEntry}/send/money-forward', [FurimaDeckAccountingSyncController::class, 'moneyForward'])->name('furimadeck-accounting.send.money-forward');
            Route::get('/furimadeck-accounting.csv', [FurimaDeckAccountingExportController::class, 'csv'])->name('furimadeck-accounting.csv');
            Route::get('/furimadeck-accounting/freee.csv', [FurimaDeckAccountingVendorExportController::class, 'freee'])->name('furimadeck-accounting.freee');
            Route::get('/furimadeck-accounting/connect/{provider}', [FurimaDeckAccountingConnectionController::class, 'connect'])->name('furimadeck-accounting.connect');
            Route::get('/furimadeck-accounting/callback/{provider}', [FurimaDeckAccountingConnectionController::class, 'callback'])->name('furimadeck-accounting.callback');
            Route::get('/furimadeck-accounting/freee/setup', [FurimaDeckAccountingConnectionController::class, 'freeeSetup'])->name('furimadeck-accounting.freee.setup');
            Route::post('/furimadeck-accounting/freee/setup', [FurimaDeckAccountingConnectionController::class, 'saveFreeeSetup'])->name('furimadeck-accounting.freee.setup.save');
            Route::get('/furimadeck-accounting/money-forward/setup', [FurimaDeckAccountingConnectionController::class, 'moneyForwardSetup'])->name('furimadeck-accounting.money-forward.setup');
            Route::post('/furimadeck-accounting/money-forward/setup', [FurimaDeckAccountingConnectionController::class, 'saveMoneyForwardSetup'])->name('furimadeck-accounting.money-forward.setup.save');
            Route::delete('/furimadeck-accounting/connect/{provider}', [FurimaDeckAccountingConnectionController::class, 'disconnect'])->name('furimadeck-accounting.disconnect');
            Route::get('/furimadeck-accounting/money-forward.csv', [FurimaDeckAccountingVendorExportController::class, 'moneyForward'])->name('furimadeck-accounting.money-forward');
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
