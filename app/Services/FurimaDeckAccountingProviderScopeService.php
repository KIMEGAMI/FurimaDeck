<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntrySync;

class FurimaDeckAccountingProviderScopeService
{
    public function accountIdForConnection(AccountingConnection $connection, string $provider): ?string
    {
        if ($connection->provider !== $provider) {
            return null;
        }

        $accountId = $provider === 'freee'
            ? data_get($connection->settings, 'company_id')
                ?? config('furimadeck.accounting.freee_sync.company_id')
                ?? $connection->external_account_id
            : ($provider === 'money_forward' ? $connection->external_account_id : null);

        return $this->normalizeAccountId($provider, $accountId);
    }

    public function fingerprintForConnection(AccountingConnection $connection, string $provider): ?string
    {
        return $this->fingerprint($provider, $this->accountIdForConnection($connection, $provider));
    }

    public function fingerprint(string $provider, mixed $accountId): ?string
    {
        $normalized = $this->normalizeAccountId($provider, $accountId);
        $key = (string) config('app.key');
        if ($normalized === null || $key === '') {
            return null;
        }

        return hash_hmac('sha256', "furimadeck-accounting-scope-v1\0".$provider."\0".$normalized, $key);
    }

    /** @return list<string> */
    public function compatibleFingerprints(string $provider, mixed $accountId): array
    {
        $normalized = $this->normalizeAccountId($provider, $accountId);
        if ($normalized === null) {
            return [];
        }

        $fingerprints = [];
        $current = $this->fingerprint($provider, $normalized);
        if ($current !== null) {
            $fingerprints[] = $current;
        }

        // Manual purchase evidence predates the keyed fingerprint and remains encrypted at rest.
        $fingerprints[] = hash('sha256', $provider."\0".$normalized);

        return array_values(array_unique($fingerprints));
    }

    public function matchesManualEvidence(?string $storedFingerprint, string $provider, mixed $accountId): bool
    {
        if ($storedFingerprint === null || $storedFingerprint === '') {
            return false;
        }

        foreach ($this->compatibleFingerprints($provider, $accountId) as $candidate) {
            if (hash_equals($candidate, $storedFingerprint)) {
                return true;
            }
        }

        return false;
    }

    public function assertSyncMatchesConnection(
        AccountingEntrySync $sync,
        AccountingConnection $connection,
        string $provider,
    ): void {
        $expected = $this->fingerprintForConnection($connection, $provider);
        $stored = $sync->provider_account_scope_hash;
        if ($expected === null || ! is_string($stored) || $stored === '' || ! hash_equals($expected, $stored)) {
            throw new \RuntimeException('送信時と現在の会計事業者が一致しないか、送信先の記録がありません。正しい事業者へ接続し直して確認してください。');
        }

        if ($provider === 'freee') {
            $payloadCompanyId = data_get($sync->accountingPayload(), 'company_id');
            $payloadFingerprint = $this->fingerprint($provider, $payloadCompanyId);
            if ($payloadFingerprint === null || ! hash_equals($stored, $payloadFingerprint)) {
                throw new \RuntimeException('送信記録のfreee事業所と保存済み仕訳内容が一致しません。自動照合を停止しました。');
            }
        }
    }

    private function normalizeAccountId(string $provider, mixed $accountId): ?string
    {
        if (! in_array($provider, ['freee', 'money_forward'], true) || ! is_scalar($accountId)) {
            return null;
        }

        $accountId = trim((string) $accountId);
        if ($accountId === '' || strlen($accountId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $accountId)) {
            return null;
        }
        if ($provider === 'freee' && (! ctype_digit($accountId) || (int) $accountId < 1)) {
            return null;
        }

        return $accountId;
    }
}
