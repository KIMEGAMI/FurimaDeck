<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

class FurimaDeckFreeeCsvMappingService
{
    /** @return array{accounts: array<string, string>, tax_names: array<string, string>} */
    public function forEntries(User $user, Collection $entries): array
    {
        $connection = $user->accountingConnections()
            ->where('provider', 'freee')
            ->where('status', 'connected')
            ->first();
        if ($connection === null) {
            throw new RuntimeException('freee CSVを作る前にfreee連携と仕訳設定を完了してください。');
        }

        $settings = (array) ($connection->settings ?? []);
        $companyId = (string) ($settings['company_id'] ?? '');
        if (! ctype_digit($companyId) || (int) $companyId <= 0) {
            throw new RuntimeException('freeeの事業所が未設定です。');
        }

        $setup = app(FurimaDeckFreeeAccountingSetupService::class);
        $options = $setup->options($user, (int) $companyId);
        $connection = $options['connection'];
        $accountItems = collect($options['account_items'])->keyBy('id');
        $taxNamesByCode = collect($options['tax_codes'])->pluck('name_ja', 'code');
        $accountIds = (array) ($settings['account_item_ids'] ?? []);
        $accountDetails = $setup->accountItemDetails($connection, (int) $companyId, array_values($accountIds));
        $savedTaxCodes = (array) ($settings['tax_codes'] ?? []);
        $accounts = [];
        $taxNames = [];
        $maxAccountNameCharacters = (int) config('furimadeck.accounting.csv_limits.freee.max_account_name_characters');

        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory', 'shipping', 'other'] as $role) {
            $accountId = trim((string) ($accountIds[$role] ?? ''));
            if ($accountId === '') {
                continue;
            }
            $item = $accountItems->get((int) $accountId);
            $detail = $accountDetails[$accountId] ?? null;
            if (! is_array($item) || ($item['available'] ?? false) !== true
                || ! is_array($detail) || (($detail['available'] ?? true) !== true)) {
                throw new RuntimeException('freeeの「'.FurimaDeckFreeeAccountingSetupService::roleLabel($role).'」用勘定科目は現在利用できません。設定を見直してください。CSVは出力していません。');
            }
            $categories = is_array($detail) ? FurimaDeckFreeeAccountingSetupService::accountCategoryValues($detail) : [];
            if (! FurimaDeckFreeeAccountingSetupService::categoryMatchesCsvRole($role, $categories)) {
                if ($role === 'settlement') {
                    throw new RuntimeException('売上日には実際の入金を確認できないため、freeeの売上科目に「売掛金」を設定してください。銀行口座科目のままではCSVを出力しません。');
                }
                throw new RuntimeException('freeeの「'.FurimaDeckFreeeAccountingSetupService::roleLabel($role).'」用勘定科目の区分を確認できません。設定を見直してください。CSVは出力していません。');
            }
            $accountName = trim((string) ($detail['name'] ?? $item['name'] ?? ''));
            $taxName = trim((string) $taxNamesByCode->get((string) ($savedTaxCodes[$role] ?? ''), ''));
            if ($accountName === '' || mb_strlen($accountName) > $maxAccountNameCharacters || $taxName === '') {
                throw new RuntimeException('freeeの'.$role.'用勘定科目または税区分を確認できません。CSVは出力していません。');
            }
            $accounts[$role] = $accountName;
            $taxNames[$role] = $taxName;
        }

        foreach (['settlement', 'sales', 'fees', 'cost', 'inventory'] as $role) {
            if (! isset($accounts[$role])) {
                throw new RuntimeException('freeeの仕訳設定が不足しています: '.$role);
            }
        }

