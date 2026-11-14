<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FurimaDeckAccountingManualLegacyReconciliationService
{
    public function reconcile(User $user, AccountingEntry $entry, string $provider, array $evidence): void
    {
        if ($entry->user_id !== $user->id || ! in_array($provider, ['freee', 'money_forward'], true)) {
            abort(404);
        }

        DB::connection('furimadeck')->transaction(function () use ($user, $entry, $provider, $evidence): void {
            DB::connection('furimadeck')->table('users')->where('id', $user->id)->lockForUpdate()->first();
            $connection = AccountingConnection::on('furimadeck')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->whereIn('status', ['connected', 'expired'])
                ->lockForUpdate()
                ->first();
            $scopeHash = $connection === null
                ? null
                : app(FurimaDeckAccountingProviderScopeService::class)->fingerprintForConnection($connection, $provider);
            if ($scopeHash === null) {
                throw new RuntimeException('接続中の会計事業者を特定できません。現在の事業者を確認できるまで手動照合は行えません。');
            }
            $lockedEntry = $user->accountingEntries()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $sync = $lockedEntry->providerSyncs()->where('provider', $provider)->lockForUpdate()->first();

            if ($sync === null || $sync->status !== 'legacy_unknown'
                || $sync->accountingPayload() !== null
                || filled($sync->external_transaction_id)
                || ($provider === 'freee' && $sync->last_error === AccountingEntrySync::EXTERNAL_VOUCHER_DELETED_TAX_REVIEW)
                || $sync->manual_reconciliation_evidence !== null
                || $sync->manual_reconciled_at !== null) {
                throw new RuntimeException('送信時の記録がない旧状態だけが手動照合できます。状態は変更していません。');
            }

            $debitTotal = (int) $evidence['debit_total'];
            $creditTotal = (int) $evidence['credit_total'];
            if ($debitTotal < AccountingEntrySync::MANUAL_RECONCILIATION_AMOUNT_MIN
                || $creditTotal < AccountingEntrySync::MANUAL_RECONCILIATION_AMOUNT_MIN) {
                throw new RuntimeException('借方・貸方の合計は1円以上で入力してください。外部仕訳を再確認してください。');
            }
            if ($debitTotal !== $creditTotal) {
                throw new RuntimeException('入力した借方合計と貸方合計が一致しません。外部仕訳を再確認してください。');
            }

            $journalId = trim((string) $evidence['external_transaction_id']);
            $duplicate = AccountingEntrySync::query()
                ->where('provider', $provider)
                ->where('provider_account_scope_hash', $scopeHash)
                ->where('external_transaction_id', $journalId)
                ->whereHas('entry', fn ($query) => $query->where('user_id', $user->id))
                ->whereKeyNot($sync->id)
                ->exists();
            if ($duplicate) {
                throw new RuntimeException('この仕訳IDは別の明細に記録済みです。IDを確認してください。');
            }

            $now = now();
            $sync->forceFill([
                'status' => AccountingEntrySync::STATUS_MANUALLY_RECONCILED,
                'external_transaction_id' => $journalId,
                'provider_account_scope_hash' => $scopeHash,
                'synced_at' => null,
                'verified_at' => null,
                'last_error' => null,
                'manual_reconciliation_evidence' => [
                    'accounting_service' => $provider,
                    'transaction_date' => $evidence['transaction_date'],
                    'debit_total' => $debitTotal,
                    'credit_total' => $creditTotal,
                    'accounts' => trim((string) $evidence['accounts']),
                    'tax_classifications' => trim((string) $evidence['tax_classifications']),
                    'confirmed_fields' => [
                        'same_accounting_entry' => true,
                        'accounting_service' => true,
                        'journal_id' => true,
                        'transaction_date' => true,
                        'debit_and_credit_amounts' => true,
                        'accounts_and_subaccounts' => true,
                        'tax_classifications' => true,
                        'no_retry' => true,
                    ],
                ],
                'manual_reconciled_by_user_id' => $user->id,
                'manual_reconciled_at' => $now,
            ])->save();

            $lockedEntry->refreshLegacySyncStatus();
        });
    }
}
