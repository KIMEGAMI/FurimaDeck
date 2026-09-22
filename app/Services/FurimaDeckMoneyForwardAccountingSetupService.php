<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckMoneyForwardAccountingSetupService
{
    public function connection(User $user): AccountingConnection
    {
        return $user->accountingConnections()->where('provider', 'money_forward')->where('status', 'connected')->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function options(User $user): array
    {
        $connection = app(FurimaDeckAccountingOAuthService::class)->refreshIfNeeded($user, 'money_forward');

        $taxes = $this->get($connection, '/api/v3/taxes', ['available' => 'true']);
        if (($taxes['taxes'] ?? []) === []) {
            $taxes = $this->get($connection, '/api/v3/taxes');
        }

        return [
            'accounts' => $this->get($connection, '/api/v3/accounts', ['available' => 'true']),
            'taxes' => $taxes,
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(User $user, array $data): void
    {
        $connection = $this->connection($user);
        $options = $this->options($user);
        $accountIds = $this->accountIds($options['accounts']['accounts'] ?? []);
        $taxIds = collect($options['taxes']['taxes'] ?? [])->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $key) {
            if (! in_array((string) $data['account_ids'][$key], $accountIds, true)) {
                throw new RuntimeException('Money Forwardの勘定科目設定が正しくありません。画面に表示された科目を選択してください。');
            }
        }
        if (! in_array((string) $data['tax_id'], $taxIds, true)) {
            throw new RuntimeException('Money Forwardの税区分設定が正しくありません。画面に表示された税区分を選択してください。');
        }

        $connection->update(['settings' => [
            'account_ids' => array_map('strval', $data['account_ids']),
            'tax_id' => (string) $data['tax_id'],
            'invoice_kind' => (string) ($data['invoice_kind'] ?? 'INVOICE_KIND_NOT_TARGET'),
        ], 'last_error' => null]);
    }

    /** @return array<int, string> */
    private function accountIds(array $groups): array
    {
        $ids = [];
        foreach ($groups as $group) {
            if (is_array($group) && isset($group['id'])) {
                $ids[] = (string) $group['id'];
            }
            foreach ((array) ($group['sub_accounts'] ?? []) as $subAccount) {
                if (is_array($subAccount) && isset($subAccount['id'])) {
                    $ids[] = (string) $subAccount['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed> */
    private function get(AccountingConnection $connection, string $path, array $query = []): array
    {
        $base = trim((string) config('furimadeck.accounting.oauth.money_forward.accounting_api_base'));
        if (! filter_var($base, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Money Forward会計APIのURL設定が不正です。');
        }
        $response = Http::acceptJson()->withToken((string) $connection->access_token)->timeout(15)->get(rtrim($base, '/').$path, $query);
        if ($response->status() === 401) {
            throw new RuntimeException('Money Forwardの認証トークンが無効です。連携を解除してから再連携してください。');
        }
        if ($response->status() === 403) {
            throw new RuntimeException('Money Forward会計APIの利用権限がありません。アプリポータルのクラウド会計・確定申告権限に加え、会計API用スコープを確認してから再連携してください。');
        }
        if ($response->status() === 404) {
            throw new RuntimeException('Money Forward会計APIの接続先が見つかりません。FURIMADECK_MF_ACCOUNTING_API_BASEを確認してください。');
        }
        if ($response->status() === 409) {
            $apiMessage = (string) ($response->json('errors.0.message') ?? '会計APIが現在の事業者状態を受け付けませんでした。');
            throw new RuntimeException('Money Forward会計APIがHTTP 409を返しました（'.$path.'）。'.$apiMessage.' Money Forwardでクラウド会計またはクラウド確定申告の利用開始状態と年度設定を確認してください。');
        }
        if ($response->failed() || ! is_array($response->json())) {
            throw new RuntimeException('Money Forwardの会計マスタ取得に失敗しました（HTTP '.$response->status().'）。');
        }

        return $response->json();
    }
}
