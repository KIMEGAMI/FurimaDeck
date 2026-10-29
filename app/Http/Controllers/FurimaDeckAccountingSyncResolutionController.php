<?php

namespace App\Http\Controllers;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Sale;
use App\Services\FurimaDeckAccountingApiReleaseGate;
use App\Services\FurimaDeckAccountingJournalVerificationService;
use App\Services\FurimaDeckAccountingManualLegacyReconciliationService;
use App\Services\FurimaDeckAccountingProviderScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class FurimaDeckAccountingSyncResolutionController extends Controller
{
    public function resolve(Request $request, AccountingEntry $accountingEntry, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['freee', 'money_forward'], true), 404);
        abort_unless($accountingEntry->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['mark_synced', 'mark_csv_imported', 'manual_reconcile', 'confirm_mf_default_invoice_kind', 'retry'])],
            'external_transaction_id' => ['exclude_unless:action,mark_synced', 'required', 'string', 'max:255'],
            'external_import_id' => ['exclude_unless:action,mark_csv_imported', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{0,254}$/'],
            'import_confirmed' => ['exclude_unless:action,mark_csv_imported', 'accepted'],
            'manual_external_transaction_id' => ['exclude_unless:action,manual_reconcile', 'required', 'string', 'max:'.AccountingEntrySync::MANUAL_RECONCILIATION_EXTERNAL_ID_MAX_LENGTH],
            'manual_transaction_date' => ['exclude_unless:action,manual_reconcile', 'required', 'date_format:Y-m-d'],
            'manual_debit_total' => ['exclude_unless:action,manual_reconcile', 'required', 'integer', 'min:'.AccountingEntrySync::MANUAL_RECONCILIATION_AMOUNT_MIN, 'max:'.AccountingEntrySync::MANUAL_RECONCILIATION_AMOUNT_MAX],
            'manual_credit_total' => ['exclude_unless:action,manual_reconcile', 'required', 'integer', 'min:'.AccountingEntrySync::MANUAL_RECONCILIATION_AMOUNT_MIN, 'max:'.AccountingEntrySync::MANUAL_RECONCILIATION_AMOUNT_MAX],
            'manual_accounts' => ['exclude_unless:action,manual_reconcile', 'required', 'string', 'max:'.AccountingEntrySync::MANUAL_RECONCILIATION_TEXT_MAX_LENGTH],
            'manual_tax_classifications' => ['exclude_unless:action,manual_reconcile', 'required', 'string', 'max:'.AccountingEntrySync::MANUAL_RECONCILIATION_TEXT_MAX_LENGTH],
            'confirm_same_entry' => ['exclude_unless:action,manual_reconcile', 'accepted'],
            'confirm_accounting_service' => ['exclude_unless:action,manual_reconcile', 'accepted'],
            'confirm_journal_id' => ['exclude_unless:action,manual_reconcile', 'accepted'],
            'confirm_transaction_date' => ['exclude_unless:action,manual_reconcile', 'accepted'],
            'confirm_amounts' => ['exclude_unless:action,manual_reconcile', 'accepted'],
            'confirm_accounts' => ['exclude_unless:action,manual_reconcile', 'accepted'],
            'confirm_tax_classifications' => ['exclude_unless:action,manual_reconcile', 'accepted'],
            'confirm_no_retry' => ['exclude_unless:action,manual_reconcile', 'accepted'],
            'confirm_mf_invoice_kind_correct' => ['exclude_unless:action,confirm_mf_default_invoice_kind', 'accepted'],
            'confirm_mf_no_resend' => ['exclude_unless:action,confirm_mf_default_invoice_kind', 'accepted'],
            'verified_absent' => ['exclude_unless:action,retry', 'accepted'],
        ], [
            'confirm_same_entry.accepted' => 'この外部仕訳が対象明細に対応すると確認してください。',
            'confirm_accounting_service.accepted' => '会計サービス名を確認してください。',
            'confirm_journal_id.accepted' => '外部仕訳IDを確認してください。',
            'confirm_transaction_date.accepted' => '取引日を確認してください。',
            'confirm_amounts.accepted' => '借方・貸方の金額を確認してください。',
            'confirm_accounts.accepted' => '勘定科目・補助科目を確認してください。',
            'confirm_tax_classifications.accepted' => '税区分を確認してください。',
            'confirm_no_retry.accepted' => '手動照合後は再送できないことを確認してください。',
            'confirm_mf_invoice_kind_correct.accepted' => 'Money Forward側のインボイス区分が正しいことを確認してください。',
            'confirm_mf_no_resend.accepted' => 'この操作で仕訳を再送・変更しないことを確認してください。',
        ]);

        if (in_array($validated['action'], ['mark_synced', 'confirm_mf_default_invoice_kind'], true)) {
            abort_unless(app(FurimaDeckAccountingApiReleaseGate::class)->enabled(), 404);
        }

        $inactiveSaleWasMarkedAbsent = false;
        try {
            if ($validated['action'] === 'mark_csv_imported') {
                DB::connection('furimadeck')->transaction(function () use ($request, $accountingEntry, $provider, $validated): void {
                    $connection = AccountingConnection::on('furimadeck')
                        ->where('user_id', $request->user()->id)
                        ->where('provider', $provider)
                        ->whereIn('status', ['connected', 'expired'])
                        ->lockForUpdate()
                        ->first();
                    $currentScopeHash = $connection === null
                        ? null
                        : app(FurimaDeckAccountingProviderScopeService::class)
                            ->fingerprintForConnection($connection, $provider);
                    if ($currentScopeHash === null) {
                        throw new RuntimeException('接続中の会計事業者を特定できません。事業者を確認できるまでCSV取込済みとして記録しません。');
                    }
                    $entry = $request->user()->accountingEntries()->whereKey($accountingEntry->id)->lockForUpdate()->firstOrFail();
                    if ($entry->source_status !== 'confirmed' || $entry->confirmed_at === null) {
                        throw new RuntimeException('確認済みデータだけCSV取込結果を記録できます。');
                    }

                    $sync = $entry->providerSyncs()->firstOrCreate(
                        ['provider' => $provider],
                        ['status' => $entry->providerSyncInitialStatus($provider)],
                    );
                    if ($sync->status !== 'csv_pending' || filled($sync->external_transaction_id)) {
                        throw new RuntimeException('CSV取込待ちの明細だけ取込結果を記録できます。');
                    }
                    if (filled($sync->external_import_id)) {
                        throw new RuntimeException('既存のCSV取込IDが記録されています。監査情報を保持するため、上書きせず記録を止めました。');
                    }
                    $exportScopeHash = $sync->provider_account_scope_hash;
                    if (! is_string($exportScopeHash) || $exportScopeHash === '') {
                        throw new RuntimeException('CSV出力時の事業者記録がありません。外部での取込有無をこの画面では判定できないため記録を止めました。会計サービス側の取込履歴を確認するまで、CSVを再取込しないでください。');
                    }
                    if (! hash_equals($exportScopeHash, $currentScopeHash)) {
                        throw new RuntimeException('CSV出力時と現在の会計事業者が異なります。取込済み記録は行いません。CSVを登録した事業者を確認してください。');
                    }

                    $sync->update([
                        'status' => AccountingEntrySync::STATUS_CSV_IMPORTED,
                        'external_import_id' => $validated['external_import_id'] ?? null,
                        'last_error' => null,
                    ]);
                    $entry->refreshLegacySyncStatus();
                });

                return back()->with('success', 'CSVを取込済みとして記録しました。これは手動確認であり、APIによる仕訳照合済みではありません。');
            }

            if ($validated['action'] === 'mark_synced') {
                app(FurimaDeckAccountingJournalVerificationService::class)->reconcileUnknown(
                    $request->user(), $accountingEntry, $provider, $validated['external_transaction_id'],
                );

                return back()->with('success', '外部仕訳の内容を照合し、一致を確認しました。');
            }

            if ($validated['action'] === 'manual_reconcile') {
                app(FurimaDeckAccountingManualLegacyReconciliationService::class)->reconcile(
                    $request->user(),
                    $accountingEntry,
                    $provider,
                    [
                        'external_transaction_id' => $validated['manual_external_transaction_id'],
                        'transaction_date' => $validated['manual_transaction_date'],
                        'debit_total' => $validated['manual_debit_total'],
                        'credit_total' => $validated['manual_credit_total'],
                        'accounts' => $validated['manual_accounts'],
                        'tax_classifications' => $validated['manual_tax_classifications'],
                    ],
                );

                return back()->with('success', '旧明細を手動照合済みとして記録しました。API自動照合ではなく、再送もできません。');
            }

            if ($validated['action'] === 'confirm_mf_default_invoice_kind') {
                abort_unless($provider === 'money_forward', 404);
                app(FurimaDeckAccountingJournalVerificationService::class)->confirmMoneyForwardDefaultInvoiceKind(
                    $request->user(),
                    $accountingEntry,
                    true,
                    true,
                );

                return back()->with('success', 'Money Forwardの最新仕訳を読み直し、既定のインボイス区分を手動確認済みとして監査記録に保存しました。API完全一致とは別の状態で、再送・変更はしていません。');
            }

            DB::connection('furimadeck')->transaction(function () use ($request, $accountingEntry, $provider, &$inactiveSaleWasMarkedAbsent): void {
                $entry = $request->user()->accountingEntries()->whereKey($accountingEntry->id)->lockForUpdate()->firstOrFail();
                if ($entry->source_status !== 'confirmed') {
                    throw new RuntimeException('Confirm済みデータだけ送信状態を確定できます。');
                }
                $sync = $entry->providerSyncs()->firstOrCreate(
                    ['provider' => $provider],
                    ['status' => $entry->providerSyncInitialStatus($provider)],
                );
                if (! in_array($sync->status, ['sync_unknown', 'legacy_unknown', 'csv_pending'], true)) {
                    throw new RuntimeException('この送信状態は照合操作の対象ではありません。');
                }
                if ($sync->status === 'csv_pending') {
                    $connection = AccountingConnection::on('furimadeck')
                        ->where('user_id', $request->user()->id)
                        ->where('provider', $provider)
                        ->whereIn('status', ['connected', 'expired'])
                        ->lockForUpdate()
                        ->first();
                    $currentScopeHash = $connection === null
                        ? null
                        : app(FurimaDeckAccountingProviderScopeService::class)
                            ->fingerprintForConnection($connection, $provider);
                    $exportScopeHash = $sync->provider_account_scope_hash;
                    if (! is_string($exportScopeHash) || trim($exportScopeHash) === ''
                        || $currentScopeHash === null
                        || ! hash_equals($exportScopeHash, $currentScopeHash)) {
                        throw new RuntimeException('CSV出力時と同じ会計事業者に接続し、その仕訳帳で登録がないことを確認するまで状態を変更しません。');
                    }
                }
                if ($provider === 'freee' && $sync->last_error === AccountingEntrySync::EXTERNAL_VOUCHER_DELETED_TAX_REVIEW) {
                    throw new RuntimeException('削除済みのfreeeテスト仕訳は、会計処理の確認が終わるまで再送可能にできません。');
                }
                if (filled($sync->external_transaction_id)
                    || filled($sync->external_import_id)
                    || ($sync->status === 'legacy_unknown' && filled($entry->external_transaction_id))) {
                    throw new RuntimeException('外部仕訳IDが記録されています。二重送信を防ぐため、外部仕訳を照合してください。');
                }
                if ($sync->status !== 'csv_pending') {
                    $connection = AccountingConnection::on('furimadeck')
                        ->where('user_id', $request->user()->id)
                        ->where('provider', $provider)
                        ->whereIn('status', ['connected', 'expired'])
                        ->lockForUpdate()
                        ->first();
                    $currentScopeHash = $connection === null
                        ? null
                        : app(FurimaDeckAccountingProviderScopeService::class)
                            ->fingerprintForConnection($connection, $provider);
                    $sendScopeHash = $sync->provider_account_scope_hash;
                    if (! is_string($sendScopeHash) || trim($sendScopeHash) === ''
                        || $currentScopeHash === null
                        || ! hash_equals($sendScopeHash, $currentScopeHash)) {
                        throw new RuntimeException('送信時と同じ会計事業者を特定できません。正しい事業者の仕訳帳を確認し、接続先が一致するまで未登録として記録しません。');
                    }
                }

                if ($entry->entry_type === AccountingEntry::TYPE_SALE) {
                    $saleStatus = $entry->sale()->value('status');
                    $inactiveSaleWasMarkedAbsent = ! in_array($saleStatus, Sale::VALID_SOLD_STATUSES, true);
                }

                $sync->update([
                    'status' => 'not_synced',
                    'external_transaction_id' => null,
                    'external_import_id' => null,
                    'synced_at' => null,
                    'last_error' => $inactiveSaleWasMarkedAbsent ? AccountingEntrySync::VERIFIED_ABSENT_INACTIVE_SALE : null,
                ]);
                $entry->refreshLegacySyncStatus();
            });
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $successMessage = match (true) {
            $inactiveSaleWasMarkedAbsent => '会計サービスに仕訳がないことを記録しました。売上は取消・返品済みのため再送しません。',
            $accountingEntry->entry_type === AccountingEntry::TYPE_PURCHASE => '仕訳がないことを記録しました。API送信は行っていません。仕入データはFurimaDeck 1.0の会計CSV出力対象外です。',
            default => '仕訳がないことを記録しました。API送信は行っていません。必要なら会計ソフト用CSVを再出力してください。',
        };

        return back()->with('success', $successMessage);
    }
}
