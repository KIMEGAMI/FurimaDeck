<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckFreeeAccountingSetupService
{
    /** @return array{companies: list<array{id:int,name:string,display_name:string}>, connection: AccountingConnection} */
    public function companies(User $user): array
    {
        $connection = $this->connection($user);
        $payload = $this->get($connection, '/api/1/companies');

        return [
            'companies' => collect($payload['companies'] ?? [])->map(static fn (array $company): array => [
                'id' => (int) ($company['id'] ?? 0),
                'name' => (string) ($company['name'] ?? ''),
                'display_name' => (string) ($company['display_name'] ?? $company['name'] ?? ''),
            ])->filter(static fn (array $company): bool => $company['id'] > 0)->values()->all(),
            'connection' => $connection,
        ];
    }

    /** @return array{company: array{id:int,name:string,display_name:string}, account_items: list<array{id:int,name:string}>, tax_codes: list<array{code:string,name:string}>, settings: array<string,mixed>, connection: AccountingConnection} */
    public function options(User $user, int $companyId): array
    {
        $company = collect($this->companies($user)['companies'])->firstWhere('id', $companyId);
        if ($company === null) {
            throw new RuntimeException('選択したfreee事業所を確認できません。');
        }

        $connection = $this->connection($user);
        $accountItems = $this->get($connection, '/api/1/account_items', ['company_id' => $companyId]);
        $taxes = $this->get($connection, '/api/1/taxes/companies/'.$companyId);

        return [
            'company' => $company,
            'account_items' => collect($accountItems['account_items'] ?? [])->map(static fn (array $item): array => [
                'id' => (int) ($item['id'] ?? 0),
                'name' => (string) ($item['name'] ?? ''),
            ])->filter(static fn (array $item): bool => $item['id'] > 0 && $item['name'] !== '')->sortBy('name')->values()->all(),
            'tax_codes' => collect($taxes['taxes'] ?? $taxes['tax_codes'] ?? [])->map(static fn (array $tax): array => [
                'code' => (string) ($tax['code'] ?? $tax['tax_code'] ?? ''),
                'name' => (string) ($tax['name'] ?? $tax['tax_name'] ?? ''),
            ])->filter(static fn (array $tax): bool => $tax['code'] !== '' && $tax['name'] !== '')->sortBy('name')->values()->all(),
            'settings' => (array) ($connection->settings ?? []),
            'connection' => $connection,
        ];
    }

    public function save(User $user, array $values): void
    {
        $connection = $this->connection($user);
        $options = $this->options($user, (int) $values['company_id']);
        $accountIds = collect($options['account_items'])->pluck('id')->map(static fn (int $id): string => (string) $id);
        $taxCodes = collect($options['tax_codes'])->pluck('code')->map(static fn (string $code): string => $code);
        $selectedIds = collect($values['account_item_ids'])->map(static fn (string $id): string => $id);

        if (! $taxCodes->contains((string) $values['tax_code']) || $selectedIds->diff($accountIds)->isNotEmpty()) {
            throw new RuntimeException('freeeから取得した選択肢以外は保存できません。');
        }

        $connection->update([
            'external_account_id' => (string) $values['company_id'],
            'settings' => [
                'company_id' => (string) $values['company_id'],
                'tax_code' => (string) $values['tax_code'],
                'account_item_ids' => $values['account_item_ids'],
            ],
            'last_error' => null,
        ]);
    }

    private function connection(User $user): AccountingConnection
    {
        $connection = $user->accountingConnections()->where('provider', 'freee')->where('status', 'connected')->first();
        if ($connection === null) {
            throw new RuntimeException('先にfreeeを連携してください。');
        }

        return app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'freee');
    }

    /** @return array<string,mixed> */
    private function get(AccountingConnection $connection, string $path, array $query = []): array
    {
        $configuredBaseUrl = trim((string) config('furimadeck.accounting.oauth.freee.api_base'));
        $baseUrl = rtrim($configuredBaseUrl !== '' ? $configuredBaseUrl : 'https://api.freee.co.jp', '/');
        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('freee APIの接続先設定が正しくありません。');
        }

        $response = Http::acceptJson()->withToken((string) $connection->access_token)->timeout(15)->get(
            $baseUrl.$path,
            $query,
        );
        if ($response->failed() || ! is_array($response->json())) {
            throw new RuntimeException('freeeの会計マスタを取得できませんでした。');
        }

        return $response->json();
    }
}
