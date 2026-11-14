<?php

namespace App\Services;

use App\Exceptions\MoneyForwardUnauthorizedException;
use App\Models\AccountingConnection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FurimaDeckMoneyForwardAccountingSetupService
{
    private const REQUIRED_ROLES = ['settlement', 'sales', 'fees', 'cost', 'inventory'];

    private const OPTIONAL_ROLES = ['shipping', 'other'];

    public function connection(User $user): AccountingConnection
    {
        return $user->accountingConnections()->where('provider', 'money_forward')->where('status', 'connected')->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function options(User $user): array
    {
        $oauth = app(FurimaDeckAccountingOAuthService::class);
        $connection = $oauth->refreshIfNeeded($user, 'money_forward');
        $rejectedAccessToken = (string) $connection->access_token;

        try {
            return $this->loadOptions($connection);
        } catch (MoneyForwardUnauthorizedException) {
            $connection = $oauth->refreshIfNeeded($user, 'money_forward', true, $rejectedAccessToken);

            return $this->loadOptions($connection);
        }
    }

    /** @return array{accounts: array<string, mixed>, taxes: array<string, mixed>} */
    private function loadOptions(AccountingConnection $connection): array
    {
        $taxes = $this->get($connection, '/api/v3/taxes', ['available' => 'true']);
        $taxes['taxes'] = collect($taxes['taxes'] ?? [])
            ->filter(fn (mixed $tax): bool => is_array($tax)
                && (! array_key_exists('available', $tax) || $tax['available'] === true))
            ->values()
            ->all();

        return [
            'accounts' => $this->get($connection, '/api/v3/accounts', ['available' => 'true']),
            'taxes' => $taxes,
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(User $user, array $data): void
    {
        $businessTaxPeriods = app(FurimaDeckMoneyForwardBusinessPeriodService::class)
            ->normalize((array) ($data['business_tax_periods'] ?? []));
        $invoiceKinds = (array) ($data['invoice_kinds'] ?? []);
        $invoiceKindService = app(FurimaDeckMoneyForwardInvoiceKindService::class);
        $allowedRoles = [...self::REQUIRED_ROLES, ...self::OPTIONAL_ROLES];
        $periodKinds = collect($businessTaxPeriods)->pluck('invoice_kind');
        $requiresInvoiceKinds = $periodKinds->contains(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
        if (array_diff(array_keys($invoiceKinds), $allowedRoles) !== []) {
            throw new RuntimeException('Money Forwardのインボイス区分に不明な項目があります。設定は保存していません。');
        }
        if ($requiresInvoiceKinds) {
            foreach (self::REQUIRED_ROLES as $role) {
                if (! $invoiceKindService->isValid((string) ($invoiceKinds[$role] ?? ''))) {
                    throw new RuntimeException('Money Forwardの'.$role.'側のインボイス区分を選択してください。設定は保存していません。');
                }
            }
        }
        $connection = $this->connection($user);
        $options = $this->options($user);
        $accountMappings = $this->accountMappings($options['accounts']['accounts'] ?? []);
        $existingSettings = (array) ($connection->settings ?? []);
        $taxIds = collect($options['taxes']['taxes'] ?? [])->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $taxMasterUnavailableForHold = $taxIds === [] && $periodKinds->every(
            fn (string $kind): bool => in_array($kind, [
                FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT,
                FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED,
            ], true),
        );
        if ($taxIds === [] && ! $taxMasterUnavailableForHold) {
            throw new RuntimeException('Money Forwardの税区分マスタが空です。事業者が免税の場合だけ「免税事業者（区分を送信しない）」を選択できます。課税事業者の場合は、Money Forward側で課税形式と税区分を設定してください。');
        }

        $roles = self::REQUIRED_ROLES;
        $selected = collect((array) $data['account_ids'])
            ->filter(static fn (mixed $id): bool => $id !== null && trim((string) $id) !== '');
        if ($selected->keys()->diff($allowedRoles)->isNotEmpty()
            || collect($roles)->diff($selected->keys())->isNotEmpty()) {
            throw new RuntimeException('Money Forwardの勘定科目設定が正しくありません。画面に表示された科目を選択してください。');
        }
        foreach ($selected as $id) {
            if (! isset($accountMappings[(string) $id])) {
                throw new RuntimeException('Money Forwardの勘定科目設定が正しくありません。画面に表示された科目を選択してください。');
            }
        }

        $purchaseTaxIds = (array) ($data['purchase_tax_ids'] ?? []);
        if ($taxMasterUnavailableForHold
            && collect($purchaseTaxIds)->filter(static fn (mixed $taxId): bool => filled($taxId))->isEmpty()) {
            $purchaseTaxIds = (array) ($existingSettings['purchase_tax_ids'] ?? []);
        }
        $purchaseTaxCategories = array_values(array_diff(Product::PURCHASE_TAX_CATEGORIES, ['unknown']));
        if (array_diff(array_keys($purchaseTaxIds), $purchaseTaxCategories) !== []) {
            throw new RuntimeException('仕入証憑の税区分設定に不明な項目があります。');
        }
        foreach ($purchaseTaxIds as $category => $taxId) {
            $taxId = trim((string) $taxId);
            if ($taxId !== '' && ! $taxMasterUnavailableForHold && ! in_array($taxId, $taxIds, true)) {
                throw new RuntimeException('仕入証憑の税区分にはMoney Forwardから取得した有効な税区分を選択してください。');
            }
            $purchaseTaxIds[$category] = $taxId === '' ? null : $taxId;
        }
        $eligiblePaymentIds = $this->eligibleAccountIds($options['accounts']['accounts'] ?? [])['purchase_payment_source'];
        $purchasePaymentIds = array_key_exists('purchase_payment_account_ids_present', $data)
            ? collect((array) ($data['purchase_payment_account_ids'] ?? []))->map(static fn (mixed $id): string => (string) $id)->unique()->values()
            : collect(array_keys((array) ($existingSettings['purchase_payment_accounts'] ?? [])))->map(static fn (mixed $id): string => (string) $id);
        if ($purchasePaymentIds->diff($eligiblePaymentIds)->isNotEmpty()) {
            throw new RuntimeException('支払元候補にはMoney Forwardから取得した有効な資産・負債科目だけを選択してください。');
        }
        $purchasePaymentAccounts = [];
        foreach ($purchasePaymentIds as $id) {
            $mapping = $accountMappings[$id] ?? null;
            if ($mapping === null || ! $this->isCompatiblePaymentSource($mapping)) {
                throw new RuntimeException('Money Forwardから取得した支払元科目を確認できません。');
            }
            if (! $taxMasterUnavailableForHold && filled($mapping['tax_id']) && ! in_array($mapping['tax_id'], $taxIds, true)) {
                throw new RuntimeException('支払元科目の税区分がMoney Forwardの有効な税区分マスタにありません。Money Forward側で設定を確認してください。');
            }
            $purchasePaymentAccounts[$id] = [
                'account_id' => $mapping['account_id'],
                'sub_account_id' => $mapping['sub_account_id'],
                'tax_id' => $mapping['tax_id'] === '' ? null : $mapping['tax_id'],
                'name' => $mapping['name'],
            ];
        }

        $selectedAccountIds = [];
        $selectedSubAccountIds = [];
        $selectedTaxIds = [];
        foreach ($selected as $role => $id) {
            $mapping = $accountMappings[(string) $id];
            $this->assertAccountStatementType($role, $mapping);
            if ($role === 'settlement') {
                $parentMapping = $accountMappings[$mapping['account_id']] ?? null;
                if (! is_array($parentMapping) || trim($parentMapping['name']) !== '売掛金') {
                    throw new RuntimeException('Money Forwardの売上借方には「売掛金」を選択してください。銀行口座科目では設定を保存できません。');
                }
            }
            $taxId = $mapping['tax_id'];
            if (! $taxMasterUnavailableForHold && ($taxId === '' || ! in_array($taxId, $taxIds, true))) {
                throw new RuntimeException('選択したMoney Forward勘定科目に標準税区分がありません。Money Forward側で科目の税区分を設定してください。');
            }
            $selectedAccountIds[$role] = $mapping['account_id'];
            $selectedSubAccountIds[$role] = $mapping['sub_account_id'];
            $selectedTaxIds[$role] = $taxMasterUnavailableForHold || $taxId === '' ? null : $taxId;
        }

        $storedInvoiceKinds = (array) ($existingSettings['invoice_kinds'] ?? []);
        if (array_key_exists('invoice_kinds', $data)) {
            foreach ($invoiceKinds as $role => $kind) {
                if (filled($kind) && ! $invoiceKindService->isValid((string) $kind)) {
                    throw new RuntimeException('Money Forwardのインボイス区分に不明な値があります。設定は保存していません。');
                }
            }
            $storedInvoiceKinds = collect([...self::REQUIRED_ROLES, ...self::OPTIONAL_ROLES])
                ->filter(fn (string $role): bool => isset($selectedAccountIds[$role]) && filled($invoiceKinds[$role] ?? null))
                ->mapWithKeys(fn (string $role): array => [$role => (string) $invoiceKinds[$role]])
                ->all();
        }

        $connection->update(['settings' => array_merge($existingSettings, [
            'business_tax_periods' => $businessTaxPeriods,
            'account_ids' => $selectedAccountIds,
            'sub_account_ids' => $selectedSubAccountIds,
            'tax_ids' => $selectedTaxIds,
            'account_statement_types_checked' => true,
            'purchase_tax_ids' => $purchaseTaxIds,
            'invoice_kinds' => $storedInvoiceKinds,
            'purchase_payment_accounts' => $purchasePaymentAccounts,
        ]), 'last_error' => null]);
    }

    /** @return array<string, string|null> Current tax IDs attached to the selected accounts. */
    public function assertCurrentConfiguration(User $user, AccountingConnection $connection, ?string $businessStatus = null, ?array $options = null): array
    {
        $options ??= $this->options($user);
        $mappings = $this->accountMappings($options['accounts']['accounts'] ?? []);
        $availableTaxIds = collect($options['taxes']['taxes'] ?? [])->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $settings = (array) ($connection->settings ?? []);
        $taxesOptional = $businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT;
        $currentTaxIds = [];

        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory', 'shipping', 'other'] as $role) {
            if (in_array($role, ['shipping', 'other'], true) && ! isset($settings['account_ids'][$role])) {
                continue;
            }
            $accountId = (string) ($settings['account_ids'][$role] ?? '');
            $subAccountId = $settings['sub_account_ids'][$role] ?? null;
            $selectedId = $subAccountId === null ? $accountId : (string) $subAccountId;
            $mapping = $mappings[$selectedId] ?? null;
            $storedTaxId = trim((string) ($settings['tax_ids'][$role] ?? ''));
            $currentTaxId = (string) ($mapping['tax_id'] ?? '');
            if ($mapping === null || $mapping['account_id'] !== $accountId
                || $mapping['sub_account_id'] !== $subAccountId
                || (! $taxesOptional && ($currentTaxId !== $storedTaxId
                    || ! in_array($currentTaxId, $availableTaxIds, true)))) {
                throw new RuntimeException('Money Forwardの勘定科目または税区分が保存時から変わりました。仕訳は送信していません。設定を再確認してください。');
            }
            $this->assertAccountStatementType($role, $mapping);
            $currentTaxIds[$role] = $taxesOptional || $currentTaxId === '' ? null : $currentTaxId;
        }

        $savedPurchaseTaxIds = (array) ($settings['purchase_tax_ids'] ?? []);
        if (! $taxesOptional && array_diff(array_keys($savedPurchaseTaxIds), array_diff(Product::PURCHASE_TAX_CATEGORIES, ['unknown'])) !== []) {
            throw new RuntimeException('仕入証憑の税区分設定を確認できません。仕訳は送信していません。');
        }
        if (! $taxesOptional) {
            foreach ($savedPurchaseTaxIds as $taxId) {
                if (filled($taxId) && ! in_array((string) $taxId, $availableTaxIds, true)) {
                    throw new RuntimeException('仕入証憑の税区分がMoney Forward側で変更または無効になっています。設定を再確認してください。');
                }
            }
        }

        foreach ((array) ($settings['purchase_payment_accounts'] ?? []) as $sourceId => $savedSource) {
            $mapping = $mappings[(string) $sourceId] ?? null;
            if ($mapping === null || ! $this->isCompatiblePaymentSource($mapping)) {
                throw new RuntimeException('仕入商品の支払元科目がMoney Forward側で無効または変更されています。設定を再確認してください。');
            }
            if ((string) ($savedSource['account_id'] ?? '') !== $mapping['account_id']
                || ($savedSource['sub_account_id'] ?? null) !== $mapping['sub_account_id']) {
                throw new RuntimeException('仕入商品の支払元科目が保存時から変更されています。設定を再確認してください。');
            }
            if (! $taxesOptional && (string) ($savedSource['tax_id'] ?? '') !== $mapping['tax_id']) {
                throw new RuntimeException('仕入商品の支払元税区分が保存時から変更されています。設定を再確認してください。');
            }
            if (! $taxesOptional && filled($mapping['tax_id'])
                && ! in_array($mapping['tax_id'], $availableTaxIds, true)) {
                throw new RuntimeException('仕入商品の支払元税区分がMoney Forwardの有効な税区分マスタにありません。仕訳は送信していません。');
            }
        }

        return $currentTaxIds;
    }

    /** @return array<string, array<int, string>> */
    public function eligibleAccountIds(array $groups): array
    {
        $mappings = $this->accountMappings($groups);
        $eligible = [];
        foreach (['settlement', 'sales', 'fees', 'shipping', 'other', 'cost', 'inventory'] as $role) {
            $eligible[$role] = [];
            foreach ($mappings as $id => $mapping) {
                if ($this->isCompatibleAccountStatementType($role, $mapping)) {
                    $eligible[$role][] = (string) $id;
                }
            }
        }
        $eligible['purchase_payment_source'] = [];
        foreach ($mappings as $id => $mapping) {
            if ($this->isCompatiblePaymentSource($mapping)) {
                $eligible['purchase_payment_source'][] = (string) $id;
            }
        }

        return $eligible;
    }

    /** @return array<string, array{account_id: string, sub_account_id: ?string, tax_id: string, account_group: string, financial_statement_type: string, name: string, available: bool}> */
    private function accountMappings(array $groups): array
    {
        $mappings = [];
        foreach ($groups as $group) {
            if (is_array($group) && isset($group['id'])) {
                $accountId = (string) $group['id'];
                $available = ! array_key_exists('available', $group) || $group['available'] === true;
                $mappings[$accountId] = [
                    'account_id' => $accountId,
                    'sub_account_id' => null,
                    'tax_id' => (string) ($group['tax_id'] ?? ''),
                    'account_group' => (string) ($group['account_group'] ?? ''),
                    'financial_statement_type' => (string) ($group['financial_statement_type'] ?? ''),
                    'name' => (string) ($group['name'] ?? ''),
                    'available' => $available,
                ];
            }
            foreach ((array) ($group['sub_accounts'] ?? []) as $subAccount) {
                if (is_array($subAccount) && isset($subAccount['id'], $group['id'])) {
                    $subId = (string) $subAccount['id'];
                    $mappings[$subId] = [
                        'account_id' => (string) $group['id'],
                        'sub_account_id' => $subId,
                        'tax_id' => (string) ($subAccount['tax_id'] ?? ''),
                        'account_group' => (string) ($group['account_group'] ?? ''),
                        'financial_statement_type' => (string) ($group['financial_statement_type'] ?? ''),
                        'name' => trim((string) ($group['name'] ?? '').' / '.(string) ($subAccount['name'] ?? '')),
                        'available' => $available,
                    ];
                }
            }
        }

        return $mappings;
    }

    /** @param array{account_group: string, financial_statement_type: string} $mapping */
    private function assertAccountStatementType(string $role, array $mapping): void
    {
        if (! $this->isCompatibleAccountStatementType($role, $mapping)) {
            throw new RuntimeException('Money Forwardの勘定科目の区分が入力欄と合いません。選択した科目を確認してください。');
        }
    }

    /** @param array{account_group: string, financial_statement_type: string, available: bool} $mapping */
    private function isCompatibleAccountStatementType(string $role, array $mapping): bool
    {
        $group = $mapping['account_group'];
        $statementType = $mapping['financial_statement_type'];
        if (! $mapping['available'] || $group === '' || $statementType === '') {
            return false;
        }

        if (in_array($role, ['settlement', 'inventory'], true)) {
            return $group === 'ASSET' && $statementType === 'BALANCE_SHEET';
        }
        if ($role === 'sales') {
            return $group === 'REVENUE' && $statementType === 'PROFIT_LOSS';
        }

        return $group === 'EXPENSE' && $statementType === 'PROFIT_LOSS';
    }

    /** @param array{account_group: string, financial_statement_type: string, name: string, available: bool} $mapping */
    private function isCompatiblePaymentSource(array $mapping): bool
    {
        return $mapping['available']
            && $mapping['financial_statement_type'] === 'BALANCE_SHEET'
            && in_array($mapping['account_group'], ['ASSET', 'LIABILITY'], true)
            && trim((string) ($mapping['name'] ?? '')) !== '';
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
            throw new MoneyForwardUnauthorizedException('Money Forwardの認証トークンが無効です。連携を解除してから再連携してください。');
        }
        if ($response->status() === 403) {
            $messages = collect((array) $response->json('errors', []))
                ->pluck('message')
                ->filter(fn (mixed $message): bool => is_string($message));
            $missingScopes = $messages
                ->flatMap(function (string $message): array {
                    preg_match_all('/mfc\/[a-z0-9._\/-]+/i', $message, $matches);

                    return $matches[0] ?? [];
                })
                ->unique()
                ->values()
                ->all();

            if ($missingScopes !== []) {
                throw new RuntimeException('Money Forward会計APIで不足しているスコープ: '.implode(', ', $missingScopes).'。FURIMADECK_MF_SCOPEとアプリポータルのアプリ権限を確認し、連携を解除してから再連携してください。');
            }

            throw new RuntimeException('Money Forward会計APIの利用権限がありません。FURIMADECK_MF_SCOPEとアプリポータルのクラウド会計・確定申告権限を確認し、連携を解除してから再連携してください。');
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
