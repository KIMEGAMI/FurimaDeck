<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class FurimaDeckAccountingJournalVerificationService
{
    public function __construct(private readonly FurimaDeckAccountingJournalComparator $comparator) {}

    public function verify(User $user, AccountingEntry $entry, string $provider): bool
    {
        abort_unless($entry->user_id === $user->id, 404);
        if (! in_array($provider, ['freee', 'money_forward'], true)) {
            abort(404);
        }
        $this->assertProviderApiReadbackEnabled();

        $sync = $entry->providerSyncs()->where('provider', $provider)->first();
        if ($sync === null || $sync->status !== 'synced' || ! is_array($sync->accountingPayload())
            || ! is_string($sync->external_transaction_id) || $sync->external_transaction_id === '') {
            throw new RuntimeException('送信時の内容と外部仕訳IDが揃っていないため、自動照合できません。');
        }
        $remote = $this->fetchRemote($user, $provider, $sync, $sync->external_transaction_id);
        if ($remote === null) {
            $this->saveResult($user, $provider, $sync, false, 'external_journal_missing');
            throw new RuntimeException('会計ソフト側に仕訳が見つかりません。送信履歴は保持し、再送はしません。');
        }
        $matches = $this->matchesRemote($provider, $sync, $sync->external_transaction_id, $remote);
        $remoteId = is_scalar($remote['id'] ?? null) ? (string) $remote['id'] : '';
        if (! $matches && $provider === 'money_forward' && $remoteId === $sync->external_transaction_id
            && $this->comparator->moneyForwardUnsentInvoiceKindDefaultFields(
                $sync->accountingPayload() ?? [],
                $remote,
                config('furimadeck.accounting.oauth.money_forward.application_name'),
            ) !== []) {
            $this->saveResult($user, $provider, $sync, false, AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED, $remote);
            throw new RuntimeException('Money Forwardの読戻しで、送信していないインボイス区分「対象外」が返りました。ほかの送信項目は一致していますが、この区分が正しいか確認できないため未照合とし、登録済み仕訳の修正・再送は行っていません。');
        }
        $diagnostic = $matches ? null : $this->mismatchDiagnostic($provider, $sync, $sync->external_transaction_id, $remote);
        $this->saveResult($user, $provider, $sync, $matches, $diagnostic, $remote);

        return $matches;
    }

    public function confirmMoneyForwardDefaultInvoiceKind(User $user, AccountingEntry $entry, bool $confirmedCorrect, bool $confirmedNoResend): void
    {
        abort_unless($entry->user_id === $user->id, 404);
        $this->assertProviderApiReadbackEnabled();

        if (! $confirmedCorrect || ! $confirmedNoResend) {
            throw new RuntimeException('Money Forward側の区分が正しいことと、再送しないことを確認してください。記録は変更していません。');
        }

        $sync = $entry->providerSyncs()->where('provider', 'money_forward')->first();
        if ($sync === null || $sync->status !== 'synced'
            || ! $sync->hasUnverifiedProviderInvoiceKindDefault()
            || ! is_array($sync->accountingPayload())
            || blank($sync->external_transaction_id)
            || blank($sync->provider_account_scope_hash)) {
            throw new RuntimeException('Money Forwardの既定区分が未照合で、事業者と仕訳IDが記録された明細だけが対象です。');
        }

        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward');
        $businessStatus = app(FurimaDeckMoneyForwardBusinessPeriodService::class)
            ->statusForDate((array) ($connection->settings ?? []), $entry->transaction_date?->toDateString() ?? '');
        $confirmedBusinessStatuses = array_values(array_diff(
            FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES,
            [FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED],
        ));
        if (! in_array($businessStatus, $confirmedBusinessStatuses, true)) {
            throw new RuntimeException('Money Forwardの課税・免税状態が未確認です。区分を確認するまで手動確認を記録できません。');
        }

        $remote = $this->fetchRemote($user, 'money_forward', $sync, (string) $sync->external_transaction_id, $connection);
        $remoteId = is_array($remote) && is_scalar($remote['id'] ?? null) ? (string) $remote['id'] : '';
        $defaultFields = is_array($remote) && $remoteId === $sync->external_transaction_id
            ? $this->comparator->moneyForwardUnsentInvoiceKindDefaultFields(
                $sync->accountingPayload() ?? [],
                $remote,
                config('furimadeck.accounting.oauth.money_forward.application_name'),
            )
            : [];
        if ($remote === null || $remoteId !== $sync->external_transaction_id || $defaultFields === []) {
            throw new RuntimeException('最新のMoney Forward仕訳が、送信内容とMF既定区分だけ異なる状態ではありません。手動確認は記録していません。');
        }

        $remoteEvidenceHash = $this->evidenceHash($remote);
        $requestPayloadHash = $this->evidenceHash($sync->accountingPayload() ?? []);
        $observedValues = array_fill_keys($defaultFields, FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET);
        DB::connection('furimadeck')->transaction(function () use (
            $user,
            $entry,
            $sync,
            $connection,
            $businessStatus,
            $defaultFields,
            $observedValues,
            $remoteEvidenceHash,
            $requestPayloadHash,
        ): void {
            $lockedEntry = $user->accountingEntries()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $current = $lockedEntry->providerSyncs()->where('provider', 'money_forward')->lockForUpdate()->firstOrFail();
            $currentConnection = AccountingConnection::on('furimadeck')
                ->where('user_id', $user->id)
                ->where('provider', 'money_forward')
                ->whereIn('status', ['connected', 'expired'])
                ->lockForUpdate()
                ->first();
            $currentBusinessStatus = $currentConnection === null
                ? null
                : app(FurimaDeckMoneyForwardBusinessPeriodService::class)
                    ->statusForDate((array) ($currentConnection->settings ?? []), $lockedEntry->transaction_date?->toDateString() ?? '');
            if ($current->status !== 'synced'
                || $current->last_error !== AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED
                || $current->external_transaction_id !== $sync->external_transaction_id
                || $current->request_payload !== $sync->request_payload
                || $current->sensitive_request_payload !== $sync->sensitive_request_payload
                || $current->provider_account_scope_hash !== $sync->provider_account_scope_hash
                || $current->manual_invoice_kind_confirmed_at !== null
                || $currentConnection === null
                || (int) $currentConnection->id !== (int) $connection->id
                || $currentBusinessStatus !== $businessStatus) {
                throw new RuntimeException('確認中に送信状態、事業者、または税務区分設定が変わりました。状態は変更していません。');
            }
            app(FurimaDeckAccountingProviderScopeService::class)->assertSyncMatchesConnection($current, $currentConnection, 'money_forward');

            $duplicateExternalId = AccountingEntrySync::query()
                ->where('provider', 'money_forward')
                ->where('provider_account_scope_hash', $current->provider_account_scope_hash)
                ->where('external_transaction_id', $current->external_transaction_id)
                ->whereKeyNot($current->id)
                ->whereHas('entry', fn ($query) => $query->where('user_id', $user->id))
                ->exists();
            if ($duplicateExternalId) {
                throw new RuntimeException('このMoney Forward仕訳IDが別の明細にも記録されています。重複を解消するまで確認を記録しません。');
            }

            $current->forceFill([
                'last_error' => AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_MANUALLY_CONFIRMED,
                'verified_at' => null,
                'manual_invoice_kind_confirmed_at' => now(),
                'manual_invoice_kind_confirmed_by_user_id' => $user->id,
                'manual_invoice_kind_evidence_hash' => $remoteEvidenceHash,
            ])->save();

            DB::connection('furimadeck')->table('accounting_entry_sync_invoice_kind_audits')->insert([
                'accounting_entry_sync_id' => $current->id,
                'accounting_entry_id' => $lockedEntry->id,
                'user_id' => $user->id,
                'actor_user_id' => $user->id,
                'provider' => 'money_forward',
                'event_type' => 'manual_default_confirmed',
                'provider_account_scope_hash' => $current->provider_account_scope_hash,
                'external_transaction_id' => $current->external_transaction_id,
                'request_payload_hash' => $requestPayloadHash,
                'remote_evidence_hash' => $remoteEvidenceHash,
                'observed_fields' => json_encode($defaultFields, JSON_THROW_ON_ERROR),
                'observed_values' => json_encode($observedValues, JSON_THROW_ON_ERROR),
                'reason' => 'user_confirmed_provider_default_as_correct_no_resend',
                'created_at' => now(),
            ]);
        });
    }

    public function verifyLegacyUnscoped(
        User $user,
        AccountingEntry $entry,
        string $provider,
        bool $currentAccountConfirmed,
        bool $externalJournalConfirmed,
        bool $readOnlyConfirmed,
    ): bool {
        abort_unless($entry->user_id === $user->id, 404);
        if (! in_array($provider, ['freee', 'money_forward'], true)) {
            abort(404);
        }
        $this->assertProviderApiReadbackEnabled();

        if (! $currentAccountConfirmed || ! $externalJournalConfirmed || ! $readOnlyConfirmed) {
            throw new RuntimeException('事業者・仕訳ID・読取専用操作の確認が必要です。記録は変更していません。');
        }

        $sync = $entry->providerSyncs()->where('provider', $provider)->first();
        if ($sync === null || $sync->status !== 'synced' || ! is_array($sync->accountingPayload())
            || ! is_string($sync->external_transaction_id) || $sync->external_transaction_id === ''
            || filled($sync->provider_account_scope_hash)) {
            throw new RuntimeException('事業者スコープ未記録の送信済み仕訳だけが対象です。状態は変更していません。');
        }

        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, $provider);
        $scope = app(FurimaDeckAccountingProviderScopeService::class);
        $scopeHash = $scope->fingerprintForConnection($connection, $provider);
        if ($scopeHash === null) {
            throw new RuntimeException('接続中の会計事業者を特定できません。状態は変更していません。');
        }
        if ($provider === 'freee'
            && ! hash_equals($scopeHash, (string) $scope->fingerprint($provider, data_get($sync->accountingPayload(), 'company_id')))) {
            throw new RuntimeException('保存済みfreee仕訳の事業所と現在の接続先が一致しません。状態は変更していません。');
        }

        $remote = $this->fetchRemote($user, $provider, $sync, $sync->external_transaction_id, $connection, true);
        $remoteId = is_scalar($remote['id'] ?? null) ? (string) $remote['id'] : '';
        if ($remote === null || $remoteId !== $sync->external_transaction_id) {
            throw new RuntimeException('現在の事業者に同じ仕訳IDが見つかりません。事業者の紐付けは保存していません。');
        }

        $matches = $this->matchesRemote($provider, $sync, $sync->external_transaction_id, $remote);
        $invoiceKindDefaults = ! $matches && $provider === 'money_forward'
            ? $this->comparator->moneyForwardUnsentInvoiceKindDefaultFields(
                $sync->accountingPayload() ?? [],
                $remote,
                config('furimadeck.accounting.oauth.money_forward.application_name'),
            )
            : [];
        if (! $matches && $invoiceKindDefaults === []) {
            throw new RuntimeException('仕訳内容が送信時と一致しません。事業者の紐付けは保存していません。');
        }

        $diagnostic = $invoiceKindDefaults === [] ? null : AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED;
        $this->saveLegacyScopeBinding($user, $entry, $provider, $sync, $connection, $scopeHash, $matches, $diagnostic);
        if ($invoiceKindDefaults !== []) {
            throw new RuntimeException('送信した仕訳内容は一致し、現在の事業者を記録しました。ただし、送信していないインボイス区分「対象外」が返ったため、正しい区分か確認できず未照合のままです。登録済み仕訳の修正・再送は行っていません。');
        }

        return true;
    }

    public function reconcileUnknown(User $user, AccountingEntry $entry, string $provider, string $externalId): void
    {
        abort_unless($entry->user_id === $user->id && in_array($provider, ['freee', 'money_forward'], true), 404);
        $this->assertProviderApiReadbackEnabled();

        $sync = $entry->providerSyncs()->where('provider', $provider)->first();
        if ($sync === null || ! in_array($sync->status, ['sync_unknown', 'legacy_unknown'], true)
            || ! is_array($sync->accountingPayload())) {
            throw new RuntimeException('送信時の内容がないため自動照合できません。送信状態は変更していません。');
        }
        if ($provider === 'freee' && $sync->last_error === AccountingEntrySync::EXTERNAL_VOUCHER_DELETED_TAX_REVIEW) {
            throw new RuntimeException('削除済みのfreeeテスト仕訳は、会計処理の確認が終わるまで送信済みにできません。');
        }
        $remote = $this->fetchRemote($user, $provider, $sync, $externalId);
        if ($remote === null || ! $this->matchesRemote($provider, $sync, $externalId, $remote)) {
            throw new RuntimeException('外部仕訳が見つからないか送信内容と一致しません。送信状態は変更していません。');
        }

        DB::connection('furimadeck')->transaction(function () use ($user, $provider, $sync, $entry, $externalId): void {
            $lockedEntry = AccountingEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $current = AccountingEntrySync::query()->whereKey($sync->id)->lockForUpdate()->firstOrFail();
            $connection = AccountingConnection::on('furimadeck')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->whereIn('status', ['connected', 'expired'])
                ->lockForUpdate()
                ->first();
            if (! in_array($current->status, ['sync_unknown', 'legacy_unknown'], true)
                || $current->request_payload !== $sync->request_payload
                || $current->sensitive_request_payload !== $sync->sensitive_request_payload
                || $current->external_transaction_id !== $sync->external_transaction_id
                || $current->provider_account_scope_hash !== $sync->provider_account_scope_hash
                || $connection === null) {
                throw new RuntimeException('照合中に送信状態が変わりました。もう一度確認してください。');
            }
            app(FurimaDeckAccountingProviderScopeService::class)->assertSyncMatchesConnection($current, $connection, $provider);
            $current->update([
                'status' => 'synced',
                'external_transaction_id' => $externalId,
                'synced_at' => now(),
                'verified_at' => now(),
                'last_error' => null,
            ]);
            if ($lockedEntry->external_transaction_id === null) {
                $lockedEntry->update(['external_transaction_id' => $externalId, 'synced_at' => now()]);
            }
            $lockedEntry->refreshLegacySyncStatus();
        });
    }

    private function assertProviderApiReadbackEnabled(): void
    {
        if (! app(FurimaDeckAccountingApiReleaseGate::class)->enabled()) {
            throw new RuntimeException('FurimaDeck 1.0では会計ソフト側の仕訳API読み戻しを提供していません。会計ソフト側で登録内容を確認してください。');
        }
    }

    private function fetchRemote(
        User $user,
        string $provider,
        AccountingEntrySync $sync,
        string $externalId,
        ?AccountingConnection $resolvedConnection = null,
        bool $allowUnscopedSync = false,
    ): ?array {
        $connection = $resolvedConnection ?? app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, $provider);
        $scope = app(FurimaDeckAccountingProviderScopeService::class);
        if ($allowUnscopedSync && blank($sync->provider_account_scope_hash)) {
            if ($scope->fingerprintForConnection($connection, $provider) === null) {
                throw new RuntimeException('接続中の会計事業者を特定できません。状態は変更していません。');
            }
        } else {
            $scope->assertSyncMatchesConnection($sync, $connection, $provider);
        }
        if ($provider === 'freee') {
            $base = rtrim((string) config('furimadeck.accounting.freee_sync.api_base'), '/');
            $companyId = $sync->request_payload['company_id'] ?? null;
            if (! ctype_digit($externalId) || ! is_int($companyId) || $companyId <= 0) {
                throw new RuntimeException('freee仕訳IDまたは事業所IDを確認できません。');
            }
            $url = $base.'/api/1/manual_journals/'.$externalId;
            $query = ['company_id' => $companyId];
        } else {
            $base = rtrim((string) config('furimadeck.accounting.oauth.money_forward.accounting_api_base'), '/');
            $url = $base.'/api/v3/journals/'.rawurlencode($externalId);
            $query = [];
        }
        if (! filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('会計APIの接続先設定が正しくありません。');
        }

        try {
            $response = $provider === 'freee'
                ? app(FurimaDeckFreeeApiReadService::class)->get($connection, $url, $query)
                : Http::acceptJson()->withToken((string) $connection->access_token)->timeout(15)->get($url, $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('会計ソフトから仕訳を取得できませんでした。送信履歴は変更していません。', previous: $exception);
        }
        if ($response->failed()) {
            if (in_array($response->status(), [404, 410], true)) {
                return null;
            }
            throw new RuntimeException('会計ソフトから仕訳を取得できませんでした（HTTP '.$response->status().'）。送信履歴は変更していません。');
        }

        $remote = $provider === 'freee' ? $response->json('manual_journal') : $response->json('journal');

        return is_array($remote) ? $remote : null;
    }

    private function matchesRemote(string $provider, AccountingEntrySync $sync, string $externalId, array $remote): bool
    {
        $remoteId = is_array($remote) ? ($remote['id'] ?? null) : null;
        $idMatches = is_scalar($remoteId) && (string) $remoteId === $externalId;

        return $idMatches && ($provider === 'freee'
            ? $this->comparator->matchesFreee($sync->accountingPayload() ?? [], $remote)
            : $this->comparator->matchesMoneyForward(
                $sync->accountingPayload() ?? [],
                $remote,
                config('furimadeck.accounting.oauth.money_forward.application_name'),
            ));
    }

    private function mismatchDiagnostic(string $provider, AccountingEntrySync $sync, string $externalId, array $remote): string
    {
        $remoteId = is_scalar($remote['id'] ?? null) ? (string) $remote['id'] : '';
        if ($remoteId !== $externalId) {
            return 'external_id_mismatch';
        }
        if ($provider === 'money_forward') {
            $fields = $this->comparator->moneyForwardMismatchFields(
                $sync->accountingPayload() ?? [],
                $remote,
                config('furimadeck.accounting.oauth.money_forward.application_name'),
            );
            if ($fields !== []) {
                return mb_substr('external_content_mismatch:'.implode(',', array_unique($fields)), 0, AccountingEntrySync::LAST_ERROR_LENGTH);
            }
        }

        return 'external_content_mismatch';
    }

    private function saveResult(User $user, string $provider, AccountingEntrySync $sync, bool $matches, ?string $diagnostic, ?array $remote = null): void
    {
        DB::connection('furimadeck')->transaction(function () use ($user, $provider, $sync, $matches, $diagnostic, $remote): void {
            $current = AccountingEntrySync::query()->whereKey($sync->id)->lockForUpdate()->firstOrFail();
            $connection = AccountingConnection::on('furimadeck')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->whereIn('status', ['connected', 'expired'])
                ->lockForUpdate()
                ->first();
            if ($current->status !== 'synced' || $current->external_transaction_id !== $sync->external_transaction_id
                || $current->request_payload !== $sync->request_payload
                || $current->sensitive_request_payload !== $sync->sensitive_request_payload
                || $current->provider_account_scope_hash !== $sync->provider_account_scope_hash
                || $connection === null) {
                throw new RuntimeException('照合中に送信状態が変わりました。もう一度確認してください。');
            }
            app(FurimaDeckAccountingProviderScopeService::class)->assertSyncMatchesConnection($current, $connection, $provider);
            $manualConfirmationActive = $provider === 'money_forward'
                && $current->hasManuallyConfirmedProviderInvoiceKindDefault();
            if ($manualConfirmationActive) {
                $freshEvidenceHash = $remote === null ? null : $this->evidenceHash($remote);
                $sameManualEvidence = ! $matches
                    && $diagnostic === AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED
                    && is_string($freshEvidenceHash)
                    && hash_equals((string) $current->manual_invoice_kind_evidence_hash, $freshEvidenceHash);
                if ($sameManualEvidence) {
                    $diagnostic = AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_MANUALLY_CONFIRMED;
                } else {
                    $this->appendInvoiceKindAuditEvent(
                        $user,
                        $current,
                        $matches ? 'automatic_match_restored' : 'manual_confirmation_invalidated',
                        $remote,
                        $diagnostic ?? 'exact_api_match_restored',
                    );
                    $current->manual_invoice_kind_confirmed_at = null;
                    $current->manual_invoice_kind_confirmed_by_user_id = null;
                    $current->manual_invoice_kind_evidence_hash = null;
                }
            }
            $current->update([
                'verified_at' => $matches ? now() : null,
                'last_error' => $diagnostic === null ? null : mb_substr($diagnostic, 0, AccountingEntrySync::LAST_ERROR_LENGTH),
                'manual_invoice_kind_confirmed_at' => $current->manual_invoice_kind_confirmed_at,
                'manual_invoice_kind_confirmed_by_user_id' => $current->manual_invoice_kind_confirmed_by_user_id,
                'manual_invoice_kind_evidence_hash' => $current->manual_invoice_kind_evidence_hash,
            ]);
        });
    }

    /** @param array<string, mixed>|null $remote */
    private function appendInvoiceKindAuditEvent(User $user, AccountingEntrySync $sync, string $eventType, ?array $remote, string $reason): void
    {
        $payload = $sync->accountingPayload() ?? [];
        $observedFields = [];
        $observedValues = [];
        if ($remote !== null && $eventType === 'manual_confirmation_invalidated'
            && $this->comparator->moneyForwardUnsentInvoiceKindDefaultFields(
                $payload,
                $remote,
                config('furimadeck.accounting.oauth.money_forward.application_name'),
            ) !== []) {
            $observedFields = ['debit_invoice', 'credit_invoice'];
            $observedValues = array_fill_keys($observedFields, FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET);
        }

        DB::connection('furimadeck')->table('accounting_entry_sync_invoice_kind_audits')->insert([
            'accounting_entry_sync_id' => $sync->id,
            'accounting_entry_id' => $sync->accounting_entry_id,
            'user_id' => $user->id,
            'actor_user_id' => $user->id,
            'provider' => 'money_forward',
            'event_type' => $eventType,
            'provider_account_scope_hash' => $sync->provider_account_scope_hash,
            'external_transaction_id' => $sync->external_transaction_id,
            'request_payload_hash' => $this->evidenceHash($payload),
            'remote_evidence_hash' => $remote === null ? null : $this->evidenceHash($remote),
            'observed_fields' => json_encode($observedFields, JSON_THROW_ON_ERROR),
            'observed_values' => json_encode($observedValues, JSON_THROW_ON_ERROR),
            'reason' => mb_substr($reason, 0, 100),
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $evidence */
    private function evidenceHash(array $evidence): string
    {
        try {
            $json = json_encode($this->canonicalizeEvidence($evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $exception) {
            throw new RuntimeException('会計照合の証拠を安全に記録できません。状態は変更していません。', previous: $exception);
        }

        return hash('sha256', $json);
    }

    private function canonicalizeEvidence(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalizeEvidence($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function saveLegacyScopeBinding(
        User $user,
        AccountingEntry $entry,
        string $provider,
        AccountingEntrySync $sync,
        AccountingConnection $connection,
        string $scopeHash,
        bool $matches,
        ?string $diagnostic,
    ): void {
        DB::connection('furimadeck')->transaction(function () use ($user, $entry, $provider, $sync, $connection, $scopeHash, $matches, $diagnostic): void {
            $lockedEntry = $user->accountingEntries()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $current = $lockedEntry->providerSyncs()->where('provider', $provider)->lockForUpdate()->firstOrFail();
            $currentConnection = AccountingConnection::on('furimadeck')
                ->where('user_id', $user->id)
                ->where('provider', $provider)
                ->whereIn('status', ['connected', 'expired'])
                ->lockForUpdate()
                ->first();
            if ($current->status !== 'synced'
                || $current->request_payload !== $sync->request_payload
                || $current->sensitive_request_payload !== $sync->sensitive_request_payload
                || $current->external_transaction_id !== $sync->external_transaction_id
                || filled($current->provider_account_scope_hash)
                || $currentConnection === null
                || (int) $currentConnection->id !== (int) $connection->id
                || app(FurimaDeckAccountingProviderScopeService::class)->fingerprintForConnection($currentConnection, $provider) !== $scopeHash
                || $connection->provider !== $provider) {
                throw new RuntimeException('照合中に送信状態または接続先が変わりました。事業者の紐付けは保存していません。');
            }

            $current->update([
                'provider_account_scope_hash' => $scopeHash,
                'verified_at' => $matches ? now() : null,
                'last_error' => $diagnostic,
            ]);
        });
    }
}
