<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckMoneyForwardAccountingSyncService
{
    /** @return list<string> */
    public function missingConfiguration(?AccountingConnection $connection = null): array
    {
        $settings = (array) ($connection?->settings ?? []);
        $missing = [];
        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $key) {
            if (trim((string) (($settings['account_ids'] ?? [])[$key] ?? '')) === '') {
                $missing[] = 'account_ids.'.$key;
            }
        }
        if (trim((string) ($settings['tax_id'] ?? '')) === '') {
            $missing[] = 'tax_id';
        }

        return $missing;
    }

    public function send(User $user, AccountingEntry $entry): string
    {
        abort_unless($entry->user_id === $user->id, 404);

        return DB::transaction(function () use ($user, $entry): string {
            $locked = $user->accountingEntries()->eligibleForAccounting()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->source_status !== 'confirmed') {
                throw new RuntimeException('Confirm済みの会計データだけ送信できます。');
            }
            if ($locked->sync_status !== 'not_synced') {
                throw new RuntimeException('この会計データはすでに送信済みです。');
            }

            $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward');
            if ($this->missingConfiguration($connection) !== []) {
                throw new RuntimeException('Money Forwardの仕訳設定が不足しています。');
            }
            $settings = (array) $connection->settings;
            $response = $this->request($connection)->post('/api/v3/journals', ['journal' => $this->journal($locked, $settings)]);
            if ($response->failed()) {
                $connection->update(['last_error' => 'sync_failed']);
                throw new RuntimeException('Money Forwardへの仕訳送信に失敗しました。権限、会計期間、税区分を確認してください。');
            }
            $externalId = data_get($response->json(), 'journal.id') ?: data_get($response->json(), 'journal.transaction_id');
            if (! is_scalar($externalId) || trim((string) $externalId) === '') {
                $connection->update(['last_error' => 'sync_response_invalid']);
                throw new RuntimeException('Money Forwardの送信応答に外部IDがありません。');
            }
            $locked->update(['sync_status' => 'synced', 'external_transaction_id' => (string) $externalId, 'synced_at' => now()]);
            $connection->update(['last_error' => null, 'last_synced_at' => now()]);

            return (string) $externalId;
        });
    }

    /** @return array<string, mixed> */
    private function journal(AccountingEntry $entry, array $settings): array
    {
        $accounts = (array) $settings['account_ids'];
        $taxId = (string) $settings['tax_id'];
        $branches = [];
        $this->addPair($branches, $accounts['settlement'], $accounts['sales'], $taxId, (int) $entry->sale_amount, $entry->title.' 売上', $settings);
        $fees = (int) $entry->selling_fee + (int) $entry->shipping_cost + (int) $entry->other_cost;
        if ($fees > 0) {
            $this->addPair($branches, $accounts['fees'], $accounts['settlement'], $taxId, $fees, $entry->title.' 手数料等', $settings);
        }
        if ((int) $entry->purchase_cost > 0) {
            $this->addPair($branches, $accounts['cost'], $accounts['inventory'], $taxId, (int) $entry->purchase_cost, $entry->title.' 原価', $settings);
        }

        return ['transaction_date' => $entry->transaction_date->format('Y-m-d'), 'journal_type' => 'journal_entry', 'memo' => 'FurimaDeck #'.$entry->management_id, 'branches' => $branches];
    }

    private function addPair(array &$branches, string $debit, string $credit, string $taxId, int $amount, string $remark, array $settings): void
    {
        $common = ['value' => $amount, 'tax_id' => $taxId, 'invoice_kind' => (string) ($settings['invoice_kind'] ?? 'INVOICE_KIND_NOT_TARGET')];
        $branches[] = ['debitor' => [...$common, 'account_id' => $debit], 'creditor' => [...$common, 'account_id' => $credit], 'remark' => $remark];
    }

    private function request(AccountingConnection $connection): PendingRequest
    {
        $base = trim((string) config('furimadeck.accounting.oauth.money_forward.accounting_api_base'));

        return Http::acceptJson()->withToken((string) $connection->access_token)->timeout(15)->baseUrl(rtrim($base, '/'));
    }
}
