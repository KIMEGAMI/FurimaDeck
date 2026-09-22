<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckFreeeAccountingSyncService
{
    /** @return list<string> */
    public function missingConfiguration(?AccountingConnection $connection = null): array
    {
        $config = $this->configuration($connection);
        $missing = [];
        foreach (['company_id' => 'company_id', 'tax_code' => 'tax_code'] as $label => $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }
        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $name) {
            if (trim((string) (($config['account_item_ids'] ?? [])[$name] ?? '')) === '') {
                $missing[] = 'account_item_ids.'.$name;
            }
        }

        return $missing;
    }

    public function send(User $user, AccountingEntry $entry): string
    {
        abort_unless($entry->user_id === $user->id, 404);

        $failureCode = null;

        try {
            return DB::transaction(function () use ($user, $entry, &$failureCode): string {
                $lockedEntry = $user->accountingEntries()->eligibleForAccounting()
                    ->whereKey($entry->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->sendLocked($user, $lockedEntry, $failureCode);
            });
        } catch (\Throwable $exception) {
            if ($failureCode !== null) {
                $user->accountingConnections()
                    ->where('provider', 'freee')
                    ->update(['last_error' => $failureCode]);
            }

            throw $exception;
        }
    }

    private function sendLocked(User $user, AccountingEntry $entry, ?string &$failureCode): string
    {
        if ($entry->source_status !== 'confirmed') {
            throw new RuntimeException('Confirm済みの会計データだけ送信できます。');
        }
        if ($entry->sync_status !== 'not_synced') {
            throw new RuntimeException('この会計データはすでに送信済みです。');
        }

        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'freee');
        $config = $this->configuration($connection);
        $companyId = trim((string) ($config['company_id'] ?? ''));
        $taxCode = trim((string) ($config['tax_code'] ?? ''));
        $accountIds = (array) ($config['account_item_ids'] ?? []);
        if ($this->missingConfiguration($connection) !== []) {
            throw new RuntimeException('freee連携設定が不足しています。');
        }

        $failureCode = 'sync_failed';
        $response = Http::acceptJson()
            ->withToken((string) $connection->access_token)
            ->timeout(15)
            ->post(rtrim((string) ($config['api_base'] ?? ''), '/').'/api/1/manual_journals', [
                'company_id' => (int) $companyId,
                'issue_date' => $entry->transaction_date->format('Y-m-d'),
                'adjustment' => false,
                'ref_number' => (string) $entry->management_id,
                'details' => $this->details($entry, $accountIds, $taxCode),
            ]);

        if ($response->failed()) {
            $failureCode = 'sync_failed';
            throw new RuntimeException('freeeへの会計データ送信に失敗しました。');
        }

        $externalId = data_get($response->json(), 'manual_journal.id');
        if (! is_scalar($externalId) || (string) $externalId === '') {
            $failureCode = 'sync_response_invalid';
            throw new RuntimeException('freeeの送信応答に外部IDがありません。');
        }

        $entry->update([
            'sync_status' => 'synced',
            'external_transaction_id' => (string) $externalId,
            'synced_at' => now(),
        ]);
        $connection->update(['last_error' => null, 'last_synced_at' => now()]);
        $failureCode = null;

        return (string) $externalId;
    }

    /** @return array{company_id:string,tax_code:string,account_item_ids:array<string,string>,api_base:string} */
    private function configuration(?AccountingConnection $connection = null): array
    {
        $config = (array) config('furimadeck.accounting.freee_sync');
        $saved = (array) ($connection?->settings ?? []);

        return [
            'api_base' => (string) ($config['api_base'] ?? ''),
            'company_id' => (string) ($saved['company_id'] ?? $config['company_id'] ?? ''),
            'tax_code' => (string) ($saved['tax_code'] ?? $config['tax_code'] ?? ''),
            'account_item_ids' => array_merge(
                array_map('strval', (array) ($config['account_item_ids'] ?? [])),
                array_map('strval', (array) ($saved['account_item_ids'] ?? [])),
            ),
        ];
    }

    /** @return array<int, array<string, int|string>> */
    private function details(AccountingEntry $entry, array $accountIds, string $taxCode): array
    {
        $details = [];
        $this->addPair($details, $accountIds, $taxCode, 'settlement', 'sales', (int) $entry->sale_amount, $entry->title.' 売上');
        $fees = (int) $entry->selling_fee + (int) $entry->shipping_cost + (int) $entry->other_cost;
        if ($fees > 0) {
            $this->addPair($details, $accountIds, $taxCode, 'fees', 'settlement', $fees, $entry->title.' 手数料等');
        }
        $cost = (int) $entry->purchase_cost;
        if ($cost > 0) {
            $this->addPair($details, $accountIds, $taxCode, 'cost', 'inventory', $cost, $entry->title.' 原価');
        }

        return $details;
    }

    private function addPair(array &$details, array $accountIds, string $taxCode, string $debit, string $credit, int $amount, string $description): void
    {
        if ($amount <= 0) {
            return;
        }
        foreach ([['debit', $debit], ['credit', $credit]] as [$side, $account]) {
            $details[] = [
                'entry_side' => $side,
                'account_item_id' => (int) $accountIds[$account],
                'tax_code' => (int) $taxCode,
                'amount' => $amount,
                'description' => $description,
            ];
        }
    }
}
