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

        $purchasePaymentCandidates = [];
        foreach ((array) ($options['accounts']['accounts'] ?? []) as $group) {
            if (! is_array($group) || ! isset($group['id'], $group['name'])) {
                continue;
            }
            $purchasePaymentCandidates[(string) $group['id']] = [
                'account_id' => (string) $group['id'],
                'sub_account_id' => null,
                'account_name' => trim((string) $group['name']),
                'sub_account_name' => '',
                'tax_id' => (string) ($group['tax_id'] ?? ''),
            ];
            foreach ((array) ($group['sub_accounts'] ?? []) as $subAccount) {
                if (is_array($subAccount) && isset($subAccount['id'], $subAccount['name'])) {
                    $purchasePaymentCandidates[(string) $subAccount['id']] = [
                        'account_id' => (string) $group['id'],
                        'sub_account_id' => (string) $subAccount['id'],
                        'account_name' => trim((string) $group['name']),
                        'sub_account_name' => trim((string) $subAccount['name']),
                        'tax_id' => (string) ($subAccount['tax_id'] ?? ''),
                    ];
                }
            }
        }
        foreach (($entries ?? collect())->filter(fn (AccountingEntry $entry): bool => $entry->entry_type === AccountingEntry::TYPE_PURCHASE) as $entry) {
            $sourceId = trim((string) data_get($entry->expense_breakdown, 'payment_sources.money_forward', ''));
            $savedSource = (array) ($settings['purchase_payment_accounts'][$sourceId] ?? []);
            $currentSource = $purchasePaymentCandidates[$sourceId] ?? null;
            $currentSourceName = $currentSource === null
                ? ''
                : trim($currentSource['account_name'].($currentSource['sub_account_name'] === '' ? '' : ' / '.$currentSource['sub_account_name']));
            if ($sourceId === '' || $currentSource === null
                || (string) ($savedSource['account_id'] ?? '') !== $currentSource['account_id']
                || ($savedSource['sub_account_id'] ?? null) !== $currentSource['sub_account_id']
                || trim((string) ($savedSource['name'] ?? '')) !== $currentSourceName) {
                throw new RuntimeException('Money Forwardの仕入支払元科目が最新の設定と一致しません。設定を再確認してください。仕入CSVは出力していません。');
            }
            $paymentRole = 'purchase_payment_'.$sourceId;
            $accounts[$paymentRole] = $currentSource['account_name'];
            $subAccounts[$paymentRole] = $currentSource['sub_account_name'];
            $paymentTaxId = trim($currentSource['tax_id']);
            $paymentTax = $taxes->get($paymentTaxId);
            $paymentTaxName = trim((string) ($paymentTax['name'] ?? $paymentTax['abbreviation'] ?? ''));
            if ($paymentTaxName === '') {
                throw new RuntimeException('Money Forwardの仕入支払元税区分を確認できません。仕入CSVは出力していません。');
            }

            foreach (['purchase_unit_cost', 'purchase_shipping_cost', 'other_purchase_expense'] as $expense) {
                $amount = $expense === 'purchase_unit_cost'
                    ? (int) $entry->purchase_cost
                    : (int) data_get($entry->expense_breakdown, $expense, 0);
                if ($amount <= 0) {
                    continue;
                }
                $evidence = (array) data_get($entry->expense_breakdown, 'evidence.'.$expense, []);
                $taxCategory = (string) ($evidence['tax_category'] ?? '');
                $invoiceIssuer = (string) ($evidence['invoice_issuer'] ?? '');
                $invoiceKind = app(FurimaDeckMoneyForwardInvoiceKindService::class)->forPurchaseEvidence(
                    $taxCategory,
                    $invoiceIssuer,
                    $entry->transaction_date?->toDateString() ?? '',
                    $expense === 'purchase_unit_cost' && $entry->purchase_special_case_data !== null,
                );
                $taxId = trim((string) ($settings['purchase_tax_ids'][$taxCategory] ?? ''));
                $purchaseTax = $taxes->get($taxId);
                $purchaseTaxName = trim((string) ($purchaseTax['name'] ?? $purchaseTax['abbreviation'] ?? ''));
                if ($invoiceKind === null || ! app(FurimaDeckMoneyForwardInvoiceKindService::class)->isValid($invoiceKind)
                    || ($taxesRequired && $purchaseTaxName === '')) {
                    throw new RuntimeException('Money Forwardの仕入証憑に対応する税区分・インボイス区分を確認できません。仕入CSVは出力していません。');
                }
                $roleSuffix = $entry->id.'_'.$expense;
                $taxNames['purchase_tax_'.$roleSuffix] = $purchaseTaxName;
                $taxNames['purchase_payment_tax_'.$roleSuffix] = $paymentTaxName;
                $invoiceKinds['purchase_invoice_'.$roleSuffix] = $invoiceKind;
                $invoiceKinds['purchase_payment_invoice_'.$roleSuffix] = $invoiceKind;
            }
        }

        $invoiceKindService = app(FurimaDeckMoneyForwardInvoiceKindService::class);
        foreach (($entries ?? collect())->filter(fn (AccountingEntry $entry): bool => $entry->entry_type === AccountingEntry::TYPE_SALE) as $entry) {
            $details = app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails($entry, 'money_forward', $settings);
            foreach ($details as $field => $detail) {
                $sourceId = trim((string) $detail['source_id']);
                $source = (array) $detail['source'];
                if ($sourceId === 'settlement') {
                    if ($field !== 'shipping_fee') {
                        throw new RuntimeException('売上入金から控除できるのは販売送料だけです。仕訳CSVは出力していません。');
                    }
                    $roleSourceId = trim((string) ($settings['sub_account_ids']['settlement'] ?? ''));
                    if ($roleSourceId === '') {
                        $roleSourceId = trim((string) ($settings['account_ids']['settlement'] ?? ''));
                    }
                    $roleSource = $purchasePaymentCandidates[$roleSourceId] ?? null;
                    if (! is_array($roleSource)
                        || $roleSource['account_id'] !== trim((string) ($source['account_id'] ?? ''))
                        || $roleSource['sub_account_id'] !== ($source['sub_account_id'] ?? null)) {
                        throw new RuntimeException('Money Forwardの販売送料控除先が現在の売掛金設定と一致しません。仕訳CSVは出力していません。');
                    }
                    $currentSource = $roleSource;
                    $paymentTaxId = trim((string) ($settings['tax_ids']['settlement'] ?? ''));
                } else {
                    $savedSource = (array) ($settings['purchase_payment_accounts'][$sourceId] ?? []);
                    $currentSource = $purchasePaymentCandidates[$sourceId] ?? null;
                    $currentSourceName = $currentSource === null
                        ? ''
                        : trim($currentSource['account_name'].($currentSource['sub_account_name'] === '' ? '' : ' / '.$currentSource['sub_account_name']));
                    if ($currentSource === null
                        || (string) ($savedSource['account_id'] ?? '') !== $currentSource['account_id']
                        || ($savedSource['sub_account_id'] ?? null) !== $currentSource['sub_account_id']
                        || trim((string) ($savedSource['name'] ?? '')) !== $currentSourceName
                        || $currentSource['account_id'] !== trim((string) ($source['account_id'] ?? ''))
                        || $currentSource['sub_account_id'] !== ($source['sub_account_id'] ?? null)) {
                        throw new RuntimeException('Money Forwardの販売費用支払元が最新の設定と一致しません。設定を再確認してください。仕訳CSVは出力していません。');
                    }
                    $paymentTaxId = trim((string) $currentSource['tax_id']);
                }

                $paymentRole = 'sale_payment_'.$sourceId;
                $accounts[$paymentRole] = $currentSource['account_name'];
                $subAccounts[$paymentRole] = $currentSource['sub_account_name'];
                $paymentTax = $taxes->get($paymentTaxId);
                $paymentTaxName = trim((string) ($paymentTax['name'] ?? $paymentTax['abbreviation'] ?? ''));
                if ($taxesRequired && $paymentTaxName === '') {
                    throw new RuntimeException('Money Forwardの販売費用支払元税区分を確認できません。仕訳CSVは出力していません。');
                }

                $evidence = (array) $detail['evidence'];
                $taxCategory = (string) ($evidence['tax_category'] ?? '');
                $invoiceIssuer = (string) ($evidence['invoice_issuer'] ?? '');
                $expenseTaxId = trim((string) ($settings['purchase_tax_ids'][$taxCategory] ?? ''));
                $expenseTax = $taxes->get($expenseTaxId);
                $expenseTaxName = trim((string) ($expenseTax['name'] ?? $expenseTax['abbreviation'] ?? ''));
                if ($taxesRequired) {
                    $invoiceKind = $invoiceKindService->forPurchaseEvidence(
                        $taxCategory,
                        $invoiceIssuer,
                        $entry->transaction_date?->toDateString() ?? '',
                    );
                    if ($invoiceKind === null || ! $invoiceKindService->isValid($invoiceKind) || $expenseTaxName === '') {
                        throw new RuntimeException('Money Forwardの販売費用証憑に対応する税区分・インボイス区分を確認できません。仕訳CSVは出力していません。');
                    }

                    $paymentInvoiceKind = $sourceId === 'settlement'
                        ? (string) ($invoiceKinds['settlement'] ?? '')
                        : $invoiceKind;
                    $invoiceKindService->csvLabel($paymentInvoiceKind, $entry->transaction_date->format('Y/m/d'));

                    $roleSuffix = $entry->id.'_'.$field;
                    $taxNames['sale_expense_tax_'.$roleSuffix] = $expenseTaxName;
                    $taxNames['sale_payment_tax_'.$roleSuffix] = $paymentTaxName;
                    $invoiceKinds['sale_expense_invoice_'.$roleSuffix] = $invoiceKind;
                    $invoiceKinds['sale_payment_invoice_'.$roleSuffix] = $paymentInvoiceKind;
                }
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
