<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\AccountingSyncBatchItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckMoneyForwardAccountingSyncService
{
    public const INVOICE_KIND_OMIT = FurimaDeckMoneyForwardInvoiceKindService::OMIT;

    private const AMBIGUOUS_RESPONSE_STATUSES = [408, 425, 429];

    private const MAX_DIAGNOSTIC_ITEMS = 2;

    /** @return list<string> */
    public function missingConfiguration(?AccountingConnection $connection = null, ?string $businessStatus = null): array
    {
        $settings = (array) ($connection?->settings ?? []);
        $missing = [];
        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $key) {
            if (trim((string) (($settings['account_ids'] ?? [])[$key] ?? '')) === '') {
                $missing[] = 'account_ids.'.$key;
            }
            if (! array_key_exists($key, (array) ($settings['sub_account_ids'] ?? []))) {
                $missing[] = 'sub_account_ids.'.$key;
            }
        }
        $periods = collect();
        if ($businessStatus === null) {
            try {
                $periods = collect(app(FurimaDeckMoneyForwardBusinessPeriodService::class)
                    ->normalize((array) ($settings['business_tax_periods'] ?? [])));
            } catch (RuntimeException) {
                $missing[] = 'business_tax_periods';
            }
            if ($periods->isEmpty() && ! in_array('business_tax_periods', $missing, true)) {
                $missing[] = 'business_tax_periods';
            } elseif ($periods->contains(fn (array $period): bool => $period['invoice_kind'] === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED)) {
                $missing[] = 'business_tax_status_confirmation';
            }
            $periodKinds = $periods->pluck('invoice_kind');
            $taxesOptional = $periodKinds->isNotEmpty() && $periodKinds->every(fn (mixed $kind): bool => in_array($kind, [
                FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT,
                FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED,
            ], true));
        } else {
            $taxesOptional = in_array($businessStatus, [
                FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT,
                FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED,
            ], true);
            if (! in_array($businessStatus, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES, true)) {
                $missing[] = 'business_tax_periods';
            } elseif ($businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED) {
                $missing[] = 'business_tax_status_confirmation';
            }
        }
        if (! $taxesOptional) {
            foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $key) {
                if (trim((string) (($settings['tax_ids'] ?? [])[$key] ?? '')) === '') {
                    $missing[] = 'tax_ids.'.$key;
                }
            }
        }
        if (($settings['account_statement_types_checked'] ?? false) !== true) {
            $missing[] = 'account_statement_types_check';
        }
        $requiresInvoiceKinds = $businessStatus === null
            ? ($periods->isEmpty() || $periods->contains(fn (array $period): bool => $period['invoice_kind'] === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE))
            : $businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE;
        if ($requiresInvoiceKinds) {
            $invoiceKinds = (array) ($settings['invoice_kinds'] ?? []);
            foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $key) {
                if (! app(FurimaDeckMoneyForwardInvoiceKindService::class)->isValid((string) ($invoiceKinds[$key] ?? ''))) {
                    $missing[] = 'invoice_kinds.'.$key;
                }
            }
        }

        return $missing;
    }

    public function missingConfigurationForDate(?AccountingConnection $connection, string $date): array
    {
        try {
            $businessStatus = app(FurimaDeckMoneyForwardBusinessPeriodService::class)
                ->statusForDate((array) ($connection?->settings ?? []), $date);
        } catch (RuntimeException) {
            return [...$this->missingConfiguration($connection, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED), 'business_tax_periods'];
        }

        return $this->missingConfiguration($connection, $businessStatus);
    }

    public function send(User $user, AccountingEntry $entry, ?int $batchItemId = null): string
    {
        if (! app(FurimaDeckAccountingApiReleaseGate::class)->enabled()) {
            throw new RuntimeException('FurimaDeck 1.0では会計ソフトへのAPI仕訳送信を提供していません。会計ソフト用CSVを利用してください。');
        }
        abort_unless($entry->user_id === $user->id, 404);
        if ($entry->source_status !== 'confirmed' || $entry->confirmed_at === null) {
            throw new RuntimeException('明細ごとに確認済みの会計データだけ送信できます。');
        }
        app(FurimaDeckAccountingEntryService::class)->assertCurrent($entry);
        app(FurimaDeckAccountingEntryService::class)->assertProviderPurchaseReconciled($entry, 'money_forward');
        if ($batchItemId !== null && ! AccountingSyncBatchItem::query()
            ->whereKey($batchItemId)
            ->where('accounting_entry_id', $entry->id)
            ->whereHas('batch', fn ($query) => $query->where('user_id', $user->id)->where('provider', 'money_forward'))
            ->exists()) {
            throw new RuntimeException('一括送信の対象データを確認できません。');
        }
        $existingStatus = $entry->providerSyncStatus('money_forward');
        $sendableStatuses = $batchItemId === null ? ['not_synced', 'failed'] : ['queued'];
        if (! in_array($existingStatus, $sendableStatuses, true)) {
            throw new RuntimeException(in_array($existingStatus, ['sync_unknown', 'legacy_unknown'], true)
                ? 'Money Forward側の送信結果を確認してください。確認が終わるまで再送できません。'
                : 'この会計データはMoney Forwardへ送信済み、または処理中です。');
        }
        $existingSync = $entry->providerSync('money_forward');
        if (filled($existingSync->external_transaction_id) || $existingSync->synced_at !== null) {
            throw new RuntimeException('Money Forwardの外部仕訳IDまたは同期済み記録が残っています。二重登録防止のため再送できません。');
        }

        $storedConnection = $user->accountingConnections()
            ->where('provider', 'money_forward')
            ->where('status', 'connected')
            ->first();
        $storedSettings = (array) ($storedConnection?->settings ?? []);
        $businessStatus = app(FurimaDeckMoneyForwardBusinessPeriodService::class)
            ->statusForDate($storedSettings, $entry->transaction_date?->toDateString() ?? '');
        if ($businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED) {
            throw new RuntimeException('取引日に適用するMoney Forward事業者の課税・免税状態が未確認のため、API送信を停止しています。');
        }

        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward');
        $providerScopeHash = app(FurimaDeckAccountingProviderScopeService::class)
            ->fingerprintForConnection($connection, 'money_forward');
        if ($providerScopeHash === null) {
            throw new RuntimeException('Money Forwardの接続先事業者を特定できません。事業者情報を確認するまで送信しません。');
        }
        $businessStatus = app(FurimaDeckMoneyForwardBusinessPeriodService::class)
            ->statusForDate((array) ($connection->settings ?? []), $entry->transaction_date?->toDateString() ?? '');
        if ($businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED) {
            throw new RuntimeException('取引日に適用するMoney Forward事業者の課税・免税状態が未確認のため、API送信を停止しています。');
        }
        if ($this->missingConfiguration($connection, $businessStatus) !== []) {
            throw new RuntimeException('Money Forwardの仕訳設定が不足しています。');
        }
        if ($entry->entry_type !== AccountingEntry::TYPE_PURCHASE) {
            foreach (['shipping_cost' => 'shipping', 'other_cost' => 'other'] as $field => $role) {
                $settings = (array) $connection->settings;
                if ((int) $entry->{$field} > 0 && (
                    trim((string) ($settings['account_ids'][$role] ?? '')) === ''
                    || ($businessStatus !== FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT && trim((string) ($settings['tax_ids'][$role] ?? '')) === '')
                    || ! array_key_exists($role, (array) ($settings['sub_account_ids'] ?? []))
                    || ($businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE
                        && ! app(FurimaDeckMoneyForwardInvoiceKindService::class)->isValid((string) (($settings['invoice_kinds'] ?? [])[$role] ?? '')))
                )) {
                    throw new RuntimeException('Money Forwardの'.$role.'用勘定科目と税区分を設定してください。仕訳は送信していません。');
                }
            }
        }
        $currentTaxIds = app(FurimaDeckMoneyForwardAccountingSetupService::class)->assertCurrentConfiguration($user, $connection, $businessStatus);
        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward');
        $settings = (array) $connection->settings;
        $settings['invoice_kind'] = $businessStatus;
        $settings['tax_ids'] = $currentTaxIds;
        [$sync, $entry] = DB::connection('furimadeck')->transaction(function () use ($user, $entry, $batchItemId, $settings, $providerScopeHash): array {
            if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                $lockedProduct = Product::query()->whereKey($entry->product_id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            } else {
                $lockedSale = Sale::query()->whereKey($entry->sale_id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            }
            $locked = $user->accountingEntries()->eligibleForAccounting()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                $locked->setRelation('product', $lockedProduct);
            } else {
                $locked->setRelation('sale', $lockedSale->loadMissing('marketplace'));
            }
            if ($locked->source_status !== 'confirmed' || $locked->confirmed_at === null) {
                throw new RuntimeException('Confirm済みの会計データだけ送信できます。');
            }
            app(FurimaDeckAccountingEntryService::class)->assertCurrent($locked);
            app(FurimaDeckAccountingEntryService::class)->assertProviderPurchaseReconciled($locked, 'money_forward');
            $sync = $locked->providerSyncs()->firstOrCreate(
                ['provider' => 'money_forward'],
                ['status' => $locked->providerSyncInitialStatus('money_forward')],
            );
            if (filled($sync->external_transaction_id) || $sync->synced_at !== null) {
                throw new RuntimeException('Money Forwardの外部仕訳IDまたは同期済み記録が残っています。二重登録防止のため再送できません。');
            }
            $allowedStatuses = $batchItemId === null ? ['not_synced', 'failed'] : ['queued'];
            if (! in_array($sync->status, $allowedStatuses, true)) {
                throw new RuntimeException($sync->status === 'sync_unknown' || $sync->status === 'legacy_unknown'
                    ? 'Money Forward側の送信結果を確認してください。確認が終わるまで再送できません。'
                    : 'この会計データはMoney Forwardへ送信済み、または処理中です。');
            }
            $journal = $this->journal($locked, $settings);
            $containsSensitiveRemarks = $locked->entry_type === AccountingEntry::TYPE_PURCHASE
                && $locked->purchase_special_case_data !== null;
            $sync->fill([
                'status' => 'sending',
                'attempt_count' => $sync->attempt_count + 1,
                'attempted_at' => now(),
                'last_error' => null,
                'verified_at' => null,
                'provider_account_scope_hash' => $providerScopeHash,
            ]);
            $sync->setAccountingPayload($journal, $containsSensitiveRemarks);
            $sync->save();

            return [$sync, $locked];
        });

        try {
            $response = $this->request($connection)->post('/api/v3/journals', ['journal' => $sync->accountingPayload()]);
        } catch (ConnectionException $exception) {
            $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'network_error');
            throw new RuntimeException('Money Forwardとの通信結果を確認できません。二重登録防止のため、Money Forward側を確認するまで再送できません。', previous: $exception);
        } catch (\Throwable $exception) {
            $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'sync_unknown');
            throw new RuntimeException('Money Forwardへの送信結果を確認できません。Money Forward側を確認するまで再送できません。', previous: $exception);
        }

        if ($response->status() === 401) {
            try {
                $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded(
                    $user,
                    'money_forward',
                    true,
                    (string) $connection->access_token,
                );
            } catch (\Throwable $exception) {
                $this->markFailure($sync, $entry, $connection, 'failed', 'http_401_token_refresh_failed');
                throw new RuntimeException('Money Forwardの認証を更新できませんでした。連携を解除して再連携してください。仕訳は再送していません。', previous: $exception);
            }

            try {
                $response = $this->request($connection)->post('/api/v3/journals', ['journal' => $sync->accountingPayload()]);
            } catch (ConnectionException $exception) {
                $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'network_error_after_auth_refresh');
                throw new RuntimeException('認証更新後の送信結果を確認できません。二重登録防止のため、Money Forward側を確認するまで再送できません。', previous: $exception);
            } catch (\Throwable $exception) {
                $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'sync_unknown_after_auth_refresh');
                throw new RuntimeException('認証更新後の送信結果を確認できません。Money Forward側を確認するまで再送できません。', previous: $exception);
            }
        }

        if ($response->failed()) {
            $diagnostic = $this->failureDiagnostic(
                $response->status(),
                $response->json(),
                ['journal' => (array) $sync->accountingPayload()],
                $this->requestId($response),
            );
            $unknown = $response->serverError() || in_array($response->status(), self::AMBIGUOUS_RESPONSE_STATUSES, true);
            $this->markFailure($sync, $entry, $connection, $unknown ? 'sync_unknown' : 'failed', $diagnostic);
            $message = $unknown
                ? 'Money Forward側の応答が不確定です。二重登録防止のため、Money Forward側を確認するまで再送できません。'
                : $this->failureMessage($response->status(), $diagnostic);
            throw new RuntimeException($message);
        }

        $externalId = data_get($response->json(), 'journal.id');
        if (! is_scalar($externalId) || trim((string) $externalId) === '') {
            $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'sync_response_invalid');
            throw new RuntimeException('Money Forwardは応答しましたが仕訳IDを確認できません。二重登録防止のため、Money Forward側を確認してください。');
        }

        DB::connection('furimadeck')->transaction(function () use ($sync, $entry, $connection, $externalId): void {
            $locked = AccountingEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $lockedSync = $locked->providerSyncs()->whereKey($sync->id)->where('provider', 'money_forward')->lockForUpdate()->firstOrFail();
            $lockedSync->update([
                'status' => 'synced',
                'external_transaction_id' => (string) $externalId,
                'synced_at' => now(),
                'last_error' => null,
            ]);
            $legacyValues = [];
            if ($locked->external_transaction_id === null) {
                $legacyValues['external_transaction_id'] = (string) $externalId;
                $legacyValues['synced_at'] = now();
            }
            if ($legacyValues !== []) {
                $locked->update($legacyValues);
            }
            $locked->refreshLegacySyncStatus();
            $connection->update(['last_error' => null, 'last_synced_at' => now()]);
        });

        return (string) $externalId;
    }

    private function markFailure(AccountingEntrySync $sync, AccountingEntry $entry, AccountingConnection $connection, string $status, string $diagnostic): void
    {
        DB::connection('furimadeck')->transaction(function () use ($sync, $entry, $connection, $status, $diagnostic): void {
            $locked = AccountingEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $lockedSync = $locked->providerSyncs()->whereKey($sync->id)->where('provider', 'money_forward')->lockForUpdate()->firstOrFail();
            $alreadySucceeded = $lockedSync->status === 'synced' && filled($lockedSync->external_transaction_id);
            if (! $alreadySucceeded) {
                $lockedSync->update(['status' => $status, 'last_error' => mb_substr($diagnostic, 0, AccountingEntrySync::LAST_ERROR_LENGTH)]);
            }
            $locked->refreshLegacySyncStatus();
            $connection->update(['last_error' => $alreadySucceeded ? null : $diagnostic]);
        });
    }

    /** @return array<string, mixed> */
    private function journal(AccountingEntry $entry, array $settings): array
    {
        if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
            return $this->purchaseJournal($entry, $settings);
        }

        $accounts = (array) $settings['account_ids'];
        $subAccounts = (array) ($settings['sub_account_ids'] ?? []);
        $taxesOptional = ($settings['invoice_kind'] ?? null) === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT;
        $taxIds = $taxesOptional ? [] : (array) $settings['tax_ids'];
        $invoiceKinds = (array) ($settings['invoice_kinds'] ?? []);
        $invoiceKindService = app(FurimaDeckMoneyForwardInvoiceKindService::class);
        $branches = [];
        foreach (['settlement', 'sales', 'fees', 'shipping', 'other', 'cost', 'inventory'] as $role) {
            $amountField = match ($role) {
                'shipping' => 'shipping_cost',
                'other' => 'other_cost',
                default => null,
            };
            if ($amountField !== null && (int) ($entry->{$amountField} ?? 0) <= 0) {
                continue;
            }
            $kind = (string) ($invoiceKinds[$role] ?? '');
            if (! $invoiceKindService->isValid($kind)) {
                throw new RuntimeException('Money Forwardの'.$role.'側のインボイス区分が未設定です。仕訳は送信していません。');
            }
        }
        $this->addPair($branches, $accounts, $subAccounts, $taxIds, 'settlement', 'sales', (int) $entry->sale_amount, $entry->title.' 売上', $settings);
        foreach (['selling_fee' => '販売手数料', 'shipping_cost' => '送料'] as $field => $label) {
            $amount = (int) $entry->{$field};
            if ($amount > 0) {
                $role = $field === 'shipping_cost' ? 'shipping' : 'fees';
                $this->addPair($branches, $accounts, $subAccounts, $taxIds, $role, 'settlement', $amount, $entry->title.' '.$label, $settings);
            }
        }
        $operatingExpenses = app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails($entry, 'money_forward', $settings);
        $breakdown = (array) $entry->expense_breakdown;
        $purchaseTaxIds = (array) ($settings['purchase_tax_ids'] ?? []);
        foreach ($operatingExpenses as $field => $expense) {
            $amount = (int) ($breakdown[$field] ?? 0);
            if ($amount <= 0) {
                throw new RuntimeException('Money Forwardの費目別金額が会計明細と一致しません。仕訳は送信していません。');
            }
            $evidence = $expense['evidence'];
            $taxCategory = (string) ($evidence['tax_category'] ?? '');
            $invoiceIssuer = (string) ($evidence['invoice_issuer'] ?? '');
            $taxId = trim((string) ($purchaseTaxIds[$taxCategory] ?? ''));
            if (! $taxesOptional && $taxId === '') {
                throw new RuntimeException('証憑の税区分に対応するMoney Forward税区分が未設定です。仕訳は送信していません。');
            }
            $invoiceKind = $invoiceKindService->forPurchaseEvidence($taxCategory, $invoiceIssuer, $entry->transaction_date->toDateString());
            $source = $expense['source'];
            $debit = ['value' => $amount, 'account_id' => (string) ($accounts['other'] ?? '')];
            $credit = ['value' => $amount, 'account_id' => (string) ($source['account_id'] ?? '')];
            $expenseSubAccount = $subAccounts['other'] ?? null;
            $paymentSubAccount = $source['sub_account_id'] ?? null;
            if ($expenseSubAccount !== null) {
                $debit['sub_account_id'] = (string) $expenseSubAccount;
            }
            if ($paymentSubAccount !== null) {
                $credit['sub_account_id'] = (string) $paymentSubAccount;
            }
            if (! $taxesOptional && $taxId !== '') {
                $debit['tax_id'] = $taxId;
            }
            $paymentTaxId = trim((string) ($source['tax_id'] ?? ''));
            if (! $taxesOptional && $paymentTaxId === '') {
                throw new RuntimeException('Money Forwardの費目別支払元税区分を確認できません。仕訳は送信していません。');
            }
            if (! $taxesOptional && $paymentTaxId !== '') {
                $credit['tax_id'] = $paymentTaxId;
            }
            if (! $taxesOptional) {
                if ($invoiceKind !== null) {
                    $debit['invoice_kind'] = $invoiceKind;
                }
                $credit['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET;
            }
            $remark = 'FurimaDeck #'.$entry->management_id.' / '.$entry->title.' / '.FurimaDeckSaleExpenseAccountingService::EXPENSES[$field];
            $capMemo = app(FurimaDeckInvoiceTransitionCapService::class)->accountingMemoForEvidence(
                (int) $entry->user_id,
                $entry->transaction_date->toDateString(),
                $evidence,
            );
            $remark = app(FurimaDeckInvoiceTransitionCapService::class)->appendAccountingMemo($remark, $capMemo);
            if (mb_strlen($remark, 'UTF-8') > 200) {
                throw new RuntimeException('Money Forward摘要が200文字を超えるため仕訳を送信できません。商品名または管理IDを短くしてください。');
            }
            $branches[] = ['debitor' => $debit, 'creditor' => $credit, 'remark' => $remark];
        }
        $costOfGoodsSold = app(FurimaDeckAccountingPurchaseReconciliationService::class)
            ->costOfGoodsSoldForSale($entry, 'money_forward');
        if ($costOfGoodsSold > 0) {
            $this->addPair($branches, $accounts, $subAccounts, $taxIds, 'cost', 'inventory', $costOfGoodsSold, $entry->title.' 原価', $settings);
        }

        return [
            'transaction_date' => $entry->transaction_date->format('Y-m-d'),
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => $branches,
        ];
    }

    /** @return array<string, mixed> */
    private function purchaseJournal(AccountingEntry $entry, array $settings): array
    {
        $breakdown = (array) $entry->expense_breakdown;
        $evidenceByExpense = (array) ($breakdown['evidence'] ?? []);
        $purchaseSources = (array) ($breakdown['payment_sources'] ?? []);
        $sourceId = trim((string) ($purchaseSources['money_forward'] ?? ''));
        $source = (array) (((array) ($settings['purchase_payment_accounts'] ?? []))[$sourceId] ?? []);
        if ($sourceId === '' || $source === [] || trim((string) ($source['account_id'] ?? '')) === '') {
            throw new RuntimeException('この商品のMoney Forward支払元が未設定または無効です。支払方法を確認してください。');
        }

        $accountIds = (array) ($settings['account_ids'] ?? []);
        $subAccountIds = (array) ($settings['sub_account_ids'] ?? []);
        $purchaseTaxIds = (array) ($settings['purchase_tax_ids'] ?? []);
        $invoiceKindService = app(FurimaDeckMoneyForwardInvoiceKindService::class);
        $taxesOptional = ($settings['invoice_kind'] ?? null) === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT;
        $remarkService = app(FurimaDeckMoneyForwardPurchaseRemarkService::class);
        $lines = [
            'purchase_unit_cost' => ['amount' => (int) $entry->purchase_cost, 'label' => '商品仕入'],
            'purchase_shipping_cost' => ['amount' => (int) ($breakdown['purchase_shipping_cost'] ?? 0), 'label' => '仕入送料'],
            'other_purchase_expense' => ['amount' => (int) ($breakdown['other_purchase_expense'] ?? 0), 'label' => 'その他仕入費'],
        ];
        $branches = [];

        foreach ($lines as $expenseKey => $line) {
            $amount = $line['amount'];
            if ($amount <= 0) {
                continue;
            }
            $evidence = (array) ($evidenceByExpense[$expenseKey] ?? []);
            $taxCategory = (string) ($evidence['tax_category'] ?? '');
            $issuer = (string) ($evidence['invoice_issuer'] ?? '');
            $antiqueDealerException = $expenseKey === 'purchase_unit_cost' && $entry->purchase_special_case_data !== null;
            $invoiceKind = $invoiceKindService->forPurchaseEvidence(
                $taxCategory,
                $issuer,
                $entry->transaction_date->toDateString(),
                $antiqueDealerException,
            );
            $taxId = trim((string) ($purchaseTaxIds[$taxCategory] ?? ''));
            if (! $taxesOptional && $taxId === '') {
                throw new RuntimeException('証憑の税区分「'.$taxCategory.'」に対応するMoney Forward税区分が未設定です。仕訳は送信していません。');
            }

            $debit = ['value' => $amount, 'account_id' => (string) ($accountIds['inventory'] ?? '')];
            $credit = ['value' => $amount, 'account_id' => (string) $source['account_id']];
            $inventorySubAccountId = $subAccountIds['inventory'] ?? null;
            $paymentSubAccountId = $source['sub_account_id'] ?? null;
            if ($inventorySubAccountId !== null) {
                $debit['sub_account_id'] = (string) $inventorySubAccountId;
            }
            if ($paymentSubAccountId !== null) {
                $credit['sub_account_id'] = (string) $paymentSubAccountId;
            }
            if (! $taxesOptional && $taxId !== '') {
                $debit['tax_id'] = $taxId;
            }
            $paymentTaxId = trim((string) ($source['tax_id'] ?? ''));
            if (! $taxesOptional && $paymentTaxId !== '') {
                $credit['tax_id'] = $paymentTaxId;
            }
            if (! $taxesOptional && $invoiceKind !== null) {
                $debit['invoice_kind'] = $invoiceKind;
            }
            if (! $taxesOptional) {
                $credit['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET;
            }
            $lineRemark = $remarkService->build($entry, $antiqueDealerException).' / '.$line['label'];
            $capMemo = app(FurimaDeckInvoiceTransitionCapService::class)->accountingMemoForEvidence(
                (int) $entry->user_id,
                $entry->transaction_date->toDateString(),
                $evidence,
                $antiqueDealerException,
            );
            $lineRemark = app(FurimaDeckInvoiceTransitionCapService::class)->appendAccountingMemo($lineRemark, $capMemo);
            if (mb_strlen($lineRemark, 'UTF-8') > 200) {
                throw new RuntimeException('Money Forward摘要が200文字を超えるため仕訳を送信できません。商品名または管理IDを短くしてください。');
            }
            $branches[] = [
                'debitor' => $debit,
                'creditor' => $credit,
                'remark' => $lineRemark,
            ];
        }

        if ($branches === []) {
            throw new RuntimeException('仕入金額が0円のためMoney Forward仕訳を作成できません。');
        }

        return [
            'transaction_date' => $entry->transaction_date->format('Y-m-d'),
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => $branches,
        ];
    }

    private function addPair(array &$branches, array $accounts, array $subAccounts, array $taxIds, string $debitRole, string $creditRole, int $amount, string $remark, array $settings): void
    {
        $invoiceKinds = (array) ($settings['invoice_kinds'] ?? []);
        $debitSubAccount = $subAccounts[$debitRole] ?? null;
        $creditSubAccount = $subAccounts[$creditRole] ?? null;
        $debitor = ['value' => $amount, 'account_id' => $accounts[$debitRole]];
        $creditor = ['value' => $amount, 'account_id' => $accounts[$creditRole]];
        if (filled($taxIds[$debitRole] ?? null)) {
            $debitor['tax_id'] = (string) $taxIds[$debitRole];
        }
        if (filled($taxIds[$creditRole] ?? null)) {
            $creditor['tax_id'] = (string) $taxIds[$creditRole];
        }
        if ($debitSubAccount !== null) {
            $debitor['sub_account_id'] = $debitSubAccount;
        }
        if ($creditSubAccount !== null) {
            $creditor['sub_account_id'] = $creditSubAccount;
        }
        $debitInvoiceKind = (string) ($invoiceKinds[$debitRole] ?? '');
        $creditInvoiceKind = (string) ($invoiceKinds[$creditRole] ?? '');
        if ($debitInvoiceKind !== self::INVOICE_KIND_OMIT) {
            $debitor['invoice_kind'] = $debitInvoiceKind;
        }
        if ($creditInvoiceKind !== self::INVOICE_KIND_OMIT) {
            $creditor['invoice_kind'] = $creditInvoiceKind;
        }
        $branches[] = [
            'debitor' => $debitor,
            'creditor' => $creditor,
            'remark' => $remark,
        ];
    }

    private function request(AccountingConnection $connection): PendingRequest
    {
        $base = trim((string) config('furimadeck.accounting.oauth.money_forward.accounting_api_base'));

        return Http::acceptJson()->asJson()->withToken((string) $connection->access_token)->timeout(15)->baseUrl(rtrim($base, '/'));
    }

    private function failureDiagnostic(int $status, mixed $body, array $requestPayload = [], ?string $requestId = null): string
    {
        $errors = collect(data_get($body, 'errors', []));
        $codes = $errors
            ->pluck('code')
            ->filter(fn ($code): bool => is_string($code) && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', $code) === 1)
            ->unique()
            ->take(self::MAX_DIAGNOSTIC_ITEMS)
            ->map(fn (string $code): string => mb_substr($code, 0, 40))
            ->values()
            ->all();
        $messages = $errors
            ->flatMap(fn ($error): array => [data_get($error, 'message'), data_get($error, 'detail')])
            ->filter(fn ($message): bool => is_string($message))
            ->implode(' ');
        preg_match_all('/\bTarget:\s*([A-Za-z][A-Za-z0-9_.\[\]-]{0,79})/', $messages, $targetMatches);
        $targets = collect($targetMatches[1] ?? [])
            ->unique()
            ->take(self::MAX_DIAGNOSTIC_ITEMS)
            ->map(fn (string $target): string => mb_substr($target, 0, 40))
            ->values()
            ->all();
        $baseTargetValuePath = null;
        $baseTargetValueUnmapped = false;
        if (in_array('base', $targets, true)) {
            foreach ($errors as $error) {
                $errorMessage = collect([data_get($error, 'message'), data_get($error, 'detail')])
                    ->filter(fn ($message): bool => is_string($message))
                    ->implode(' ');
                if (preg_match('/\bTarget:\s*base\b/', $errorMessage) !== 1) {
                    continue;
                }

                $targetValue = $this->extractTargetValue($errorMessage);
                if ($targetValue === null) {
                    continue;
                }

                $baseTargetValuePath = $this->findPayloadValuePath($requestPayload, $targetValue);
                if ($baseTargetValuePath !== null) {
                    $baseTargetValuePath = preg_replace('/^journal\./', '', $baseTargetValuePath) ?? $baseTargetValuePath;
                    break;
                }

                $baseTargetValueUnmapped = true;
            }
        }
        $invoiceKindConflict = str_contains($messages, '免税事業者にインボイス区分を登録できません');

        $diagnostic = 'http_'.$status
            .($codes === [] ? '' : '_'.implode('_', $codes))
            .($targets === [] || $invoiceKindConflict ? '' : '_target_'.implode('_', $targets))
            .($baseTargetValuePath === null ? '' : '_value_'.mb_substr($baseTargetValuePath, 0, 32))
            .($baseTargetValuePath === null && $baseTargetValueUnmapped ? '_value_unmapped' : '')
            .($invoiceKindConflict ? '_invoice_kind_exempt_business_conflict' : '');

        return $requestId === null ? $diagnostic : $diagnostic.'|request_id='.$requestId;
    }

    private function requestId(Response $response): ?string
    {
        foreach (['X-Request-Id', 'Request-Id', 'X-Correlation-Id'] as $header) {
            $requestId = trim($response->header($header));
            if ($requestId !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $requestId) === 1) {
                return $requestId;
            }
        }

        return null;
    }

    private function extractTargetValue(string $message): ?string
    {
        if (preg_match('/\bTargetValue:\s*/', $message, $targetValueMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $valueStart = $targetValueMatch[0][1] + strlen($targetValueMatch[0][0]);
        $remaining = ltrim(substr($message, $valueStart));
        if ($remaining === '') {
            return null;
        }

        $firstCharacter = $remaining[0];
        if ($firstCharacter === '"') {
            if (preg_match('/^"((?:\\\\.|[^"\\\\])*)"/', $remaining, $quotedValue) === 1) {
                $decoded = json_decode('"'.$quotedValue[1].'"', true);

                return is_string($decoded) ? $decoded : $quotedValue[1];
            }
        }

        if ($firstCharacter === '{' || $firstCharacter === '[') {
            $closingCharacter = $firstCharacter === '{' ? '}' : ']';
            $depth = 0;
            $inString = false;
            $escaped = false;
            $length = strlen($remaining);
            for ($index = 0; $index < $length; $index++) {
                $character = $remaining[$index];
                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($character === '"') {
                    $inString = true;
                } elseif ($character === $firstCharacter) {
                    $depth++;
                } elseif ($character === $closingCharacter && --$depth === 0) {
                    return substr($remaining, 0, $index + 1);
                }
            }
        }

        if (preg_match('/^([^\s]+)/', $remaining, $unquotedValue) === 1) {
            return trim($unquotedValue[1], "\"'");
        }

        return null;
    }

    private function findPayloadValuePath(array $payload, string $targetValue, string $prefix = ''): ?string
    {
        $decodedTarget = json_decode(rawurldecode($targetValue), true);
        if (is_array($decodedTarget) && json_last_error() === JSON_ERROR_NONE) {
            $matchingStructures = $this->findPayloadStructurePaths($payload, $decodedTarget, $prefix);
            if (count($matchingStructures) === 1) {
                return $matchingStructures[0];
            }
        }

        $matchingValues = $this->findPayloadValuePaths($payload, $targetValue, $prefix);

        return count($matchingValues) === 1 ? $matchingValues[0] : null;
    }

    /** @return list<string> */
    private function findPayloadStructurePaths(array $payload, array $target, string $prefix = ''): array
    {
        $paths = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (! is_array($value)) {
                continue;
            }
            if ($this->normalizePayloadStructure($value) === $this->normalizePayloadStructure($target)) {
                $paths[] = $path;
            }
            $paths = [...$paths, ...$this->findPayloadStructurePaths($value, $target, $path)];
        }

        return array_values(array_unique($paths));
    }

    /** @return list<string> */
    private function findPayloadValuePaths(array $payload, string $targetValue, string $prefix = ''): array
    {
        $paths = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $paths = [...$paths, ...$this->findPayloadValuePaths($value, $targetValue, $path)];
            } elseif (is_scalar($value) && $this->payloadValueMatchesTarget((string) $value, $targetValue)) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    private function normalizePayloadStructure(array $value): array
    {
        foreach ($value as $key => $nestedValue) {
            if (is_array($nestedValue)) {
                $value[$key] = $this->normalizePayloadStructure($nestedValue);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function payloadValueMatchesTarget(string $payloadValue, string $targetValue): bool
    {
        return $payloadValue === $targetValue
            || rawurldecode($payloadValue) === $targetValue
            || $payloadValue === rawurldecode($targetValue)
            || rawurldecode($payloadValue) === rawurldecode($targetValue);
    }

    private function failureMessage(int $status, string $diagnostic): string
    {
        if (str_contains($diagnostic, 'invoice_kind_exempt_business_conflict')) {
            return "Money Forwardへの仕訳送信に失敗しました（HTTP {$status}）。Money Forward側の事業者が免税扱いの場合、インボイス区分はnullで送信してください。設定で「免税事業者（区分を送信しない）」を選択してください。事業者の扱いが誤っている場合は、Money Forward側の事業者設定を確認してください。診断コード: {$diagnostic}";
        }

        $reason = match ($status) {
            400 => '入力した勘定科目・税区分・仕訳日付などを確認してください。',
            401 => '連携の認証が切れている可能性があります。Money Forward連携をやり直してください。',
            403 => '仕訳登録の権限またはAPIスコープを確認してください。',
            409 => '対象の会計期間・事業者の状態をMoney Forward側で確認してください。',
            429 => 'Money Forward APIの利用制限です。時間をおいて再試行してください。',
            default => $status >= 500 ? 'Money Forward側で一時的なエラーが発生しました。時間をおいて再試行してください。' : 'Money Forwardが仕訳を受け付けませんでした。設定内容を確認してください。',
        };

        return "Money Forwardへの仕訳送信に失敗しました（HTTP {$status}）。{$reason} 診断コード: {$diagnostic}";
    }
}

class MoneyForwardSyncFailure extends RuntimeException
{
    public function __construct(string $message, public readonly string $diagnostic)
    {
        parent::__construct($message);
    }
}
