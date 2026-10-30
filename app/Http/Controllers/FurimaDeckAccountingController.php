<?php

namespace App\Http\Controllers;

use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\AccountingSyncBatch;
use App\Models\AccountingSyncBatchItem;
use App\Services\FurimaDeckAccountingAdjustmentService;
use App\Services\FurimaDeckAccountingApiReleaseGate;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckAccountingSetupReadinessPresenter;
use App\Services\FurimaDeckAccountingVendorCsvService;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use App\Services\FurimaDeckMoneyForwardAccountingSyncService;
use App\Services\FurimaDeckMoneyForwardBusinessPeriodService;
use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use App\Services\FurimaDeckPurchasePaymentSourceReadiness;
use App\Services\FurimaDeckTaxReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class FurimaDeckAccountingController extends Controller
{
    public function index(Request $request, FurimaDeckTaxReadinessService $readiness, FurimaDeckFreeeAccountingSyncService $freeeSync, FurimaDeckMoneyForwardAccountingSyncService $moneyForwardSync, FurimaDeckAccountingEntryService $entryService, FurimaDeckAccountingAdjustmentService $adjustments, FurimaDeckAccountingSetupReadinessPresenter $setupReadiness, FurimaDeckPurchasePaymentSourceReadiness $paymentSourceReadiness, FurimaDeckAccountingVendorCsvService $vendorCsv): View
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'product_query' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('user_id', $request->user()->id)],
        ]);
        $section = $request->query('section', 'overview');
        if (! in_array($section, ['overview', 'purchase-info', 'review', 'send', 'csv', 'connections'], true)) {
            $section = 'overview';
        }
        $pendingSalesMissingPurchaseInfoCount = $section === 'overview'
            ? $request->user()->sales()
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query->whereNull('product_id')
                        ->orWhereHas('product', fn ($productQuery) => $productQuery->where('purchase_info_confirmed', false));
                })
                ->count()
            : 0;
        [$from, $toExclusive] = $this->period($validated);
        $label = isset($validated['from'], $validated['to']) ? $validated['from'].' ～ '.$validated['to'] : $from->year.'年分';
        $accountingEntries = collect();
        $visibleAccountingEntries = collect();
        if (in_array($section, ['review', 'send'], true)) {
            $accountingEntries = $request->user()->accountingEntries()->eligibleForAccounting()
                ->where('transaction_date', '>=', $from->toDateString())
                ->where('transaction_date', '<', $toExclusive->toDateString())
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->with(['providerSyncs', 'product', 'sale.marketplace'])
                ->paginate((int) config('furimadeck.accounting.entries_per_page'), ['*'], 'entries_page')
                ->withQueryString();
            $visibleAccountingEntries = $accountingEntries->getCollection();
        }
        $providerCsvScopeIssues = $visibleAccountingEntries->mapWithKeys(fn (AccountingEntry $entry): array => [
            $entry->id => [
                'freee' => $vendorCsv->providerCsvScopeIssue($entry, 'freee'),
                'money_forward' => $vendorCsv->providerCsvScopeIssue($entry, 'money_forward'),
            ],
        ]);
        $providerCsvCurrentIssues = $visibleAccountingEntries->mapWithKeys(function (AccountingEntry $entry) use ($entryService): array {
            if ($entry->entry_type !== AccountingEntry::TYPE_SALE
                || $entry->source_status !== 'confirmed'
                || $entry->confirmed_at === null) {
                return [];
            }

            try {
                $entryService->assertCurrent($entry);

                return [$entry->id => null];
            } catch (RuntimeException $exception) {
                return [$entry->id => $exception->getMessage()];
            }
        });

        $freeeConnection = $request->user()->accountingConnections()->where('provider', 'freee')->first();
        $moneyForwardConnection = $request->user()->accountingConnections()->where('provider', 'money_forward')->first();
        $freeeSyncMissingConfiguration = $freeeSync->missingConfiguration($freeeConnection);
        $freeeCsvExportReady = $freeeConnection?->status === 'connected' && $freeeSyncMissingConfiguration === [];
        $moneyForwardSyncMissingConfiguration = $moneyForwardSync->missingConfiguration($moneyForwardConnection);
        $moneyForwardEntryMissingConfiguration = $visibleAccountingEntries->mapWithKeys(fn (AccountingEntry $entry): array => [
            $entry->id => $moneyForwardSync->missingConfigurationForDate(
                $moneyForwardConnection,
                $entry->transaction_date?->toDateString() ?? '',
            ),
        ]);
        $moneyForwardEntryMissingConfigurationLabels = $moneyForwardEntryMissingConfiguration->map(
            fn (array $missing): array => $setupReadiness->labels('money_forward', $missing),
        );
        $moneyForwardEntryCsvExportStatus = $visibleAccountingEntries->mapWithKeys(function (AccountingEntry $entry) use ($moneyForwardConnection, $moneyForwardSync): array {
            if ($moneyForwardConnection?->status !== 'connected') {
                return [$entry->id => 'connection'];
            }

            try {
                $businessStatus = app(FurimaDeckMoneyForwardBusinessPeriodService::class)
                    ->statusForDate((array) ($moneyForwardConnection->settings ?? []), $entry->transaction_date?->toDateString() ?? '');
            } catch (RuntimeException) {
                return [$entry->id => 'unconfirmed'];
            }

            if (! in_array($businessStatus, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES, true)
                || $businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED) {
                return [$entry->id => 'unconfirmed'];
            }

            $missingConfiguration = $moneyForwardSync->missingConfigurationForDate(
                $moneyForwardConnection,
                $entry->transaction_date?->toDateString() ?? '',
            );
            if ($missingConfiguration !== []) {
                return [$entry->id => 'configuration'];
            }

            return [$entry->id => $businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT
                ? 'exempt'
                : 'ready'];
        });
        $moneyForwardCsvBulkExportStatus = 'not_applicable';
        $moneyForwardCsvBulkExportMissingConfiguration = [];
        $freeeCsvBulkExportStatus = 'not_applicable';
        $freeeCsvBulkExportIssue = null;
        $moneyForwardCsvBulkExportIssue = null;
        if ($section === 'csv') {
            $bulkExportEntries = $request->user()->accountingEntries()->eligibleForAccounting()
                ->where('entry_type', AccountingEntry::TYPE_SALE)
                ->where('transaction_date', '>=', $from->toDateString())
                ->where('transaction_date', '<', $toExclusive->toDateString())
                ->with(['providerSyncs', 'sale.marketplace'])
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get();

            $freeeCsvBulkExportStatus = $freeeConnection?->status === 'connected' ? 'empty' : 'connection';
            if ($freeeConnection?->status === 'connected') {
                if (! $freeeCsvExportReady) {
                    $freeeCsvBulkExportStatus = 'configuration';
                } elseif ($bulkExportEntries->isNotEmpty()) {
                    $freeeCsvBulkExportIssue = $this->providerCsvBulkExportIssue($bulkExportEntries, 'freee', $vendorCsv, $entryService);
                    $freeeCsvBulkExportStatus = $freeeCsvBulkExportIssue === null ? 'ready' : 'blocked';
                }
            }

            $moneyForwardCsvBulkExportStatus = $moneyForwardConnection?->status === 'connected' ? 'empty' : 'connection';
            if ($moneyForwardConnection?->status === 'connected' && $bulkExportEntries->isNotEmpty()) {
                $moneyForwardCsvBulkExportIssue = $this->providerCsvBulkExportIssue($bulkExportEntries, 'money_forward', $vendorCsv, $entryService);
                if ($moneyForwardCsvBulkExportIssue !== null) {
                    $moneyForwardCsvBulkExportStatus = 'blocked';
                } else {
                    $bulkExportDates = $bulkExportEntries
                        ->map(fn (AccountingEntry $entry): ?string => $entry->transaction_date?->toDateString())
                        ->filter()
                        ->unique()
                        ->values();

                    foreach ($bulkExportDates as $transactionDate) {
                        try {
                            $businessStatus = app(FurimaDeckMoneyForwardBusinessPeriodService::class)
                                ->statusForDate((array) ($moneyForwardConnection->settings ?? []), (string) $transactionDate);
                        } catch (RuntimeException) {
                            $moneyForwardCsvBulkExportStatus = 'unconfirmed';
                            break;
                        }

                        if (! in_array($businessStatus, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES, true)
                            || $businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED) {
                            $moneyForwardCsvBulkExportStatus = 'unconfirmed';
                            break;
                        }

                        $missing = $moneyForwardSync->missingConfigurationForDate($moneyForwardConnection, (string) $transactionDate);
                        if ($missing !== []) {
                            $moneyForwardCsvBulkExportStatus = 'configuration';
                            $moneyForwardCsvBulkExportMissingConfiguration = $missing;
                            break;
                        }

                        $moneyForwardCsvBulkExportStatus = 'ready';
                    }
                }
            }
        }
        $moneyForwardCsvBulkExportMissingConfigurationLabels = $setupReadiness->labels(
            'money_forward',
            $moneyForwardCsvBulkExportMissingConfiguration,
        );
        $moneyForwardBusinessTaxStatusConfirmedByEntry = $visibleAccountingEntries->mapWithKeys(function (AccountingEntry $entry) use ($moneyForwardConnection): array {
            try {
                $status = app(FurimaDeckMoneyForwardBusinessPeriodService::class)
                    ->statusForDate((array) ($moneyForwardConnection?->settings ?? []), $entry->transaction_date?->toDateString() ?? '');

                return [$entry->id => $status !== FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED];
            } catch (RuntimeException) {
                return [$entry->id => false];
            }
        });
        $purchasePaymentSourceReady = $visibleAccountingEntries->mapWithKeys(fn (AccountingEntry $entry): array => [$entry->id => [
            'freee' => $paymentSourceReadiness->isReady($entry, 'freee', $freeeConnection),
            'money_forward' => $paymentSourceReadiness->isReady($entry, 'money_forward', $moneyForwardConnection),
        ]]);
        $purchaseReconciliationIssues = [
            'freee' => $entryService->providerPurchaseReconciliationIssues($visibleAccountingEntries, 'freee'),
            'money_forward' => $entryService->providerPurchaseReconciliationIssues($visibleAccountingEntries, 'money_forward'),
        ];
        $freeeBatchCount = 0;
        $moneyForwardBatchCount = 0;
        if ($section === 'send') {
            foreach (['freee' => $freeeConnection, 'money_forward' => $moneyForwardConnection] as $provider => $connection) {
                $request->user()->accountingEntries()->eligibleForAccounting()
                    ->whereIn('entry_type', [AccountingEntry::TYPE_SALE, AccountingEntry::TYPE_PURCHASE])
                    ->where('source_status', 'confirmed')
                    ->whereNotNull('confirmed_at')
                    ->where('transaction_date', '>=', $from->toDateString())
                    ->where('transaction_date', '<', $toExclusive->toDateString())
                    ->with(['providerSyncs', 'product', 'sale.marketplace'])
                    ->orderBy('id')
                    ->chunkById((int) config('furimadeck.accounting.query_chunk_size'), function ($entries) use ($provider, $connection, $entryService, $paymentSourceReadiness, $moneyForwardSync, &$freeeBatchCount, &$moneyForwardBatchCount): void {
                        $issues = $entryService->providerPurchaseReconciliationIssues($entries, $provider);
                        foreach ($entries as $entry) {
                            if (isset($issues[$entry->id])
                                || ! $paymentSourceReadiness->isReady($entry, $provider, $connection)
                                || ! in_array($entry->providerSyncStatus($provider), ['not_synced', 'failed'], true)) {
                                continue;
                            }

                            if ($provider === 'money_forward'
                                && $moneyForwardSync->missingConfigurationForDate($connection, $entry->transaction_date?->toDateString() ?? '') !== []) {
                                continue;
                            }

                            if ($provider === 'freee') {
                                $freeeBatchCount++;
                            } else {
                                $moneyForwardBatchCount++;
                            }
                        }
                    });
            }
        }
        $purchaseCandidates = collect();
        $purchaseInfoProducts = collect();
        $purchaseInfoSearch = trim((string) ($validated['product_query'] ?? ''));
        $purchaseInfoSupplierId = $validated['supplier_id'] ?? null;
        if ($section === 'purchase-info') {
            $purchaseInfoProducts = $request->user()->products()
                ->with('supplier')
                ->when($purchaseInfoSupplierId !== null, fn ($query) => $query->where('supplier_id', $purchaseInfoSupplierId))
                ->when($purchaseInfoSearch !== '', function ($query) use ($purchaseInfoSearch): void {
                    $query->where(function ($productQuery) use ($purchaseInfoSearch): void {
                        $productQuery->where('internal_sku', 'like', '%'.$purchaseInfoSearch.'%')
                            ->orWhere('product_name', 'like', '%'.$purchaseInfoSearch.'%');
                    });
                })
                ->orderBy('internal_sku')
                ->paginate((int) config('furimadeck.accounting.entries_per_page'), ['*'], 'purchase_page')
                ->withQueryString();
        }
        if ($section === 'review') {
            $purchaseCandidates = $request->user()->products()
                ->whereNotNull('purchase_date')
                ->where('purchase_date', '>=', $from->toDateString())
                ->where('purchase_date', '<', $toExclusive->toDateString())
                ->whereDoesntHave('accountingEntries', fn ($query) => $query->where('entry_type', AccountingEntry::TYPE_PURCHASE))
                ->orderBy('purchase_date')
                ->orderBy('id')
                ->limit(100)
                ->get(['id', 'internal_sku', 'product_name', 'purchase_date', 'purchase_unit_cost', 'purchase_quantity', 'purchase_shipping_cost', 'other_purchase_expense']);
        }
        $recentAccountingBatches = collect();
        $furimadeckSchema = Schema::connection('furimadeck');
        if ($section === 'send'
            && $furimadeckSchema->hasTable((new AccountingSyncBatch)->getTable())
            && $furimadeckSchema->hasTable((new AccountingSyncBatchItem)->getTable())) {
            $recentAccountingBatches = $request->user()->accountingSyncBatches()
                ->whereIn('provider', ['freee', 'money_forward'])
                ->with(['items' => fn ($query) => $query->whereIn('status', ['failed', 'uncertain'])->orderBy('id')])
                ->latest('id')
                ->limit(5)
                ->get();
        }
        $outOfSyncSentEntries = collect();
        $inactiveReconciliationEntries = collect();
        if (in_array($section, ['overview', 'send'], true)) {
            $sentEntriesQuery = $request->user()->accountingEntries()
                ->where('transaction_date', '>=', $from->toDateString())
                ->where('transaction_date', '<', $toExclusive->toDateString())
                ->where(fn ($query) => $query->where('sync_status', 'synced')
                    ->orWhereHas('providerSyncs', fn ($sync) => $sync->whereIn('status', ['synced', AccountingEntrySync::STATUS_CSV_IMPORTED, AccountingEntrySync::STATUS_MANUALLY_RECONCILED])))
                ->with([
                    'product',
                    'sale' => fn ($query) => $query->where('user_id', $request->user()->id)->with(['marketplace', 'product']),
                    'providerSyncs',
                ])
                ->orderBy('transaction_date')
                ->orderBy('id');
            $outOfSyncPerPage = (int) config('furimadeck.accounting.entries_per_page');
            $outOfSyncPage = LengthAwarePaginator::resolveCurrentPage('out_of_sync_page');
            $outOfSyncOffset = ($outOfSyncPage - 1) * $outOfSyncPerPage;
            $outOfSyncItems = collect();
            $outOfSyncCount = 0;
            foreach ($sentEntriesQuery->lazy((int) config('furimadeck.accounting.query_chunk_size')) as $entry) {
                if ($entryService->isCurrent($entry)) {
                    continue;
                }
                if ($outOfSyncCount >= $outOfSyncOffset && $outOfSyncCount < $outOfSyncOffset + $outOfSyncPerPage) {
                    $outOfSyncItems->push($entry);
                }
                $outOfSyncCount++;
            }
            $outOfSyncSentEntries = new LengthAwarePaginator($outOfSyncItems, $outOfSyncCount, $outOfSyncPerPage, $outOfSyncPage, [
                'path' => $request->url(),
                'query' => $request->query(),
                'pageName' => 'out_of_sync_page',
            ]);
            $inactiveReconciliationEntries = $request->user()->accountingEntries()
                ->where('transaction_date', '>=', $from->toDateString())
                ->where('transaction_date', '<', $toExclusive->toDateString())
                ->whereHas('sale', fn ($query) => $query->where('user_id', $request->user()->id)->whereIn('status', ['cancelled', 'returned']))
                ->whereHas('providerSyncs', fn ($query) => $query->whereIn('status', ['queued', 'sending', 'sync_unknown', 'legacy_unknown', 'csv_pending', AccountingEntrySync::STATUS_MANUALLY_RECONCILED])
                    ->orWhere(fn ($inactiveSync) => $inactiveSync->where('status', 'not_synced')
                        ->where('last_error', AccountingEntrySync::VERIFIED_ABSENT_INACTIVE_SALE)))
                ->with([
                    'sale' => fn ($query) => $query->where('user_id', $request->user()->id)->with(['marketplace', 'product' => fn ($productQuery) => $productQuery->where('user_id', $request->user()->id)]),
                    'providerSyncs',
                ])
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->paginate((int) config('furimadeck.accounting.entries_per_page'), ['*'], 'inactive_page')
                ->withQueryString();
        }
        $adjustmentSales = $section === 'review'
            ? $adjustments->queryForPeriod($request->user(), $from, $toExclusive)->paginate((int) config('furimadeck.accounting.entries_per_page'), ['*'], 'adjustments_page')->withQueryString()
            : collect();
        $moneyForwardBusinessTaxPeriods = collect((array) data_get($moneyForwardConnection?->settings, 'business_tax_periods', []));

        return view('furimadeck_accounting.index', [
            'year' => $from->year,
            'accountingSection' => $section,
            'fromInput' => $from->toDateString(),
            'toInput' => $toExclusive->subDay()->toDateString(),
            'report' => in_array($section, ['overview', 'review'], true)
                ? $readiness->reportPeriod($request->user(), $from, $toExclusive, $label)
                : null,
            'pendingSalesMissingPurchaseInfoCount' => $pendingSalesMissingPurchaseInfoCount,
            'accountingEntries' => $accountingEntries,
            'providerCsvScopeIssues' => $providerCsvScopeIssues,
            'providerCsvCurrentIssues' => $providerCsvCurrentIssues,
            'purchaseReconciliationIssues' => $purchaseReconciliationIssues,
            'purchaseCandidates' => $purchaseCandidates,
            'purchaseInfoProducts' => $purchaseInfoProducts,
            'purchaseInfoSearch' => $purchaseInfoSearch,
            'purchaseInfoSupplierId' => $purchaseInfoSupplierId,
            'purchaseInfoSuppliers' => $section === 'purchase-info' ? $request->user()->suppliers()->orderBy('name')->get() : collect(),
            'accountingConnections' => $request->user()->accountingConnections()->get()->keyBy('provider'),
            'freeeSyncMissingConfiguration' => $freeeSyncMissingConfiguration,
            'freeeCsvExportReady' => $freeeCsvExportReady,
            'moneyForwardSyncMissingConfiguration' => $moneyForwardSyncMissingConfiguration,
            'freeeSyncMissingConfigurationLabels' => $setupReadiness->labels('freee', $freeeSyncMissingConfiguration),
            'moneyForwardSyncMissingConfigurationLabels' => $setupReadiness->labels('money_forward', $moneyForwardSyncMissingConfiguration),
            'freeePurchasePaymentAccountCount' => count((array) data_get($freeeConnection?->settings, 'purchase_payment_accounts', [])),
            'moneyForwardPurchasePaymentAccountCount' => count((array) data_get($moneyForwardConnection?->settings, 'purchase_payment_accounts', [])),
            'moneyForwardTaxableBusinessTaxPeriodsCount' => $moneyForwardBusinessTaxPeriods
                ->filter(fn (mixed $period): bool => is_array($period)
                    && ($period['invoice_kind'] ?? null) === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE)
                ->count(),
            'moneyForwardExemptBusinessTaxPeriodsCount' => $moneyForwardBusinessTaxPeriods
                ->filter(fn (mixed $period): bool => is_array($period)
                    && ($period['invoice_kind'] ?? null) === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT)
                ->count(),
            'moneyForwardUnconfirmedBusinessTaxPeriodsCount' => $moneyForwardBusinessTaxPeriods
                ->filter(fn (mixed $period): bool => is_array($period)
                    && ($period['invoice_kind'] ?? null) === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED)
                ->count(),
            'moneyForwardEntryMissingConfiguration' => $moneyForwardEntryMissingConfiguration,
            'moneyForwardEntryMissingConfigurationLabels' => $moneyForwardEntryMissingConfigurationLabels,
            'moneyForwardEntryCsvExportStatus' => $moneyForwardEntryCsvExportStatus,
            'freeeCsvBulkExportStatus' => $freeeCsvBulkExportStatus,
            'freeeCsvBulkExportIssue' => $freeeCsvBulkExportIssue,
            'moneyForwardCsvBulkExportStatus' => $moneyForwardCsvBulkExportStatus,
            'moneyForwardCsvBulkExportIssue' => $moneyForwardCsvBulkExportIssue,
            'moneyForwardCsvBulkExportMissingConfigurationLabels' => $moneyForwardCsvBulkExportMissingConfigurationLabels,
            'moneyForwardBusinessTaxStatusConfirmedByEntry' => $moneyForwardBusinessTaxStatusConfirmedByEntry,
            'purchasePaymentSourceReady' => $purchasePaymentSourceReady,
            'moneyForwardBatchCount' => $moneyForwardBatchCount,
            'freeeBatchCount' => $freeeBatchCount,
            'recentAccountingBatches' => $recentAccountingBatches,
            'directJournalSendEnabled' => app(FurimaDeckAccountingApiReleaseGate::class)->enabled(),
            'outOfSyncSentEntries' => $outOfSyncSentEntries,
            'inactiveReconciliationEntries' => $inactiveReconciliationEntries,
            'adjustmentSales' => $adjustmentSales,
            'provisionalCancellationCount' => $section === 'review' ? $adjustments->provisionalCancellationCount($request->user(), $from, $toExclusive) : 0,
        ]);
    }

    /** @param Collection<int, AccountingEntry> $entries */
    private function providerCsvBulkExportIssue(
        Collection $entries,
        string $provider,
        FurimaDeckAccountingVendorCsvService $vendorCsv,
        FurimaDeckAccountingEntryService $entryService,
    ): ?string {
        foreach ($entries as $entry) {
            $scopeIssue = $vendorCsv->providerCsvScopeIssue($entry, $provider);
            if ($scopeIssue !== null) {
                return $scopeIssue;
            }
        }

        foreach ($entries as $entry) {
            if ($entry->source_status !== 'confirmed' || $entry->confirmed_at === null) {
                return '期間内に確認前の販売データがあるため、一括CSVを出力できません。先にすべての販売データを確認してください。';
            }

            try {
                $entryService->assertCurrent($entry);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }
        }

        $purchaseIssues = $entryService->providerPurchaseReconciliationIssues($entries, $provider);
        if ($purchaseIssues !== []) {
            return (string) reset($purchaseIssues);
        }

        foreach ($entries as $entry) {
            $sync = $entry->providerSync($provider);
            if (! in_array($sync->status, ['not_synced', 'failed'], true)
                || filled($sync->external_transaction_id)
                || filled($sync->external_import_id)) {
                return '期間内に送信済み・取込待ち・処理中、または結果を確認できない販売データがあります。二重登録防止のため一括CSVを出力できません。';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $validated @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(array $validated): array
    {
        if (isset($validated['from']) xor isset($validated['to'])) {
            abort(422, '開始日と終了日は両方指定してください。');
        }
        if (isset($validated['from'], $validated['to'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], config('app.timezone'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], config('app.timezone'));
            if ($from === false || $to === false || $from->greaterThan($to)) {
                abort(422, '期間の指定が正しくありません。');
            }

            return [$from->startOfDay(), $to->addDay()->startOfDay()];
        }
        $year = (int) ($validated['year'] ?? now()->year);
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));

        return [$from, $from->addYear()];
    }
}
