<?php

namespace Tests\Unit;

use App\Models\AccountingConnection;
use App\Models\AccountingEntrySync;
use App\Services\FurimaDeckAccountingProviderScopeService;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckAccountingProviderScopeServiceTest extends TestCase
{
    public function test_freee_scope_uses_selected_company_and_money_forward_uses_tenant(): void
    {
        $service = app(FurimaDeckAccountingProviderScopeService::class);
        $freee = new AccountingConnection([
            'provider' => 'freee',
            'external_account_id' => 'oauth-principal',
            'settings' => ['company_id' => '12345'],
        ]);
        $moneyForward = new AccountingConnection([
            'provider' => 'money_forward',
            'external_account_id' => 'tenant-x91',
            'settings' => [],
        ]);

        $freeeHash = $service->fingerprintForConnection($freee, 'freee');
        $this->assertNotNull($freeeHash);
        $this->assertSame($service->fingerprint('freee', '12345'), $freeeHash);
        $this->assertSame($service->fingerprint('money_forward', 'tenant-x91'), $service->fingerprintForConnection($moneyForward, 'money_forward'));
        $this->assertNotSame($freeeHash, $service->fingerprint('freee', '54321'));
    }

    public function test_sync_match_rejects_missing_or_different_provider_account_scope(): void
    {
        $service = app(FurimaDeckAccountingProviderScopeService::class);
        $connection = new AccountingConnection([
            'provider' => 'money_forward',
            'external_account_id' => 'tenant-current',
            'settings' => [],
        ]);
        $sync = new AccountingEntrySync([
            'provider_account_scope_hash' => $service->fingerprint('money_forward', 'tenant-other'),
        ]);

        try {
            $service->assertSyncMatchesConnection($sync, $connection, 'money_forward');
            $this->fail('A sync record from another tenant must not verify against the current tenant.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('会計事業者が一致しない', $exception->getMessage());
        }
    }

    public function test_manual_evidence_accepts_legacy_scope_only_for_the_same_current_account(): void
    {
        $service = app(FurimaDeckAccountingProviderScopeService::class);
        $legacyHash = hash('sha256', "freee\0".'12345');

        $this->assertTrue($service->matchesManualEvidence($legacyHash, 'freee', '12345'));
        $this->assertFalse($service->matchesManualEvidence($legacyHash, 'freee', '54321'));
    }
}
