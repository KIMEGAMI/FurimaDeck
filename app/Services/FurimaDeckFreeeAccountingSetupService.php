<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\User;
use RuntimeException;

class FurimaDeckFreeeAccountingSetupService
{
    private const ROLE_CATEGORIES = [
        'settlement' => ['現金・預金', '売掛金'],
        'sales' => ['収入金額', '売上高'],
        'fees' => ['経費', '販売管理費'],
        'shipping' => ['経費', '販売管理費'],
        'other' => ['経費', '販売管理費'],
        'cost' => ['売上原価', '当期商品仕入'],
        'inventory' => ['棚卸資産'],
    ];

    private const ROLE_LABELS = [
        'settlement' => '売掛金（売上日・入金前）',
        'sales' => '売上',
        'fees' => '販売手数料',
        'shipping' => '送料',
        'other' => 'その他費用',
        'cost' => '仕入原価',
        'inventory' => '商品在庫',
    ];

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

    /** @return array{company: array{id:int,name:string,display_name:string}, account_items: list<array{id:int,name:string,default_tax_code:string,default_tax_name:string,account_category:string,categories:list<string>,available:bool}>, tax_codes: list<array{code:string,name:string,name_ja:string}>, settings: array<string,mixed>, connection: AccountingConnection} */
    public function options(User $user, int $companyId): array
    {
        $company = collect($this->companies($user)['companies'])->firstWhere('id', $companyId);
        if ($company === null) {
            throw new RuntimeException('選択したfreee事業所を確認できません。');
        }

        $connection = $this->connection($user);
        $accountItems = $this->get($connection, '/api/1/account_items', ['company_id' => $companyId]);
        $taxes = $this->get($connection, '/api/1/taxes/companies/'.$companyId);

        $taxCodes = collect($taxes['taxes'] ?? $taxes['tax_codes'] ?? [])->map(static fn (array $tax): array => [
            'code' => (string) ($tax['code'] ?? $tax['tax_code'] ?? ''),
            'name' => (string) ($tax['name'] ?? $tax['tax_name'] ?? ''),
            'name_ja' => (string) ($tax['name_ja'] ?? ''),
        ])->filter(static fn (array $tax): bool => $tax['code'] !== '' && $tax['name'] !== '')->values();
        $taxNames = $taxCodes->pluck('name_ja', 'code');
        $normalizedAccountItems = collect($accountItems['account_items'] ?? [])->map(static function (array $item) use ($taxNames): array {
            $normalized = [
                'id' => (int) ($item['id'] ?? 0),
                'name' => (string) ($item['name'] ?? ''),
                'default_tax_code' => (string) ($item['default_tax_code'] ?? ''),
                'account_category' => (string) ($item['account_category'] ?? ''),
                'categories' => array_values(array_filter((array) ($item['categories'] ?? []), 'is_string')),
                'available' => ($item['available'] ?? true) === true,
            ];
            $normalized['default_tax_name'] = (string) ($taxNames[$normalized['default_tax_code']] ?? '保存時に確認');

            return $normalized;
        })->filter(static fn (array $item): bool => $item['id'] > 0 && $item['name'] !== '')->sortBy('name')->values();

        return [
            'company' => $company,
            'account_items' => $normalizedAccountItems->all(),
            'purchase_payment_account_items' => $normalizedAccountItems
                ->filter(static fn (array $item): bool => $item['available']
                    && (in_array('資産', $item['categories'], true) || in_array('負債', $item['categories'], true)))
                ->values()->all(),
            'tax_codes' => $taxCodes->sortBy('name')->values()->all(),
            'settings' => (array) ($connection->settings ?? []),
            'connection' => $connection,
        ];
    }

