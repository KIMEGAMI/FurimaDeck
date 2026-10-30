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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckFreeeAccountingSyncService
{
    private const AMBIGUOUS_RESPONSE_STATUSES = [408, 425, 429];

    /** @var array<string, array{account_items: array<string, array<string, mixed>>, tax_codes: list<array{code: string, name: string, available: bool}>}> */
    private array $datedPurchaseMastersCache = [];

    /** @return list<string> */
    public function missingConfiguration(?AccountingConnection $connection = null): array
    {
        $config = $this->configuration($connection);
        $missing = [];
        foreach (['company_id' => 'company_id'] as $label => $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $name) {
            if (trim((string) (($config['tax_codes'] ?? [])[$name] ?? '')) === '') {
                $missing[] = 'tax_codes.'.$name;
            }
            if (trim((string) (($config['account_item_ids'] ?? [])[$name] ?? '')) === '') {
                $missing[] = 'account_item_ids.'.$name;
            }
        }
        $selectedAccountIds = array_map('strval', array_intersect_key((array) ($config['account_item_ids'] ?? []), array_flip(['settlement', 'sales', 'fees', 'cost', 'inventory'])));
        if (count($selectedAccountIds) === 5 && ! in_array('', $selectedAccountIds, true) && count(array_unique($selectedAccountIds)) !== 5) {
            $missing[] = 'account_item_ids.distinct_roles';
        }
        $taxCodes = array_map('strval', array_intersect_key((array) ($config['tax_codes'] ?? []), array_flip(['settlement', 'sales', 'fees', 'cost', 'inventory'])));
        if ($missing === [] && count(array_unique($taxCodes)) === 1) {
            $missing[] = 'tax_codes.distinct_roles';
        }

        return $missing;
    }

    public function send(User $user, AccountingEntry $entry, ?int $batchItemId = null): string
    {
        if (! app(FurimaDeckAccountingApiReleaseGate::class)->enabled()) {
            throw new RuntimeException('FurimaDeck 1.0では会計ソフトへのAPI仕訳送信を提供していません。会計ソフト用CSVを利用してください。');
        }
        abort_unless($entry->user_id === $user->id, 404);
        if ($entry->source_status !== 'confirmed' || $entry->confirmed_at === null) {
            throw new RuntimeException('Confirm済みの会計データだけ送信できます。');
        }
        app(FurimaDeckAccountingEntryService::class)->assertCurrent($entry);
        app(FurimaDeckAccountingEntryService::class)->assertProviderPurchaseReconciled($entry, 'freee');
        if ($batchItemId !== null && ! AccountingSyncBatchItem::query()
            ->whereKey($batchItemId)
            ->where('accounting_entry_id', $entry->id)
            ->whereHas('batch', fn ($query) => $query->where('user_id', $user->id)->where('provider', 'freee'))
            ->exists()) {
            throw new RuntimeException('一括送信の対象データを確認できません。');
        }
        $existingStatus = $entry->providerSyncStatus('freee');
        $sendableStatuses = $batchItemId === null ? ['not_synced', 'failed'] : ['queued'];
        if (! in_array($existingStatus, $sendableStatuses, true)) {
            throw new RuntimeException(in_array($existingStatus, ['sync_unknown', 'legacy_unknown'], true)
                ? 'freee側の送信結果を確認してください。確認が終わるまで再送できません。'
                : 'この会計データはすでに送信済み、または処理中です。');
        }
        $existingSync = $entry->providerSync('freee');
        if (filled($existingSync->external_transaction_id) || $existingSync->synced_at !== null) {
            throw new RuntimeException('freeeの外部仕訳IDまたは同期済み記録が残っています。二重登録防止のため再送できません。');
        }
        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'freee');
        $config = $this->configuration($connection);
        $companyId = trim((string) ($config['company_id'] ?? ''));
        $providerScopeHash = app(FurimaDeckAccountingProviderScopeService::class)->fingerprint('freee', $companyId);
        if ($providerScopeHash === null) {
            throw new RuntimeException('freeeの送信先事業所を特定できません。事業所情報を確認するまで送信しません。');
        }
        $accountIds = (array) ($config['account_item_ids'] ?? []);
        if ($this->missingConfiguration($connection) !== []) {
            throw new RuntimeException('freee連携設定が不足しています。');
        }
        if ($entry->entry_type !== AccountingEntry::TYPE_PURCHASE) {
            foreach (['shipping_cost' => 'shipping', 'other_cost' => 'other'] as $field => $role) {
                if ((int) $entry->{$field} > 0 && (
                    trim((string) ($accountIds[$role] ?? '')) === ''
                    || trim((string) (($config['tax_codes'] ?? [])[$role] ?? '')) === ''
                )) {
                    throw new RuntimeException('freeeの'.$role.'用勘定科目と税区分を設定してください。仕訳は送信していません。');
                }
            }
        }
        $taxCodes = $entry->entry_type === AccountingEntry::TYPE_PURCHASE
            ? $this->validatedPurchaseTaxCodesForDate($connection, $companyId, $accountIds, $entry)
            : $this->validatedTaxCodesForDate($connection, $companyId, $accountIds, $entry);
        if ($entry->entry_type !== AccountingEntry::TYPE_PURCHASE) {
            $taxCodes = array_merge($taxCodes, $this->validatedSaleExpenseTaxCodesForDate($connection, $companyId, $accountIds, $entry, $config));
        }
        if ($entry->source_status !== 'confirmed' || $entry->confirmed_at === null) {
            throw new RuntimeException('Confirm済みの会計データだけ送信できます。');
        }

        [$sync, $entry] = DB::connection('furimadeck')->transaction(function () use ($user, $entry, $batchItemId, $companyId, $accountIds, $taxCodes, $config, $providerScopeHash): array {
            if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                $related = Product::query()->whereKey($entry->product_id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            } else {
                $related = Sale::query()->whereKey($entry->sale_id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            }
            $locked = $user->accountingEntries()->eligibleForAccounting()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                $locked->setRelation('product', $related);
            } else {
                $locked->setRelation('sale', $related->loadMissing('marketplace'));
            }
            if ($locked->source_status !== 'confirmed' || $locked->confirmed_at === null) {
                throw new RuntimeException('Confirm済みの会計データだけ送信できます。');
            }
            app(FurimaDeckAccountingEntryService::class)->assertCurrent($locked);
            app(FurimaDeckAccountingEntryService::class)->assertProviderPurchaseReconciled($locked, 'freee');
            $sync = $locked->providerSyncs()->firstOrCreate(
                ['provider' => 'freee'],
                ['status' => $locked->providerSyncInitialStatus('freee')],
            );
            if (filled($sync->external_transaction_id) || $sync->synced_at !== null) {
                throw new RuntimeException('freeeの外部仕訳IDまたは同期済み記録が残っています。二重登録防止のため再送できません。');
            }
            $allowedStatuses = $batchItemId === null ? ['not_synced', 'failed'] : ['queued'];
            if (! in_array($sync->status, $allowedStatuses, true)) {
                throw new RuntimeException($sync->status === 'sync_unknown' || $sync->status === 'legacy_unknown'
                    ? 'freee側の送信結果を確認してください。確認が終わるまで再送できません。'
                    : 'この会計データはfreeeへ送信済み、または処理中です。');
            }
            $sync->update([
                'status' => 'sending',
                'attempt_count' => $sync->attempt_count + 1,
                'attempted_at' => now(),
                'last_error' => null,
                'verified_at' => null,
                'provider_account_scope_hash' => $providerScopeHash,
            ]);
            $payload = $this->payload($locked, $companyId, $accountIds, $taxCodes, $config);
            $sync->setAccountingPayload(
                $payload,
                $locked->entry_type === AccountingEntry::TYPE_PURCHASE && $locked->purchase_special_case_data !== null,
            );
            $sync->save();

            return [$sync, $locked];
        });

        try {
            $response = Http::acceptJson()
                ->withToken((string) $connection->access_token)
                ->timeout(15)
                ->post(rtrim((string) ($config['api_base'] ?? ''), '/').'/api/1/manual_journals', $sync->accountingPayload());
        } catch (ConnectionException $exception) {
            $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'network_error');
            throw new RuntimeException('freeeとの通信結果を確認できません。二重登録防止のため、freee側を確認するまで再送できません。', previous: $exception);
        } catch (\Throwable $exception) {
            $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'sync_unknown');
            throw new RuntimeException('freeeへの送信結果を確認できません。freee側を確認するまで再送できません。', previous: $exception);
        }

        if ($response->status() === 401 && $this->freeeErrorCode($response) === 'expired_access_token') {
            try {
                $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded(
                    $user,
                    'freee',
                    true,
                    (string) $connection->access_token,
                );
            } catch (\Throwable $exception) {
                $this->markFailure($sync, $entry, $connection, 'failed', 'http_401_token_refresh_failed');
                throw new RuntimeException('freeeの認証を更新できませんでした。再連携してください。仕訳は再送していません。', previous: $exception);
            }

            try {
                $response = Http::acceptJson()
                    ->withToken((string) $connection->access_token)
                    ->timeout(15)
                    ->post(rtrim((string) ($config['api_base'] ?? ''), '/').'/api/1/manual_journals', $sync->accountingPayload());
            } catch (ConnectionException $exception) {
                $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'network_error_after_auth_refresh');
                throw new RuntimeException('認証更新後のfreee送信結果を確認できません。二重登録防止のため、freee側を確認するまで再送できません。', previous: $exception);
            } catch (\Throwable $exception) {
                $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'sync_unknown_after_auth_refresh');
                throw new RuntimeException('認証更新後のfreee送信結果を確認できません。freee側を確認するまで再送できません。', previous: $exception);
            }
        }

        if ($response->failed()) {
            $status = $response->serverError() || in_array($response->status(), self::AMBIGUOUS_RESPONSE_STATUSES, true)
                ? 'sync_unknown'
                : 'failed';
            $errorCode = $this->freeeErrorCode($response);
            $diagnostic = 'http_'.$response->status().($errorCode === null ? '' : '_'.$errorCode);
            if ($response->status() === 401 && in_array($errorCode, ['expired_access_token', 're_authorization_required'], true)) {
                app(FurimaDeckAccountingOAuthService::class)->markExpiredIfCurrentTokenMatches(
                    $user,
                    'freee',
                    (string) $connection->access_token,
                    $errorCode,
                );
            }
            $this->markFailure($sync, $entry, $connection, $status, $diagnostic);
            $message = $status === 'sync_unknown'
                ? 'freee側の応答が不確定です。二重登録防止のため、freee側を確認するまで再送できません。'
                : $this->freeeFailureMessage($response->status(), $errorCode);
            throw new RuntimeException($message);
        }

        $externalId = data_get($response->json(), 'manual_journal.id');
        if (! is_scalar($externalId) || trim((string) $externalId) === '') {
            $this->markFailure($sync, $entry, $connection, 'sync_unknown', 'sync_response_invalid');
            throw new RuntimeException('freeeの送信応答に外部IDがありません。二重登録防止のため、freee側を確認してください。');
        }

        DB::connection('furimadeck')->transaction(function () use ($sync, $entry, $connection, $externalId): void {
            $locked = AccountingEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $lockedSync = $locked->providerSyncs()->whereKey($sync->id)->where('provider', 'freee')->lockForUpdate()->firstOrFail();
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

    private function freeeErrorCode(Response $response): ?string
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        $codes = [data_get($payload, 'code')];
        foreach ((array) ($payload['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $codes = [...$codes, ...(array) ($error['codes'] ?? [])];
            }
        }

        foreach ($codes as $code) {
            if (is_string($code) && preg_match('/\A[a-z0-9_]{1,64}\z/', $code) === 1) {
                return $code;
            }
        }

        return null;
    }

    private function freeeFailureMessage(int $status, ?string $errorCode): string
    {
        if ($status !== 401) {
            return 'freeeへの会計データ送信に失敗しました。設定を確認後、再送できます。';
        }

        return match ($errorCode) {
            'expired_access_token' => 'freeeのアクセストークンが更新後も無効です。再連携してください。仕訳は登録されていません。',
            're_authorization_required' => 'freeeアプリの権限変更により再認可が必要です。freeeと再連携してください。仕訳は登録されていません。',
            'user_do_not_have_permission' => 'freee事業所で連携ユーザーに必要な権限がありません。事業所管理者に確認してください。仕訳は登録されていません。',
            'freee_plan_limit', 'not_available_plan_limited_app' => 'freeeの現在のプランではこのAPIを利用できません。プランを確認してください。仕訳は登録されていません。',
            'company_not_found' => 'freeeの事業所設定を確認できません。仕訳設定で事業所を確認してください。仕訳は登録されていません。',
            'source_ip_address_limit' => 'freee事業所の接続元IP制限で拒否されました。事業所管理者に確認してください。仕訳は登録されていません。',
            default => 'freeeが認証または権限を拒否しました。連携設定と事業所権限を確認してください。仕訳は登録されていません。',
        };
    }

    /** @param array<string, string> $accountIds
     * @return array<string, string>
     */
    public function validatedTaxCodesForDate(AccountingConnection $connection, string $companyId, array $accountIds, AccountingEntry $entry): array
    {
        $baseUrl = rtrim((string) config('furimadeck.accounting.freee_sync.api_base'), '/');
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('freee APIの接続先設定が正しくありません。');
        }

        try {
            $response = app(FurimaDeckFreeeApiReadService::class)->get(
                $connection,
                $baseUrl.'/api/1/account_items',
                ['company_id' => (int) $companyId, 'base_date' => $entry->transaction_date->format('Y-m-d')],
            );
        } catch (ConnectionException $exception) {
            throw new RuntimeException('freeeで取引日の税区分を確認できません。仕訳は送信していません。', previous: $exception);
        }

        $items = $response->json('account_items');
        if ($response->failed() || ! is_array($items)) {
            throw new RuntimeException('freeeで取引日の税区分を確認できません。仕訳は送信していません。');
        }

        $taxCodes = [];
        $confirmedTaxCodes = (array) ($this->configuration($connection)['tax_codes'] ?? []);
        foreach ($accountIds as $role => $id) {
            $item = collect($items)->first(fn (mixed $candidate): bool => is_array($candidate) && (string) ($candidate['id'] ?? '') === (string) $id);
            $code = is_array($item) ? $item['default_tax_code'] ?? null : null;
            if (! is_array($item) || ($item['available'] ?? true) === false || ! is_scalar($code) || ! ctype_digit((string) $code)) {
                throw new RuntimeException('freeeで取引日の勘定科目・税区分を確認できません。仕訳は送信していません。');
            }
            if ((string) $code !== (string) ($confirmedTaxCodes[$role] ?? '')) {
                throw new RuntimeException('freeeの取引日時点の税区分が確認済み設定と異なります。会計上の扱いを確認してください。仕訳は送信していません。');
            }
            $taxCodes[$role] = (string) $code;
        }
        if (count(array_unique($taxCodes)) === 1) {
            throw new RuntimeException('freeeで取得した全科目の税区分が同じです。仕訳は送信していません。');
        }

        return $taxCodes;
    }

    /** @param array<string, string> $accountIds @param array<string, mixed> $settings @return array<string, string> */
    public function validatedSaleExpenseTaxCodesForDate(AccountingConnection $connection, string $companyId, array $accountIds, AccountingEntry $entry, array $settings): array
    {
        $details = app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails($entry, 'freee', $settings);
        if ($details === []) {
            return [];
        }
        $expenseAccountId = trim((string) ($accountIds['other'] ?? ''));
        if (! ctype_digit($expenseAccountId)) {
            throw new RuntimeException('freeeの別途費用用勘定科目を設定してください。仕訳は送信していません。');
        }
        $configuredBaseUrl = trim((string) ($settings['api_base'] ?? config('furimadeck.accounting.freee_sync.api_base')));
        if (! filter_var($configuredBaseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('freee APIの接続先設定が正しくありません。');
        }
        $masters = $this->datedPurchaseMasters(
            $connection,
            $companyId,
            rtrim($configuredBaseUrl, '/'),
            $entry->transaction_date->format('Y-m-d'),
        );
        $expenseAccount = $masters['account_items'][$expenseAccountId] ?? null;
        $expenseDefaultTax = is_array($expenseAccount) ? $expenseAccount['default_tax_code'] ?? null : null;
        if (! is_array($expenseAccount) || ($expenseAccount['available'] ?? true) === false
            || ! is_scalar($expenseDefaultTax) || ! ctype_digit((string) $expenseDefaultTax)) {
            throw new RuntimeException('freeeの別途費用科目または標準税区分を確認できません。仕訳は送信していません。');
        }
        $confirmedExpenseTax = trim((string) data_get($settings, 'tax_codes.other', ''));
        if ($confirmedExpenseTax === '' || $confirmedExpenseTax !== (string) $expenseDefaultTax) {
            throw new RuntimeException('freeeの別途費用科目の取引日税区分が保存済み設定と異なります。仕訳は送信していません。仕訳設定を再確認してください。');
        }

        $resolved = [];
        $resolver = app(FurimaDeckFreeePurchaseTaxCodeResolver::class);
        foreach ($details as $field => $detail) {
            $sourceId = (string) $detail['source_id'];
            $sourceAccount = $masters['account_items'][$sourceId] ?? null;
            $sourceTaxCode = is_array($sourceAccount) ? $sourceAccount['default_tax_code'] ?? null : null;
            $sourceCategories = (array) (is_array($sourceAccount) ? ($sourceAccount['categories'] ?? []) : []);
            if (! is_array($sourceAccount) || ($sourceAccount['available'] ?? true) === false
                || (! in_array('資産', $sourceCategories, true) && ! in_array('負債', $sourceCategories, true))
                || ! is_scalar($sourceTaxCode) || ! ctype_digit((string) $sourceTaxCode)) {
                throw new RuntimeException('freeeの費目別支払元またはその取引日税区分を確認できません。仕訳は送信していません。');
            }
            $evidence = $detail['evidence'];
            $resolved['sale_expense_'.$field] = $resolver->resolve(
                $masters['tax_codes'],
                (string) $expenseDefaultTax,
                (string) ($evidence['tax_category'] ?? ''),
                (string) ($evidence['invoice_issuer'] ?? ''),
                $entry->transaction_date->format('Y-m-d'),
            );
            $resolved['sale_payment_'.$field] = (string) $sourceTaxCode;
        }

        return $resolved;
    }

    /** @param array<string, string> $accountIds @return array<string, string> */
    public function validatedPurchaseTaxCodesForDate(AccountingConnection $connection, string $companyId, array $accountIds, AccountingEntry $entry): array
    {
        app(FurimaDeckPurchaseSpecialCaseService::class)->validatedFields($entry);
        $settings = $this->configuration($connection);
        $baseUrl = rtrim((string) ($settings['api_base'] ?? ''), '/');
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('freee APIの接続先設定が正しくありません。');
        }

        $breakdown = (array) $entry->expense_breakdown;
        $sourceId = trim((string) data_get($breakdown, 'payment_sources.freee', ''));
        $purchasePaymentAccounts = (array) data_get($connection->settings, 'purchase_payment_accounts', []);
        $source = (array) ($purchasePaymentAccounts[$sourceId] ?? []);
        $inventoryId = trim((string) ($accountIds['inventory'] ?? ''));
        if (! ctype_digit($sourceId) || $source === [] || (string) ($source['account_id'] ?? '') !== $sourceId
            || ! ctype_digit($inventoryId)) {
            throw new RuntimeException('freeeの仕入用支払元または商品在庫科目が未設定です。仕訳は送信していません。');
        }

        $purchaseMasters = $this->datedPurchaseMasters($connection, $companyId, $baseUrl, $entry->transaction_date->format('Y-m-d'));
        $inventory = $purchaseMasters['account_items'][$inventoryId] ?? null;
        $paymentAccount = $purchaseMasters['account_items'][$sourceId] ?? null;
        $paymentCategories = (array) ($paymentAccount['categories'] ?? []);
        $paymentTaxCode = $paymentAccount['default_tax_code'] ?? null;
        $inventoryDefaultTaxCode = $inventory['default_tax_code'] ?? null;
        if (! is_array($inventory) || ($inventory['available'] ?? true) === false
            || ! is_array($paymentAccount) || ($paymentAccount['available'] ?? true) === false
            || (! in_array('資産', $paymentCategories, true) && ! in_array('負債', $paymentCategories, true))
            || ! is_scalar($paymentTaxCode) || ! ctype_digit((string) $paymentTaxCode)
            || ! is_scalar($inventoryDefaultTaxCode) || ! ctype_digit((string) $inventoryDefaultTaxCode)) {
            throw new RuntimeException('freeeの仕入用支払元または税区分が無効です。仕訳は送信していません。');
        }

        $taxCodes = $purchaseMasters['tax_codes'];
        $evidence = (array) ($breakdown['evidence'] ?? []);
        $amounts = [
            'purchase_unit_cost' => (int) $entry->purchase_cost,
            'purchase_shipping_cost' => (int) ($breakdown['purchase_shipping_cost'] ?? 0),
            'other_purchase_expense' => (int) ($breakdown['other_purchase_expense'] ?? 0),
        ];
        $unitCost = (int) ($breakdown['purchase_unit_cost'] ?? 0);
        $quantity = (int) ($breakdown['purchase_quantity'] ?? 0);
        if (min($amounts) < 0
            || $unitCost <= 0
            || $quantity !== 1
            || $amounts['purchase_unit_cost'] !== $unitCost * $quantity
            || (int) $entry->other_cost !== $amounts['purchase_shipping_cost'] + $amounts['other_purchase_expense']) {
            throw new RuntimeException('仕入金額の内訳が会計明細と一致しません。仕訳は送信していません。');
        }

        $resolver = app(FurimaDeckFreeePurchaseTaxCodeResolver::class);
        $resolved = ['purchase_payment' => (string) $paymentTaxCode];
        foreach ($amounts as $expense => $amount) {
            if ($amount === 0) {
                continue;
            }
            $receipt = (array) ($evidence[$expense] ?? []);
            $resolved[$expense] = $resolver->resolve(
                $taxCodes,
                (string) $inventoryDefaultTaxCode,
                (string) ($receipt['tax_category'] ?? ''),
                (string) ($receipt['invoice_issuer'] ?? ''),
                $entry->transaction_date->format('Y-m-d'),
                $expense === 'purchase_unit_cost' && $entry->purchase_special_case_data !== null,
            );
        }

        return $resolved;
    }

    /** @return array{account_items: array<string, array<string, mixed>>, tax_codes: list<array{code: string, name: string, available: bool}>} */
    private function datedPurchaseMasters(AccountingConnection $connection, string $companyId, string $baseUrl, string $date): array
    {
        $key = (string) $connection->getKey().'|'.$companyId.'|'.$date;
        if (isset($this->datedPurchaseMastersCache[$key])) {
            return $this->datedPurchaseMastersCache[$key];
        }

        try {
            $accountResponse = app(FurimaDeckFreeeApiReadService::class)->get(
                $connection,
                $baseUrl.'/api/1/account_items',
                ['company_id' => (int) $companyId, 'base_date' => $date],
            );
        } catch (ConnectionException $exception) {
            throw new RuntimeException('freeeで仕入日の勘定科目と税区分を確認できません。仕訳は送信していません。', previous: $exception);
        }
        $accountItems = $accountResponse->json('account_items');
        if ($accountResponse->failed() || ! is_array($accountItems)) {
            throw new RuntimeException('freeeで仕入日の勘定科目と税区分を確認できません。仕訳は送信していません。');
        }

        try {
            $taxResponse = app(FurimaDeckFreeeApiReadService::class)->get(
                $connection,
                $baseUrl.'/api/1/taxes/companies/'.(int) $companyId,
            );
        } catch (ConnectionException $exception) {
            throw new RuntimeException('freeeの仕入税区分を確認できません。仕訳は送信していません。', previous: $exception);
        }
        $taxItems = $taxResponse->json('taxes') ?? $taxResponse->json('tax_codes');
        if ($taxResponse->failed() || ! is_array($taxItems)) {
            throw new RuntimeException('freeeの仕入税区分を確認できません。仕訳は送信していません。');
        }

        return $this->datedPurchaseMastersCache[$key] = [
            'account_items' => collect($accountItems)
                ->filter(static fn (mixed $item): bool => is_array($item))
                ->keyBy(static fn (array $item): string => (string) ($item['id'] ?? ''))
                ->all(),
            'tax_codes' => collect($taxItems)
                ->filter(static fn (mixed $tax): bool => is_array($tax))
                ->map(static fn (array $tax): array => [
                    'code' => (string) ($tax['code'] ?? $tax['tax_code'] ?? ''),
                    'name' => (string) ($tax['name'] ?? $tax['tax_name'] ?? ''),
                    'available' => ($tax['available'] ?? true) === true,
                ])
                ->filter(static fn (array $tax): bool => $tax['code'] !== '' && $tax['name'] !== '')
                ->values()
                ->all(),
        ];
    }

    private function markFailure(AccountingEntrySync $sync, AccountingEntry $entry, AccountingConnection $connection, string $status, string $diagnostic): void
    {
        DB::connection('furimadeck')->transaction(function () use ($sync, $entry, $connection, $status, $diagnostic): void {
            $locked = AccountingEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $lockedSync = $locked->providerSyncs()->whereKey($sync->id)->where('provider', 'freee')->lockForUpdate()->firstOrFail();
            $alreadySucceeded = $lockedSync->status === 'synced' && filled($lockedSync->external_transaction_id);
            if (! $alreadySucceeded) {
                $lockedSync->update(['status' => $status, 'last_error' => mb_substr($diagnostic, 0, AccountingEntrySync::LAST_ERROR_LENGTH)]);
            }
            $locked->refreshLegacySyncStatus();
            $connection->update(['last_error' => $alreadySucceeded ? null : mb_substr($diagnostic, 0, AccountingEntrySync::LAST_ERROR_LENGTH)]);
        });
    }

    /** @return array{company_id:string,tax_codes:array<string,string>,account_item_ids:array<string,string>,purchase_payment_accounts:array<string,mixed>,api_base:string} */
    private function configuration(?AccountingConnection $connection = null): array
    {
        $config = (array) config('furimadeck.accounting.freee_sync');
        $saved = (array) ($connection?->settings ?? []);

        return [
            'api_base' => (string) ($config['api_base'] ?? ''),
            'company_id' => (string) ($saved['company_id'] ?? $config['company_id'] ?? ''),
            'tax_codes' => array_merge(
                array_map('strval', (array) ($config['tax_codes'] ?? [])),
                array_map('strval', (array) ($saved['tax_codes'] ?? [])),
            ),
            'account_item_ids' => array_merge(
                array_map('strval', (array) ($config['account_item_ids'] ?? [])),
                array_map('strval', (array) ($saved['account_item_ids'] ?? [])),
            ),
            'purchase_payment_accounts' => (array) ($saved['purchase_payment_accounts'] ?? []),
        ];
    }

    /** @return array<int, array<string, int|string>> */
    private function details(AccountingEntry $entry, array $accountIds, array $taxCodes, array $settings): array
    {
        if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
            return $this->purchaseDetails($entry, $accountIds, $taxCodes, $settings);
        }

        $details = [];
        $this->addPair($details, $accountIds, $taxCodes, 'settlement', 'sales', (int) $entry->sale_amount, $entry->title.' 売上');
        foreach (['selling_fee' => '販売手数料', 'shipping_cost' => '送料'] as $field => $label) {
            $amount = (int) $entry->{$field};
            if ($amount > 0) {
                $role = $field === 'shipping_cost' ? 'shipping' : 'fees';
                $this->addPair($details, $accountIds, $taxCodes, $role, 'settlement', $amount, $entry->title.' '.$label);
            }
        }
        $operatingExpenses = app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails($entry, 'freee', $settings);
        $breakdown = (array) $entry->expense_breakdown;
        foreach ($operatingExpenses as $field => $expense) {
            $amount = (int) ($breakdown[$field] ?? 0);
            if ($amount <= 0) {
                throw new RuntimeException('freeeの費目別金額が会計明細と一致しません。仕訳は送信していません。');
            }
            $expenseTaxCode = trim((string) ($taxCodes['sale_expense_'.$field] ?? ''));
            $paymentTaxCode = trim((string) ($taxCodes['sale_payment_'.$field] ?? ''));
            if ($expenseTaxCode === '' || $paymentTaxCode === '') {
                throw new RuntimeException('freeeの費目別税区分が不足しています。仕訳は送信していません。');
            }
            $description = $entry->title.' '.FurimaDeckSaleExpenseAccountingService::EXPENSES[$field];
            $capMemo = app(FurimaDeckInvoiceTransitionCapService::class)->accountingMemoForEvidence(
                (int) $entry->user_id,
                $entry->transaction_date->toDateString(),
                $expense['evidence'],
            );
            $description = app(FurimaDeckInvoiceTransitionCapService::class)->appendAccountingMemo($description, $capMemo);
            $details[] = [
                'entry_side' => 'debit',
                'account_item_id' => (int) ($accountIds['other'] ?? 0),
                'tax_code' => (int) $expenseTaxCode,
                'amount' => $amount,
                'description' => $description,
            ];
            $details[] = [
                'entry_side' => 'credit',
                'account_item_id' => (int) ($expense['source']['account_id'] ?? 0),
                'tax_code' => (int) $paymentTaxCode,
                'amount' => $amount,
                'description' => $description,
            ];
        }
        $cost = app(FurimaDeckAccountingPurchaseReconciliationService::class)
            ->costOfGoodsSoldForSale($entry, 'freee');
        if ($cost > 0) {
            $this->addPair($details, $accountIds, $taxCodes, 'cost', 'inventory', $cost, $entry->title.' 原価');
        }

        return $details;
    }

    /** @param array<string, string> $accountIds
     * @param  array<string, string>  $taxCodes
     * @return array<string, mixed>
     */
    private function payload(AccountingEntry $entry, string $companyId, array $accountIds, array $taxCodes, array $settings): array
    {
        $payload = [
            'company_id' => (int) $companyId,
            'issue_date' => $entry->transaction_date->format('Y-m-d'),
            'adjustment' => false,
            'details' => $this->details($entry, $accountIds, $taxCodes, $settings),
        ];
        $referenceNumber = $this->freeeReferenceNumber((string) $entry->management_id);
        if ($referenceNumber !== null) {
            $payload['ref_number'] = $referenceNumber;
        }

        return $payload;
    }

    /** @param array<string, string> $accountIds @param array<string, string> $taxCodes @return array<int, array<string, int|string>> */
    private function purchaseDetails(AccountingEntry $entry, array $accountIds, array $taxCodes, array $settings): array
    {
        $breakdown = (array) $entry->expense_breakdown;
        $sourceId = trim((string) data_get($breakdown, 'payment_sources.freee', ''));
        $source = (array) (((array) ($settings['purchase_payment_accounts'] ?? []))[$sourceId] ?? []);
        if (! ctype_digit($sourceId) || (string) ($source['account_id'] ?? '') !== $sourceId) {
            throw new RuntimeException('freeeの仕入用支払元が有効ではありません。仕訳は送信していません。');
        }

        $special = $entry->purchase_special_case_data;
        $lines = [
            'purchase_unit_cost' => [(int) $entry->purchase_cost, '商品仕入'],
            'purchase_shipping_cost' => [(int) ($breakdown['purchase_shipping_cost'] ?? 0), '仕入送料'],
            'other_purchase_expense' => [(int) ($breakdown['other_purchase_expense'] ?? 0), 'その他仕入費'],
        ];
        $details = [];
        foreach ($lines as $expense => [$amount, $label]) {
            if ($amount <= 0) {
                continue;
            }
            $taxCode = trim((string) ($taxCodes[$expense] ?? ''));
            $paymentTaxCode = trim((string) ($taxCodes['purchase_payment'] ?? ''));
            if ($taxCode === '' || $paymentTaxCode === '') {
                throw new RuntimeException('freeeの仕入税区分が不足しています。仕訳は送信していません。');
            }
            $isAntiqueDealerException = $special !== null && $expense === 'purchase_unit_cost';
            $evidence = (array) data_get($breakdown, 'evidence.'.$expense, []);
            $capMemo = app(FurimaDeckInvoiceTransitionCapService::class)->accountingMemoForEvidence(
                (int) $entry->user_id,
                $entry->transaction_date->toDateString(),
                $evidence,
                $isAntiqueDealerException,
            );
            $description = $this->purchaseDescription($entry, $label, $isAntiqueDealerException ? $special : null, $capMemo);
            $details[] = [
                'entry_side' => 'debit',
                'account_item_id' => (int) ($accountIds['inventory'] ?? 0),
                'tax_code' => (int) $taxCode,
                'amount' => $amount,
                'description' => $description,
            ];
            $details[] = [
                'entry_side' => 'credit',
                'account_item_id' => (int) $sourceId,
                'tax_code' => (int) $paymentTaxCode,
                'amount' => $amount,
                'description' => $description,
            ];
        }

        if ($details === []) {
            throw new RuntimeException('仕入金額が0円のためfreee仕訳を作成できません。');
        }

        return $details;
    }

    /** @param array<string, mixed>|null $special @return non-empty-string */
    private function purchaseDescription(AccountingEntry $entry, string $label, ?array $special, ?string $capMemo): string
    {
        $normalizer = app(FurimaDeckPurchaseSpecialCaseService::class);
        $parts = ['FurimaDeck仕入', $normalizer->singleLine((string) $entry->management_id), $normalizer->singleLine((string) $entry->title), $label];
        if ($special !== null) {
            $fields = $normalizer->validatedFields($entry);
            if ($fields === null) {
                throw new RuntimeException('古物商等特例を確認できないためfreee仕訳を作成できません。');
            }
            $parts[] = '古物商等特例';
            $parts[] = '許可番号:'.$fields['license_number'];
            $parts[] = '仕入先:'.$fields['counterparty_name'];
            $parts[] = '住所:'.$fields['counterparty_address'];
        }
        $description = implode(' / ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        $description = app(FurimaDeckInvoiceTransitionCapService::class)->appendAccountingMemo($description, $capMemo);
        if (mb_strlen($description, 'UTF-8') > 200) {
            throw new RuntimeException('freeeの仕入摘要が200文字を超えるため仕訳を送信できません。商品名または特例情報を確認してください。');
        }

        return $description;
    }

    private function freeeReferenceNumber(string $managementId): ?string
    {
        $managementId = trim($managementId);
        if ($managementId === '') {
            return null;
        }
        if (mb_strlen($managementId, 'UTF-8') <= 20) {
            return $managementId;
        }

        return 'FD-'.strtoupper(substr(hash('sha256', $managementId), 0, 17));
    }

    private function addPair(array &$details, array $accountIds, array $taxCodes, string $debit, string $credit, int $amount, string $description): void
    {
        if ($amount <= 0) {
            return;
        }
        foreach ([['debit', $debit], ['credit', $credit]] as [$side, $account]) {
            $details[] = [
                'entry_side' => $side,
                'account_item_id' => (int) $accountIds[$account],
                'tax_code' => (int) $taxCodes[$account],
                'amount' => $amount,
                'description' => $description,
            ];
        }
    }
}
