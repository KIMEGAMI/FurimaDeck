<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

class FurimaDeckMoneyForwardCsvMappingService
{
    /** @return array{accounts: array<string, string>, sub_accounts: array<string, string>, tax_names: array<string, string>, invoice_kinds: array<string, string>, business_status_by_date: array<string, string>} */
    public function forUser(User $user, ?Collection $entries = null): array
    {
        if ($entries?->contains(fn ($entry): bool => $entry->entry_type === AccountingEntry::TYPE_PURCHASE) ?? false) {
            throw new RuntimeException('Money Forward用CSVでは仕入の支払元側インボイス区分を正確に表現できません。誤登録防止のため、FurimaDeck 1.0では仕入CSVに対応していません。CSVは作成していません。');
        }

        $setup = app(FurimaDeckMoneyForwardAccountingSetupService::class);
        $connection = $setup->connection($user);
        $settings = (array) ($connection->settings ?? []);
        $businessPeriodService = app(FurimaDeckMoneyForwardBusinessPeriodService::class);
        $businessStatusesByDate = collect($entries ?? [])->mapWithKeys(function (AccountingEntry $entry) use ($businessPeriodService, $settings): array {
            $status = $businessPeriodService->statusForDate(
                $settings,
                $entry->transaction_date?->toDateString() ?? '',
            );
            if ($status === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED) {
                throw new RuntimeException('取引日に適用するMoney Forward事業者の課税・免税状態が未確認のため、仕訳CSVを出力していません。');
            }

            return [$entry->transaction_date?->format('Y/m/d') ?? '' => $status];
        });
        $businessStatuses = $businessStatusesByDate->values();
        if ($businessStatuses->isEmpty()) {
            throw new RuntimeException('対象の会計明細を確認できないため、Money Forward仕訳CSVを出力していません。');
        }
        $taxesRequired = $businessStatuses->contains(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
        $options = $setup->options($user);
        $setup->assertCurrentConfiguration(
            $user,
            $connection,
            $taxesRequired
                ? FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE
                : FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT,
            $options,
        );

        foreach ($entries ?? collect() as $entry) {
            app(FurimaDeckSaleExpenseAccountingService::class)->assertClassified($entry);
            $details = app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails($entry, 'money_forward', $settings);
            if ($details !== []) {
                throw new RuntimeException('Money Forward用CSVでは、別途支払った費用に必要なインボイス区分を正確に表現できません。誤登録防止のため、FurimaDeck 1.0ではこの費用を含む明細のCSVに対応していません。CSVは作成していません。');
            }
        }

        $groups = collect($options['accounts']['accounts'] ?? [])->keyBy(fn (array $group): string => (string) ($group['id'] ?? ''));
        $taxes = collect($options['taxes']['taxes'] ?? [])->keyBy(fn (array $tax): string => (string) ($tax['id'] ?? ''));
        $accounts = [];
        $subAccounts = [];
        $taxNames = [];
        $invoiceKinds = (array) ($settings['invoice_kinds'] ?? []);
        $csvLimits = (array) config('furimadeck.accounting.csv_limits.money_forward', []);
        $maxAccountNameCharacters = (int) ($csvLimits['max_account_name_characters'] ?? 0);
        $maxSubAccountNameCharacters = (int) ($csvLimits['max_sub_account_name_characters'] ?? 0);

        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory', 'shipping', 'other'] as $role) {
            $accountId = trim((string) ($settings['account_ids'][$role] ?? ''));
            if ($accountId === '') {
                continue;
            }
            $group = $groups->get($accountId);
            if ($role === 'settlement' && trim((string) ($group['name'] ?? '')) !== '売掛金') {
                throw new RuntimeException('売上日には実際の入金を確認できないため、Money Forwardの売上科目に「売掛金」を設定してください。銀行口座科目のままではCSVを出力しません。');
            }
            $accountName = trim((string) ($group['name'] ?? ''));
            $subId = (string) ($settings['sub_account_ids'][$role] ?? '');
            $sub = $subId === '' ? null : collect($group['sub_accounts'] ?? [])->first(
                fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $subId,
            );
            $subName = $subId === '' ? '' : trim((string) ($sub['name'] ?? ''));
            $tax = $taxes->get((string) ($settings['tax_ids'][$role] ?? ''));
            $taxName = trim((string) ($tax['name'] ?? $tax['abbreviation'] ?? ''));
            if ($accountName === '' || $maxAccountNameCharacters < 1 || mb_strlen($accountName) > $maxAccountNameCharacters
                || ($subId !== '' && ($maxSubAccountNameCharacters < 1 || $subName === '' || mb_strlen($subName) > $maxSubAccountNameCharacters))
                || ($taxesRequired && $taxName === '')) {
                throw new RuntimeException('Money Forwardの'.$role.'用勘定科目・補助科目・税区分を確認できません。CSVは出力していません。');
            }
            $accounts[$role] = $accountName;
            $subAccounts[$role] = $subName;
            $taxNames[$role] = $taxName;
        }

        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $role) {
            if (! isset($accounts[$role])) {
                throw new RuntimeException('Money Forwardの仕訳設定が不足しています: '.$role);
            }
        }
        if ($taxesRequired) {
            foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $role) {
                if (! app(FurimaDeckMoneyForwardInvoiceKindService::class)->isValid((string) ($invoiceKinds[$role] ?? ''))) {
                    throw new RuntimeException('Money Forwardの'.$role.'側のインボイス区分を設定してください。CSVは出力していません。');
                }
            }
        }

        $mapping = [
            'accounts' => $accounts,
            'sub_accounts' => $subAccounts,
            'tax_names' => $taxNames,
            'invoice_kinds' => $invoiceKinds,
            'business_status_by_date' => $businessStatusesByDate->all(),
        ];

        return $mapping;
    }
}
