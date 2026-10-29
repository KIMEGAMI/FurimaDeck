<?php

namespace App\Http\Controllers;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\Sale;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckAccountingProviderScopeService;
use App\Services\FurimaDeckAccountingVendorCsvService;
use App\Services\FurimaDeckFreeeCsvMappingService;
use App\Services\FurimaDeckMoneyForwardCsvMappingService;
use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FurimaDeckAccountingVendorExportController extends Controller
{
    private const CSV_EXPORTABLE_SYNC_STATUSES = ['not_synced', 'failed'];

    public function freee(Request $request, FurimaDeckAccountingVendorCsvService $csv): StreamedResponse|RedirectResponse
    {
        return $this->download($request, $csv, 'freee');
    }

    public function moneyForward(Request $request, FurimaDeckAccountingVendorCsvService $csv): StreamedResponse|RedirectResponse
    {
        return $this->download($request, $csv, 'money-forward');
    }

    private function download(Request $request, FurimaDeckAccountingVendorCsvService $csv, string $format): StreamedResponse|RedirectResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'accounting_entry_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $returnQuery = ['section' => isset($validated['accounting_entry_id']) ? 'review' : 'csv'];
        if (isset($validated['from'], $validated['to'])) {
            $returnQuery += ['from' => $validated['from'], 'to' => $validated['to']];
        } else {
            $returnQuery['year'] = (int) ($validated['year'] ?? now()->year);
        }
        if (isset($validated['from']) xor isset($validated['to'])) {
            abort(422, '開始日と終了日は両方指定してください。');
        }

        if (isset($validated['from'], $validated['to'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], config('app.timezone'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], config('app.timezone'));
            if ($from === false || $to === false || $from->greaterThan($to)) {
                abort(422, '期間の指定が正しくありません。');
            }
            $toExclusive = $to->addDay()->startOfDay();
        } else {
            $from = CarbonImmutable::create((int) ($validated['year'] ?? now()->year), 1, 1, 0, 0, 0, config('app.timezone'));
            $toExclusive = $from->addYear();
        }

        $isSingleEntryExport = isset($validated['accounting_entry_id']);
        $query = $request->user()->accountingEntries()->eligibleForAccounting()
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $toExclusive->toDateString())
            ->orderBy('transaction_date')
            ->orderBy('id');
        if ($isSingleEntryExport) {
            $query->whereKey((int) $validated['accounting_entry_id']);
        } else {
            $query->where('entry_type', AccountingEntry::TYPE_SALE);
        }
        $entries = $query->get();
        $provider = $format === 'freee' ? 'freee' : 'money_forward';
        try {
            if ($entries->isEmpty()) {
                throw new \RuntimeException('対象期間に確認済みの販売データがありません。仕入CSVはFurimaDeck 1.0の対応対象外のため、仕入は会計サービス側で別途登録してください。');
            }
            $csv->assertProviderCsvScope($entries, $provider);

            $scopeService = app(FurimaDeckAccountingProviderScopeService::class);
            $connectionBeforeMapping = $request->user()->accountingConnections()
                ->where('provider', $provider)
                ->whereIn('status', ['connected', 'expired'])
                ->first();
            $scopeHashBeforeMapping = $connectionBeforeMapping === null
                ? null
                : $scopeService->fingerprintForConnection($connectionBeforeMapping, $provider);
            $mappingSettingsFingerprintBefore = $connectionBeforeMapping === null
                ? null
                : $this->mappingSettingsFingerprint($connectionBeforeMapping);

            foreach ($entries as $entry) {
                if ($entry->source_status !== 'confirmed' || $entry->confirmed_at === null) {
                    throw new \RuntimeException('会計ソフト用CSVは、明細ごとに確認済みのデータだけ出力できます。');
                }
                app(FurimaDeckAccountingEntryService::class)->assertCurrent($entry);
                app(FurimaDeckAccountingEntryService::class)->assertProviderPurchaseReconciled($entry, $provider);
                if (! $this->isCsvExportable($entry, $provider)) {
                    throw new \RuntimeException('対象の会計ソフトへ送信済み、処理中、CSV取込待ち、または結果不明の明細があります。二重登録防止のためCSVを出力できません。');
                }
            }
            if ($format === 'freee' && $request->input('confirm_all_amounts_tax_inclusive') !== '1') {
                throw new \RuntimeException('freee CSVを出力するには、対象となるすべての金額が原資料どおりの税込金額であることを確認してください。CSVは出力していません。');
            }

            $mapping = $format === 'freee'
                ? app(FurimaDeckFreeeCsvMappingService::class)->forEntries($request->user(), $entries)
                : app(FurimaDeckMoneyForwardCsvMappingService::class)->forUser($request->user(), $entries);

            $connectionAfterMapping = $request->user()->accountingConnections()
                ->where('provider', $provider)
                ->whereIn('status', ['connected', 'expired'])
                ->first();
            $exportScopeHash = $connectionAfterMapping === null
                ? null
                : $scopeService->fingerprintForConnection($connectionAfterMapping, $provider);
            $mappingSettingsFingerprint = $connectionAfterMapping === null
                ? null
                : $this->mappingSettingsFingerprint($connectionAfterMapping);
            if ($exportScopeHash === null) {
                throw new \RuntimeException('会計CSVの出力先事業者を特定できません。連携先を確認してから出力してください。');
            }
            if ($scopeHashBeforeMapping !== null && ! hash_equals($scopeHashBeforeMapping, $exportScopeHash)) {
                throw new \RuntimeException('CSV作成中に連携先事業者が変更されました。内容を確認してからCSVを再出力してください。');
            }
            if ($mappingSettingsFingerprintBefore === null || $mappingSettingsFingerprint === null
                || ! hash_equals($mappingSettingsFingerprintBefore, $mappingSettingsFingerprint)) {
                throw new \RuntimeException('CSV作成中に勘定科目・税区分設定が変更されました。内容を確認してからCSVを再出力してください。');
            }
            $csvStream = DB::connection('furimadeck')->transaction(function () use ($request, $entries, $format, $csv, $mapping, $provider, $exportScopeHash, $mappingSettingsFingerprint, $scopeService) {
                $provider = $format === 'freee' ? 'freee' : 'money_forward';
                $lockedConnection = AccountingConnection::on('furimadeck')
                    ->where('user_id', $request->user()->id)
                    ->where('provider', $provider)
                    ->whereIn('status', ['connected', 'expired'])
                    ->lockForUpdate()
                    ->first();
                $lockedScopeHash = $lockedConnection === null
                    ? null
                    : $scopeService->fingerprintForConnection($lockedConnection, $provider);
                if ($lockedScopeHash === null || ! hash_equals($exportScopeHash, $lockedScopeHash)) {
                    throw new \RuntimeException('CSV作成中に連携先事業者が変更されました。内容を確認してからCSVを再出力してください。');
                }
                if (! hash_equals($mappingSettingsFingerprint, $this->mappingSettingsFingerprint($lockedConnection))) {
                    throw new \RuntimeException('CSV作成中に勘定科目・税区分設定が変更されました。内容を確認してからCSVを再出力してください。');
                }

                $lockedEntries = collect();
                $lockedSyncs = [];
                foreach ($entries as $selected) {
                    if ($selected->entry_type === AccountingEntry::TYPE_PURCHASE) {
                        $related = $request->user()->products()->whereKey($selected->product_id)->lockForUpdate()->first();
                        if ($related === null) {
                            throw new \RuntimeException('仕入商品を確認できないため、仕訳CSVは出力していません。');
                        }
                    } else {
                        $related = $request->user()->sales()->whereKey($selected->sale_id)->lockForUpdate()->first();
                        if ($related === null || ! in_array($related->status, Sale::VALID_SOLD_STATUSES, true)) {
                            throw new \RuntimeException('販売記録が取消・返品済みのため、仕訳CSVは出力していません。');
                        }
                    }

                    $entry = $request->user()->accountingEntries()->whereKey($selected->id)->lockForUpdate()->firstOrFail();
                    if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                        $entry->setRelation('product', $related);
                    } else {
                        $entry->setRelation('sale', $related->loadMissing('marketplace'));
                    }
                    if ($entry->source_status !== 'confirmed' || $entry->confirmed_at === null) {
                        throw new \RuntimeException('出力準備中に明細の状態が変わりました。CSVは出力していません。');
                    }
                    app(FurimaDeckAccountingEntryService::class)->assertCurrent($entry);
                    app(FurimaDeckAccountingEntryService::class)->assertProviderPurchaseReconciled($entry, $provider);

                    $sync = $entry->providerSyncs()->firstOrCreate(
                        ['provider' => $provider],
                        ['status' => $entry->providerSyncInitialStatus($provider)],
                    );
                    if (! in_array($sync->status, self::CSV_EXPORTABLE_SYNC_STATUSES, true)
                        || filled($sync->external_transaction_id)
                        || filled($sync->external_import_id)) {
                        throw new \RuntimeException('出力準備中に明細の状態が変わりました。CSVは出力していません。');
                    }

                    $lockedEntries->push($entry);
                    $lockedSyncs[$entry->id] = $sync;
                }

                $rows = $csv->journalRows($lockedEntries, $mapping['accounts'], $provider);
                $memoWrittenForTransaction = [];
                $renderedRows = array_map(function (array $row) use ($format, $csv, $mapping, &$memoWrittenForTransaction): array {
                    if ($format === 'freee') {
                        return $csv->sanitizeRowForProvider($csv->freeeRow($row, $mapping['tax_names']), 'freee');
                    }

                    $transactionNumber = (string) $row['number'];
                    $includeJournalMemo = ! isset($memoWrittenForTransaction[$transactionNumber]);
                    $memoWrittenForTransaction[$transactionNumber] = true;
                    $businessStatus = $mapping['business_status_by_date'][$row['date']] ?? null;
                    if (! is_string($businessStatus)
                        || ! in_array($businessStatus, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES, true)
                        || $businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED) {
                        throw new \RuntimeException('Money Forwardの取引日別の課税・免税状態を確認できません。CSVは出力していません。');
                    }

                    return $csv->sanitizeRowForProvider($csv->moneyForwardRow(
                        $row,
                        $mapping['tax_names'],
                        $mapping['sub_accounts'],
                        $mapping['invoice_kinds'],
                        $businessStatus,
                        $includeJournalMemo,
                    ), 'money_forward');
                }, $rows);

                $headers = $format === 'freee' ? $csv->freeeHeaders() : $csv->moneyForwardHeaders();
                if ($format === 'freee') {
                    $maxRows = (int) config('furimadeck.accounting.csv_limits.freee.max_rows');
                    if (count($renderedRows) + 1 > $maxRows) {
                        throw new \RuntimeException('Freee CSVの行数上限（'.number_format($maxRows).'行）を超えるため出力できません。期間を分けて出力してください。');
                    }
                }

                $stream = fopen('php://temp/maxmemory:5242880', 'w+b');
                if ($stream === false) {
                    throw new \RuntimeException('仕訳CSVを一時作成できませんでした。CSVは出力していません。');
                }

                try {
                    if (fwrite($stream, "\xEF\xBB\xBF") !== 3) {
                        throw new \RuntimeException('仕訳CSVを一時作成できませんでした。CSVは出力していません。');
                    }
                    $this->writeCsvRow($stream, $headers);
                    foreach ($renderedRows as $values) {
                        $this->writeCsvRow($stream, $values);
                    }

                    if ($format === 'freee') {
                        $maxBytes = (int) config('furimadeck.accounting.csv_limits.freee.max_bytes');
                        $bytes = ftell($stream);
                        if ($bytes === false || $bytes > $maxBytes) {
                            throw new \RuntimeException('Freee CSVのファイルサイズ上限（'.number_format($maxBytes).'バイト）を超えるため出力できません。期間を分けて出力してください。');
                        }
                    }

                    rewind($stream);

                    foreach ($lockedEntries as $entry) {
                        $sync = $lockedSyncs[$entry->id];
                        $sync->update([
                            'status' => 'csv_pending',
                            'attempted_at' => now(),
                            'provider_account_scope_hash' => $exportScopeHash,
                        ]);
                        $entry->refreshLegacySyncStatus();
                    }

                    return $stream;
                } catch (\Throwable $exception) {
                    fclose($stream);
                    throw $exception;
                }
            });
        } catch (ConnectionException) {
            return redirect()->route('furimadeck-accounting.index', $returnQuery)
                ->with('error', '会計ソフトと通信できませんでした。仕訳CSVは出力していません。');
        } catch (\RuntimeException $exception) {
            return redirect()->route('furimadeck-accounting.index', $returnQuery)->with('error', $exception->getMessage());
        }
        $filename = 'furimadeck-'.($format === 'freee' ? 'freee' : 'money-forward').'-accounting.csv';

        return response()->streamDownload(function () use ($csvStream): void {
            try {
                fpassthru($csvStream);
            } finally {
                fclose($csvStream);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isCsvExportable(AccountingEntry $entry, string $provider): bool
    {
        $sync = $entry->providerSync($provider);

        return in_array($sync->status, self::CSV_EXPORTABLE_SYNC_STATUSES, true)
            && blank($sync->external_transaction_id)
            && blank($sync->external_import_id);
    }

    private function mappingSettingsFingerprint(AccountingConnection $connection): string
    {
        return hash('sha256', serialize((array) ($connection->settings ?? [])));
    }

    /** @param resource $stream @param array<int, string> $values */
    private function writeCsvRow($stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', '') === false) {
            throw new \RuntimeException('仕訳CSVを一時作成できませんでした。CSVは出力していません。');
        }
    }
}