    public function save(User $user, array $values): void
    {
        $connection = $this->connection($user);
        $options = $this->options($user, (int) $values['company_id']);
        $listedAccounts = collect($options['account_items'])->keyBy('id');
        $availableAccountIds = $listedAccounts
            ->filter(static fn (array $item): bool => ($item['available'] ?? false) === true)
            ->keys()
            ->map(static fn (mixed $id): string => (string) $id);
        $selected = collect($values['account_item_ids'])
            ->filter(static fn (mixed $id): bool => $id !== null && (string) $id !== '');
        $selectedIds = $selected->map(static fn (mixed $id): string => (string) $id);
        $requiredRoles = ['settlement', 'sales', 'fees', 'cost', 'inventory'];
        if ($selected->keys()->diff([...$requiredRoles, 'shipping', 'other'])->isNotEmpty()
            || collect($requiredRoles)->diff($selected->keys())->isNotEmpty()
            || $selectedIds->diff($availableAccountIds)->isNotEmpty()) {
            throw new RuntimeException('freeeから取得した利用可能な勘定科目だけを保存できます。');
        }
        if ($selectedIds->only($requiredRoles)->unique()->count() !== count($requiredRoles)) {
            throw new RuntimeException('仕訳の各役割には異なるfreee勘定科目を選択してください。');
        }

        $accountDetails = $this->accountItemDetails($connection, (int) $values['company_id'], $selectedIds->all());
        $taxCodes = [];
        $accountCategories = [];
        foreach ($selectedIds as $role => $id) {
            $account = $accountDetails[$id] ?? null;
            $listedAccount = $listedAccounts->get((int) $id);
            $categories = is_array($account) ? self::accountCategoryValues($account) : [];
            if (! is_array($account)
                || ! is_array($listedAccount)
                || ($listedAccount['available'] ?? false) !== true
                || (($account['available'] ?? true) !== true)
                || ! self::categoryMatchesCsvRole((string) $role, $categories)) {
                throw new RuntimeException('freeeの「'.self::roleLabel((string) $role).'」用勘定科目の区分を確認できません。勘定科目の用途を見直してください。');
            }
            $taxCode = trim((string) ($account['default_tax_code'] ?? ''));
            if ($taxCode === '' || ! collect($options['tax_codes'])->contains(fn (array $tax): bool => $tax['code'] === $taxCode)) {
                throw new RuntimeException('選択したfreee勘定科目に標準税区分がありません。freee側で科目の標準税区分を設定してください。');
            }
            $taxCodes[$role] = $taxCode;
            $accountCategories[$role] = $categories;
        }
        if (count(array_unique($taxCodes)) === 1) {
            throw new RuntimeException('全科目が同じ税区分です。freee側の科目設定を確認してください。');
        }

        $existingSettings = (array) ($connection->settings ?? []);
        $purchasePaymentIds = array_key_exists('purchase_payment_account_ids_present', $values)
            ? collect((array) ($values['purchase_payment_account_ids'] ?? []))->map(static fn (mixed $id): string => (string) $id)->unique()->values()
            : collect(array_keys((array) ($existingSettings['purchase_payment_accounts'] ?? [])))->map(static fn (mixed $id): string => (string) $id);
        $purchasePaymentOptions = collect($options['purchase_payment_account_items'])->keyBy(static fn (array $item): string => (string) $item['id']);
        if ($purchasePaymentIds->diff($purchasePaymentOptions->keys())->isNotEmpty()) {
            throw new RuntimeException('支払元候補にはfreeeから取得した有効な資産・負債科目だけを選択してください。');
        }
        $purchasePaymentAccounts = $purchasePaymentIds->mapWithKeys(static function (string $id) use ($purchasePaymentOptions): array {
            $item = $purchasePaymentOptions->get($id);

            return [$id => [
                'account_id' => $id,
                'name' => $item['name'],
                'account_category' => $item['account_category'],
            ]];
        })->all();

        $connection->update([
            'external_account_id' => (string) $values['company_id'],
            'settings' => array_merge($existingSettings, [
                'company_id' => (string) $values['company_id'],
                'tax_codes' => $taxCodes,
                'account_item_ids' => $selectedIds->all(),
                'account_categories' => $accountCategories,
                'purchase_payment_accounts' => $purchasePaymentAccounts,
            ]),
            'last_error' => null,
        ]);
    }

    /** @param array<int, mixed> $accountIds @return array<string, array<string, mixed>> */
    public function accountItemDetails(AccountingConnection $connection, int $companyId, array $accountIds): array
    {
        if ($companyId <= 0) {
            throw new RuntimeException('freeeの事業所が未設定です。CSVは出力していません。');
        }

        $details = [];
        foreach (collect($accountIds)->map(static fn (mixed $id): string => trim((string) $id))->filter()->unique() as $id) {
            if (! ctype_digit($id) || (int) $id <= 0) {
                throw new RuntimeException('freeeの勘定科目IDを確認できません。CSVは出力していません。');
            }
            $payload = $this->get($connection, '/api/1/account_items/'.(int) $id, ['company_id' => $companyId]);
            $account = $payload['account_item'] ?? null;
            if (! is_array($account) || (string) ($account['id'] ?? '') !== $id) {
                throw new RuntimeException('freeeの勘定科目詳細を確認できません。CSVは出力していません。');
            }
            $details[$id] = $account;
        }

        return $details;
    }

    /** @param array<string, mixed> $account @return list<string> */
    public static function accountCategoryValues(array $account): array
    {
        $categories = collect((array) ($account['categories'] ?? []))
            ->filter(static fn (mixed $category): bool => is_string($category) && trim($category) !== '')
            ->map(static fn (string $category): string => trim($category));
        $legacyCategory = $account['account_category'] ?? null;
        $legacyCategory = is_string($legacyCategory) ? trim($legacyCategory) : '';
        if ($legacyCategory !== '') {
            $categories->push($legacyCategory);
        }

        return $categories->unique()->values()->all();
    }

    /** @param list<string> $categories */
    public static function categoryMatchesRole(string $role, array $categories): bool
    {
        $allowed = self::ROLE_CATEGORIES[$role] ?? [];

        return $allowed !== [] && array_intersect($allowed, $categories) !== [];
    }

    /** @param list<string> $categories */
    public static function categoryMatchesCsvRole(string $role, array $categories): bool
    {
        if ($role === 'settlement') {
            return in_array('売掛金', $categories, true);
        }

        return self::categoryMatchesRole($role, $categories);
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? $role;
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

        $response = app(FurimaDeckFreeeApiReadService::class)->get($connection, $baseUrl.$path, $query);
        if ($response->failed() || ! is_array($response->json())) {
            throw new RuntimeException('freeeの会計マスタを取得できませんでした。');
        }

        return $response->json();
    }
}