        $syncService = app(FurimaDeckFreeeAccountingSyncService::class);
        foreach ($entries->filter(fn ($entry): bool => $entry->entry_type === AccountingEntry::TYPE_PURCHASE) as $entry) {
            $resolved = $syncService->validatedPurchaseTaxCodesForDate($connection, $companyId, $accountIds, $entry);
            $sourceId = trim((string) data_get($entry->expense_breakdown, 'payment_sources.freee', ''));
            $paymentItem = $accountItems->get((int) $sourceId);
            $paymentTaxName = trim((string) $taxNamesByCode->get((string) ($resolved['purchase_payment'] ?? ''), ''));
            if ($sourceId === '' || ! is_array($paymentItem) || ! ($paymentItem['available'] ?? false)
                || $paymentTaxName === '' || mb_strlen((string) ($paymentItem['name'] ?? '')) > $maxAccountNameCharacters) {
                throw new RuntimeException('freeeの仕入支払元科目または税区分を確認できません。CSVは出力していません。');
            }
            $accounts['purchase_payment_'.$sourceId] = (string) $paymentItem['name'];
            $amounts = [
                'purchase_unit_cost' => (int) $entry->purchase_cost,
                'purchase_shipping_cost' => (int) data_get($entry->expense_breakdown, 'purchase_shipping_cost', 0),
                'other_purchase_expense' => (int) data_get($entry->expense_breakdown, 'other_purchase_expense', 0),
            ];
            foreach ($amounts as $expense => $amount) {
                if ($amount === 0) {
                    continue;
                }
                $taxName = trim((string) $taxNamesByCode->get((string) ($resolved[$expense] ?? ''), ''));
                if ($taxName === '') {
                    throw new RuntimeException('freeeの仕入証憑に対応する税区分名を確認できません。CSVは出力していません。');
                }
                $taxNames['purchase_tax_'.$entry->id.'_'.$expense] = $taxName;
                $taxNames['purchase_payment_tax_'.$entry->id.'_'.$expense] = $paymentTaxName;
            }
        }

        foreach ($entries->filter(fn ($entry): bool => $entry->entry_type !== AccountingEntry::TYPE_PURCHASE) as $entry) {
            $breakdown = (array) $entry->expense_breakdown;
            if (! collect(FurimaDeckSaleExpenseAccountingService::EXPENSES)
                ->keys()
                ->contains(fn (string $field): bool => (int) ($breakdown[$field] ?? 0) > 0)) {
                continue;
            }

            $details = app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails($entry, 'freee', $settings);
            $resolvedTaxCodes = $syncService->validatedSaleExpenseTaxCodesForDate(
                $connection,
                $companyId,
                $accountIds,
                $entry,
                $settings,
            );
            foreach ($details as $field => $detail) {
                $sourceId = (string) $detail['source_id'];
                $sourceAccountId = (string) ($detail['source']['account_id'] ?? '');
                $sourceItem = ctype_digit($sourceAccountId) ? $accountItems->get((int) $sourceAccountId) : null;
                $expenseTaxName = trim((string) $taxNamesByCode->get((string) ($resolvedTaxCodes['sale_expense_'.$field] ?? ''), ''));
                $paymentTaxName = trim((string) $taxNamesByCode->get((string) ($resolvedTaxCodes['sale_payment_'.$field] ?? ''), ''));
                if (! is_array($sourceItem) || ($sourceItem['available'] ?? false) !== true
                    || trim((string) ($sourceItem['name'] ?? '')) === ''
                    || mb_strlen((string) $sourceItem['name']) > $maxAccountNameCharacters
                    || $expenseTaxName === '' || $paymentTaxName === '') {
                    throw new RuntimeException('freeeの費目別支払元科目または取引日税区分を確認できません。CSVは出力していません。');
                }
                $accounts['sale_payment_'.$sourceId] = (string) $sourceItem['name'];
                $taxNames['sale_expense_tax_'.$entry->id.'_'.$field] = $expenseTaxName;
                $taxNames['sale_payment_tax_'.$entry->id.'_'.$field] = $paymentTaxName;
            }
        }

        foreach ($entries->filter(fn ($entry): bool => $entry->entry_type !== AccountingEntry::TYPE_PURCHASE)
            ->unique(fn ($entry): string => $entry->transaction_date->toDateString()) as $entry) {
            $syncService->validatedTaxCodesForDate($connection, $companyId, $accountIds, $entry);
        }

        return ['accounts' => $accounts, 'tax_names' => $taxNames];
    }
}
