<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use Illuminate\Support\Collection;
use RuntimeException;

class FurimaDeckAccountingVendorCsvService
{
    /** @return array<string, string> */
    public function accounts(?array $configured = null, array $requiredRoles = ['settlement', 'sales', 'fees', 'cost', 'inventory']): array
    {
        $accounts = $configured ?? config('furimadeck.accounting.export_accounts', []);
        $missing = collect($requiredRoles)->filter(fn (string $key): bool => trim((string) ($accounts[$key] ?? '')) === '')->values();
        if ($missing->isNotEmpty()) {
            if ($missing->contains(fn (string $key): bool => str_starts_with($key, 'purchase_payment_') || str_starts_with($key, 'sale_payment_'))) {
                throw new RuntimeException('この商品の選択済み支払元科目が会計サービス設定にありません。仕訳CSVを出力していません。');
            }
            throw new RuntimeException('会計CSVの勘定科目設定が不足しています: '.$missing->implode(', '));
        }

        $normalized = collect(['settlement', 'sales', 'fees', 'cost', 'inventory', 'shipping', 'other'])
            ->mapWithKeys(fn (string $key): array => [$key => trim((string) ($accounts[$key] ?? ''))])
            ->all();
        foreach ($accounts as $key => $name) {
            if (is_string($key) && preg_match('/^(?:purchase_payment|sale_payment)_[A-Za-z0-9_-]{1,64}$/', $key) === 1) {
                $normalized[$key] = trim((string) $name);
            }
        }

        return $normalized;
    }

    /** @param Collection<int, AccountingEntry> $entries */
    public function assertProviderCsvScope(Collection $entries, string $provider): void
    {
        if (! in_array($provider, ['freee', 'money_forward'], true)) {
            throw new RuntimeException('会計サービスの指定が正しくありません。仕訳CSVを出力していません。');
        }

        foreach ($entries as $entry) {
            $issue = $this->providerCsvScopeIssue($entry, $provider);
            if ($issue !== null) {
                throw new RuntimeException($issue);
            }
        }
    }

    public function providerCsvScopeIssue(AccountingEntry $entry, string $provider): ?string
    {
        if (! in_array($provider, ['freee', 'money_forward'], true)) {
            throw new RuntimeException('会計サービスの指定が正しくありません。仕訳CSVを出力していません。');
        }

        $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';

        if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
            return $providerName.'用仕入CSVはFurimaDeck 1.0の対応対象外です。仕入は会計サービス側で別途登録してください。';
        }

        if ($entry->entry_type !== AccountingEntry::TYPE_SALE) {
            return '対応していない会計データ種別が含まれるため、仕訳CSVを出力していません。';
        }

        if ((int) $entry->selling_fee !== 0) {
            return $providerName.'用CSVで販売手数料を含む明細は、実取込の検証が完了していないためFurimaDeck 1.0では出力対象外です。会計サービス側で別途登録してください。';
        }

        if ((int) $entry->shipping_cost !== 0) {
            return '販売送料の支払元が未確認です。売上入金から差し引かれたか別途支払ったかを確認できないため、会計ソフト用CSVは出力していません。';
        }

        $breakdown = (array) $entry->expense_breakdown;
        $hasSeparateExpenses = (int) $entry->other_cost !== 0
            || collect(array_keys(FurimaDeckSaleExpenseAccountingService::EXPENSES))
                ->contains(fn (string $field): bool => (int) ($breakdown[$field] ?? 0) !== 0);
        if ($hasSeparateExpenses) {
            return $providerName.'用CSVで別途支払った販売費用を含む明細はFurimaDeck 1.0の対応対象外です。会計サービス側で別途登録してください。';
        }

        return null;
    }

    /** @return array<int, array<string, string|int>> */
    public function journalRows(Collection $entries, ?array $configuredAccounts = null, ?string $provider = null): array
    {
        if ($provider !== null && ! in_array($provider, ['freee', 'money_forward'], true)) {
            throw new RuntimeException('会計サービスの指定が正しくありません。仕訳CSVを出力していません。');
        }
        if ($provider !== null) {
            $this->assertProviderCsvScope($entries, $provider);
        }

        $requiredRoles = [];
        foreach ($entries as $entry) {
            if (! in_array($entry->entry_type, [AccountingEntry::TYPE_SALE, AccountingEntry::TYPE_PURCHASE], true)) {
                throw new RuntimeException('対応していない会計データ種別が含まれるため、仕訳CSVを出力していません。');
            }

            if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                $sourceId = $this->purchasePaymentSourceId($entry, $provider);
                if ($sourceId === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sourceId) !== 1) {
                    throw new RuntimeException('仕入商品の会計サービス別支払元が未設定または無効です。仕訳CSVを出力していません。');
                }
                $requiredRoles[] = 'inventory';
                $requiredRoles[] = 'purchase_payment_'.$sourceId;
            } else {
                if ($provider !== null && (int) $entry->shipping_cost > 0) {
                    throw new RuntimeException('販売送料の支払元が未確認です。売上入金から差し引かれたか別途支払ったかを確認できないため、会計ソフト用CSVは出力していません。');
                }
                $requiredRoles = [...$requiredRoles, 'settlement', 'sales', 'fees', 'cost', 'inventory'];
                $saleExpenseDetails = [];
                if ($provider !== null) {
                    $connection = AccountingConnection::query()->where('user_id', $entry->user_id)->where('provider', $provider)->first();
                    $saleExpenseDetails = app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails($entry, $provider, (array) ($connection?->settings ?? []));
                } elseif (collect(FurimaDeckSaleExpenseAccountingService::EXPENSES)->keys()->contains(fn (string $field): bool => (int) data_get($entry->expense_breakdown, $field, 0) > 0)) {
                    throw new RuntimeException('会計サービス別の支払元を確認できないため、仕訳CSVを出力していません。');
                }
                foreach ($saleExpenseDetails as $expense) {
                    $requiredRoles[] = 'other';
                    $requiredRoles[] = 'sale_payment_'.$expense['source_id'];
                }
            }
        }
        $accounts = $this->accounts($configuredAccounts, array_values(array_unique($requiredRoles)));
        $rows = [];

        foreach ($entries as $entry) {
            if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                $this->purchaseRows($rows, $entry, $accounts, $provider);

                continue;
            }
            $date = $entry->transaction_date?->format('Y/m/d') ?? '';
            $number = (string) $entry->getKey();
            $title = (string) $entry->title;
            $this->addRow($rows, $date, $number, 'settlement', $accounts['settlement'], (int) $entry->sale_amount, 'sales', $accounts['sales'], (int) $entry->sale_amount, $title.' 売上');

            foreach (['selling_fee' => ['fees', '販売手数料'], 'shipping_cost' => ['shipping', '送料']] as $field => [$accountKey, $label]) {
                $amount = (int) $entry->{$field};
                if ($amount <= 0) {
                    continue;
                }
                if ($accounts[$accountKey] === '') {
                    throw new RuntimeException('会計CSVの勘定科目設定が不足しています: '.$accountKey);
                }
                $this->addRow($rows, $date, $number, $accountKey, $accounts[$accountKey], $amount, 'settlement', $accounts['settlement'], $amount, $title.' '.$label);
            }
            if ($provider !== null) {
                $connection = $entry->user?->accountingConnections()->where('provider', $provider)->first();
                $saleExpenseDetails = app(FurimaDeckSaleExpenseAccountingService::class)->providerDetails($entry, $provider, (array) ($connection?->settings ?? []));
                $breakdown = (array) $entry->expense_breakdown;
                foreach ($saleExpenseDetails as $field => $expense) {
                    $amount = (int) ($breakdown[$field] ?? 0);
                    if ($amount <= 0) {
                        throw new RuntimeException('会計CSVの費目別金額が会計明細と一致しません。仕訳CSVを出力していません。');
                    }
                    $sourceId = (string) $expense['source_id'];
                    $paymentRole = 'sale_payment_'.$sourceId;
                    if (trim((string) ($accounts['other'] ?? '')) === '' || trim((string) ($accounts[$paymentRole] ?? '')) === '') {
                        throw new RuntimeException('費目別の支払元・経費科目を確認できないため、仕訳CSVを出力していません。');
                    }
                    $expenseTaxRole = 'sale_expense_tax_'.$entry->id.'_'.$field;
                    $paymentTaxRole = 'sale_payment_tax_'.$entry->id.'_'.$field;
                    $expenseAccountRole = 'other';
                    $this->addRow(
                        $rows,
                        $date,
                        $number,
                        $expenseTaxRole,
                        $accounts['other'],
                        $amount,
                        $paymentTaxRole,
                        $accounts[$paymentRole],
                        $amount,
                        app(FurimaDeckInvoiceTransitionCapService::class)->appendAccountingMemo(
                            $title.' '.FurimaDeckSaleExpenseAccountingService::EXPENSES[$field],
                            app(FurimaDeckInvoiceTransitionCapService::class)->accountingMemoForEvidence(
                                (int) $entry->user_id,
                                $entry->transaction_date->toDateString(),
                                $expense['evidence'],
                            ),
                        ),
                        $expenseTaxRole,
                        $paymentTaxRole,
                        $expenseAccountRole,
                        $paymentRole,
                    );
                }
            }
            $cost = $provider === null
                ? (int) $entry->purchase_cost
                : app(FurimaDeckAccountingPurchaseReconciliationService::class)
                    ->costOfGoodsSoldForSale($entry, $provider);
            if ($cost > 0) {
                $this->addRow($rows, $date, $number, 'cost', $accounts['cost'], $cost, 'inventory', $accounts['inventory'], $cost, $title.' 原価');
            }
        }

        return $rows;
    }

    /** @param array<int, array<string, string|int>> $rows @param array<string, string> $accounts */
    private function purchaseRows(array &$rows, AccountingEntry $entry, array $accounts, ?string $provider): void
    {
        $breakdown = (array) $entry->expense_breakdown;
        $sourceId = $this->purchasePaymentSourceId($entry, $provider);
        $paymentRole = 'purchase_payment_'.$sourceId;
        if ($sourceId === '' || trim((string) ($accounts[$paymentRole] ?? '')) === '') {
            throw new RuntimeException('この商品の会計サービス別支払元が勘定科目設定にありません。仕訳CSVを出力していません。');
        }

        $amounts = [
            'purchase_unit_cost' => (int) $entry->purchase_cost,
            'purchase_shipping_cost' => (int) ($breakdown['purchase_shipping_cost'] ?? 0),
            'other_purchase_expense' => (int) ($breakdown['other_purchase_expense'] ?? 0),
        ];
        if (min($amounts) < 0
            || $amounts['purchase_unit_cost'] <= 0
            || (int) ($breakdown['purchase_quantity'] ?? 0) !== 1
            || $amounts['purchase_unit_cost'] !== (int) ($breakdown['purchase_unit_cost'] ?? 0)
            || (int) $entry->other_cost !== $amounts['purchase_shipping_cost'] + $amounts['other_purchase_expense']) {
            throw new RuntimeException('仕入金額の内訳が会計明細と一致しません。仕訳CSVを出力していません。');
        }

        $date = $entry->transaction_date?->format('Y/m/d') ?? '';
        $number = (string) $entry->getKey();
        if ($date === '' || $number === '' || $entry->transaction_date === null) {
            throw new RuntimeException('仕入会計明細の日付または番号が不足しています。仕訳CSVを出力していません。');
        }
        $special = $entry->purchase_special_case_data;
        $labels = [
            'purchase_unit_cost' => '商品仕入',
            'purchase_shipping_cost' => '仕入送料',
            'other_purchase_expense' => 'その他仕入費',
        ];
        foreach ($amounts as $expense => $amount) {
            if ($amount === 0) {
                continue;
            }
            $roleSuffix = $entry->getKey().'_'.$expense;
            $isAntiqueDealerException = $special !== null && $expense === 'purchase_unit_cost';
            $evidence = (array) data_get($breakdown, 'evidence.'.$expense, []);
            $capMemo = app(FurimaDeckInvoiceTransitionCapService::class)->accountingMemoForEvidence(
                (int) $entry->user_id,
                $entry->transaction_date->toDateString(),
                $evidence,
                $isAntiqueDealerException,
            );
            $description = $this->purchaseDescription($entry, $labels[$expense], $isAntiqueDealerException ? $special : null, $capMemo);
            $this->addRow(
                $rows,
                $date,
                $number,
                'purchase_tax_'.$roleSuffix,
                $accounts['inventory'],
                $amount,
                'purchase_payment_tax_'.$roleSuffix,
                $accounts[$paymentRole],
                $amount,
                $description,
                'purchase_invoice_'.$roleSuffix,
                'purchase_payment_invoice_'.$roleSuffix,
                'inventory',
                $paymentRole,
            );
        }
    }

    private function purchasePaymentSourceId(AccountingEntry $entry, ?string $provider): string
    {
        $sources = (array) data_get($entry->expense_breakdown, 'payment_sources', []);
        $source = $provider === null
            ? ($sources['freee'] ?? $sources['money_forward'] ?? '')
            : ($sources[$provider] ?? '');

        return trim((string) $source);
    }

    private function freeeTransactionNumber(string $id): string
    {
        $limits = (array) config('furimadeck.accounting.csv_limits.freee', []);
        $maxLength = (int) ($limits['max_transaction_number_length'] ?? 0);
        $width = (int) ($limits['transaction_number_width'] ?? 0);
        $namespace = (string) ($limits['transaction_number_namespace'] ?? '');
        $availableIdDigits = $width - strlen($namespace);

        if ($width < 1
            || $width > $maxLength
            || $namespace === ''
            || preg_match('/^[0-9]+$/D', $namespace) !== 1
            || $availableIdDigits < 1
            || ! ctype_digit($id)
            || $id === '0'
            || strlen($id) > $availableIdDigits) {
            throw new RuntimeException('freee CSVの伝票番号を安全に作成できないため、仕訳CSVを出力していません。');
        }

        return $namespace.str_pad($id, $availableIdDigits, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed>|null $special */
    private function purchaseDescription(AccountingEntry $entry, string $label, ?array $special, ?string $capMemo): string
    {
        $normalizer = app(FurimaDeckPurchaseSpecialCaseService::class);
        $parts = ['FurimaDeck仕入', '管理ID:'.$normalizer->singleLine((string) $entry->management_id), $normalizer->singleLine((string) $entry->title)];
        if ($special !== null) {
            $fields = $normalizer->validatedFields($entry);
            if ($fields === null) {
                throw new RuntimeException('古物商等特例を確認できないため仕訳CSVを出力していません。');
            }
            $parts = [...$parts, '古物商等特例', '許可番号:'.$fields['license_number'], '仕入先:'.$fields['counterparty_name'], '住所:'.$fields['counterparty_address']];
        }
        $parts[] = $label;
        $description = implode(' / ', array_filter($parts, static fn (string $part): bool => $part !== ''));
        $description = app(FurimaDeckInvoiceTransitionCapService::class)->appendAccountingMemo($description, $capMemo);
        if (mb_strlen($description, 'UTF-8') > 200) {
            throw new RuntimeException('仕入摘要が200文字を超えるため仕訳CSVを出力できません。商品名・特例情報を確認してください。');
        }

        return $description;
    }

    /** @param array<int, array<string, string|int>> $rows */
    public function freeeHeaders(): array
    {
        return ['[表題行]', '日付', '伝票番号', '借方勘定科目', '借方金額', '借方税区分', '貸方勘定科目', '貸方金額', '貸方税区分', '摘要'];
    }

    /** @param array<int, array<string, string|int>> $rows */
    public function freeeRow(array $row, array $taxNames): array
    {
        $limits = (array) config('furimadeck.accounting.csv_limits.freee', []);
        $maxAccountNameCharacters = (int) ($limits['max_account_name_characters'] ?? 0);
        $maxDescriptionCharacters = (int) ($limits['max_description_characters'] ?? 0);
        $date = (string) ($row['date'] ?? '');
        $dateParts = [];
        $dateIsValid = preg_match('/^([0-9]{4})\/([0-9]{2})\/([0-9]{2})$/D', $date, $dateParts) === 1
            && checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]);
        if (! $dateIsValid) {
            throw new RuntimeException('freee CSVの日付が正しいYYYY/MM/DD形式ではありません。仕訳CSVは出力していません。');
        }

        $transactionNumber = $this->freeeTransactionNumber((string) ($row['number'] ?? ''));

        $debitAmount = filter_var($row['debit_amount'] ?? null, FILTER_VALIDATE_INT);
        $creditAmount = filter_var($row['credit_amount'] ?? null, FILTER_VALIDATE_INT);
        if ($debitAmount === false || $creditAmount === false || $debitAmount < 1 || $creditAmount < 1) {
            throw new RuntimeException('freee CSVの仕訳金額は1円以上の整数が必要です。仕訳CSVは出力していません。');
        }
        if ($debitAmount !== $creditAmount) {
            throw new RuntimeException('freee CSVの借方・貸方金額が一致しません。仕訳CSVは出力していません。');
        }

        foreach (['debit_account', 'credit_account'] as $accountField) {
            $accountName = trim((string) ($row[$accountField] ?? ''));
            if ($accountName === '' || $maxAccountNameCharacters < 1 || mb_strlen($accountName) > $maxAccountNameCharacters) {
                throw new RuntimeException('freee CSVの勘定科目名が未設定または文字数上限を超えています。仕訳CSVは出力していません。');
            }
        }

        $description = (string) ($row['description'] ?? '');
        if ($maxDescriptionCharacters < 1 || mb_strlen($description) > $maxDescriptionCharacters) {
            throw new RuntimeException('freee CSVの摘要が文字数上限を超えています。仕訳CSVは出力していません。');
        }

        $debitTax = trim((string) ($taxNames[$row['debit_role']] ?? ''));
        $creditTax = trim((string) ($taxNames[$row['credit_role']] ?? ''));
        if ($debitTax === '' || $creditTax === '') {
            throw new RuntimeException('freee CSVの税区分が未設定です。仕訳CSVは出力していません。');
        }

        return ['[明細行]', $date, $transactionNumber, $row['debit_account'], $debitAmount, $debitTax, $row['credit_account'], $creditAmount, $creditTax, $description];
    }

    public function moneyForwardHeaders(): array
    {
        return ['取引No', '取引日', '借方勘定科目', '借方補助科目', '借方部門', '借方取引先', '借方税区分', '借方インボイス', '借方金額(円)', '借方税額', '貸方勘定科目', '貸方補助科目', '貸方部門', '貸方取引先', '貸方税区分', '貸方インボイス', '貸方金額(円)', '貸方税額', '摘要', '仕訳メモ', 'タグ', 'MF仕訳タイプ', '決算整理仕訳', '作成日時', '作成者', '最終更新日時', '最終更新者'];
    }

    /** @param array<string, string|int> $row */
    public function moneyForwardRow(
        array $row,
        array $taxNames,
        array $subAccounts,
        array $invoiceKinds,
        string $businessStatus,
        bool $includeJournalMemo = true,
    ): array {
        $limits = (array) config('furimadeck.accounting.csv_limits.money_forward', []);
        $maxTransactionNumberLength = (int) ($limits['max_transaction_number_length'] ?? 0);
        $maxAccountNameCharacters = (int) ($limits['max_account_name_characters'] ?? 0);
        $maxSubAccountNameCharacters = (int) ($limits['max_sub_account_name_characters'] ?? 0);
        $maxDescriptionCharacters = (int) ($limits['max_description_characters'] ?? 0);
        $number = (string) ($row['number'] ?? '');
        if ($maxTransactionNumberLength < 1
            || preg_match('/^[0-9]+$/D', $number) !== 1
            || strlen($number) > $maxTransactionNumberLength) {
            throw new RuntimeException('Money Forwardの取引Noは数字'.$maxTransactionNumberLength.'桁以内が必要です。');
        }
        $date = (string) ($row['date'] ?? '');
        $dateParts = [];
        $dateIsValid = preg_match('/^([0-9]{4})\/([0-9]{2})\/([0-9]{2})$/D', $date, $dateParts) === 1
            && checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1]);
        if (! $dateIsValid) {
            throw new RuntimeException('Money Forward CSVの日付は正しいYYYY/MM/DD形式で指定してください。仕訳CSVは出力していません。');
        }
        $debitAmount = filter_var($row['debit_amount'] ?? null, FILTER_VALIDATE_INT);
        $creditAmount = filter_var($row['credit_amount'] ?? null, FILTER_VALIDATE_INT);
        if ($debitAmount === false || $creditAmount === false || $debitAmount < 1 || $creditAmount < 1) {
            throw new RuntimeException('Money Forward CSVの借方・貸方金額は1円以上の整数が必要です。仕訳CSVは出力していません。');
        }
        if ($debitAmount !== $creditAmount) {
            throw new RuntimeException('Money Forward CSVの借方・貸方金額が一致しません。仕訳CSVは出力していません。');
        }
        if ($maxDescriptionCharacters < 1 || mb_strlen((string) ($row['description'] ?? '')) > $maxDescriptionCharacters) {
            throw new RuntimeException('Money Forward CSVの摘要は'.$maxDescriptionCharacters.'字以内にしてください。仕訳CSVは出力していません。');
        }
        if (! in_array($businessStatus, [
            FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
            FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT,
        ], true)) {
            throw new RuntimeException('取引日のMoney Forward課税・免税状態を確認できないため、仕訳CSVを出力していません。');
        }

        foreach (['debit_account', 'credit_account'] as $accountField) {
            $accountName = trim((string) ($row[$accountField] ?? ''));
            if ($accountName === '' || $maxAccountNameCharacters < 1 || mb_strlen($accountName) > $maxAccountNameCharacters) {
                throw new RuntimeException('Money Forward CSVの勘定科目名は'.$maxAccountNameCharacters.'字以内で設定してください。CSVは出力していません。');
            }
        }
        foreach ([(string) ($row['debit_role'] ?? ''), (string) ($row['credit_role'] ?? '')] as $accountRole) {
            $subAccount = trim((string) ($subAccounts[$accountRole] ?? ''));
            if ($subAccount !== '' && ($maxSubAccountNameCharacters < 1 || mb_strlen($subAccount) > $maxSubAccountNameCharacters)) {
                throw new RuntimeException('Money Forward CSVの補助科目名は'.$maxSubAccountNameCharacters.'字以内で設定してください。CSVは出力していません。');
            }
        }

        $debitTax = '';
        $creditTax = '';
        $debitInvoice = '';
        $creditInvoice = '';
        if ($businessStatus === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE) {
            $debitTax = trim((string) ($taxNames[$row['debit_role']] ?? ''));
            $creditTax = trim((string) ($taxNames[$row['credit_role']] ?? ''));
            if ($debitTax === '' || $creditTax === '') {
                throw new RuntimeException('Money Forward CSVの税区分が未設定です。仕訳CSVは出力していません。');
            }
            $invoiceKindService = app(FurimaDeckMoneyForwardInvoiceKindService::class);
            $debitInvoiceRole = (string) ($row['debit_invoice_role'] ?? $row['debit_role']);
            $creditInvoiceRole = (string) ($row['credit_invoice_role'] ?? $row['credit_role']);
            $debitInvoice = $invoiceKindService->csvLabel((string) ($invoiceKinds[$debitInvoiceRole] ?? ''), (string) $row['date']);
            $creditInvoice = $invoiceKindService->csvLabel((string) ($invoiceKinds[$creditInvoiceRole] ?? ''), (string) $row['date']);
        }

        $debitAccountRole = (string) ($row['debit_account_role'] ?? $row['debit_role']);
        $creditAccountRole = (string) ($row['credit_account_role'] ?? $row['credit_role']);

        return [$row['number'], $row['date'], $row['debit_account'], $subAccounts[$debitAccountRole] ?? '', '', '', $debitTax, $debitInvoice, $debitAmount, '', $row['credit_account'], $subAccounts[$creditAccountRole] ?? '', '', '', $creditTax, $creditInvoice, $creditAmount, '', $row['description'], $includeJournalMemo ? 'FurimaDeck要確認' : '', '', 'インポート', '', '', '', '', ''];
    }

    /** @param array<int, mixed> $row @return array<int, string> */
    public function sanitizeRowForProvider(array $row, string $provider): array
    {
        if (! in_array($provider, ['freee', 'money_forward'], true)) {
            throw new RuntimeException('会計CSVの形式を確認できません。CSVは出力していません。');
        }

        $safeRow = array_map(static function (mixed $value): string {
            if ($value !== null && ! is_scalar($value)) {
                throw new RuntimeException('会計CSVの項目の形式が正しくないため、仕訳CSVは出力していません。');
            }

            $value = (string) ($value ?? '');
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw new RuntimeException('会計CSVにUTF-8として無効な値が含まれるため、仕訳CSVは出力していません。');
            }

            return preg_match('/^[\t\r\n\s]*[=+\-@＝＋－＠]/u', $value) === 1 ? "'".$value : $value;
        }, $row);
        $limits = (array) config('furimadeck.accounting.csv_limits.'.$provider, []);
        $fieldRules = $provider === 'freee'
            ? [
                ['index' => 3, 'limit' => 'max_account_name_characters', 'label' => '借方勘定科目名'],
                ['index' => 6, 'limit' => 'max_account_name_characters', 'label' => '貸方勘定科目名'],
                ['index' => 9, 'limit' => 'max_description_characters', 'label' => '摘要'],
            ]
            : [
                ['index' => 2, 'limit' => 'max_account_name_characters', 'label' => '借方勘定科目名'],
                ['index' => 10, 'limit' => 'max_account_name_characters', 'label' => '貸方勘定科目名'],
                ['index' => 3, 'limit' => 'max_sub_account_name_characters', 'label' => '借方補助科目名'],
                ['index' => 11, 'limit' => 'max_sub_account_name_characters', 'label' => '貸方補助科目名'],
                ['index' => 18, 'limit' => 'max_description_characters', 'label' => '摘要'],
            ];

        foreach ($fieldRules as $rule) {
            $maximum = (int) ($limits[$rule['limit']] ?? 0);
            $value = (string) ($safeRow[$rule['index']] ?? '');
            if ($maximum < 1 || mb_strlen($value, 'UTF-8') > $maximum) {
                $providerLabel = $provider === 'freee' ? 'freee' : 'Money Forward';
                throw new RuntimeException($providerLabel.' CSVの'.$rule['label'].'が数式対策後に文字数上限を超えます。設定または摘要を確認してください。CSVは出力していません。');
            }
        }

        return $safeRow;
    }

    /** @param array<int, array<string, string|int> $rows */
    private function addRow(array &$rows, string $date, string $number, string $debitRole, string $debitAccount, int $debitAmount, string $creditRole, string $creditAccount, int $creditAmount, string $description, ?string $debitInvoiceRole = null, ?string $creditInvoiceRole = null, ?string $debitAccountRole = null, ?string $creditAccountRole = null): void
    {
        $row = [
            'date' => $date,
            'number' => $number,
            'debit_role' => $debitRole,
            'debit_account' => $debitAccount,
            'debit_amount' => $debitAmount,
            'credit_role' => $creditRole,
            'credit_account' => $creditAccount,
            'credit_amount' => $creditAmount,
            'description' => $description,
        ];
        if ($debitInvoiceRole !== null) {
            $row['debit_invoice_role'] = $debitInvoiceRole;
        }
        if ($creditInvoiceRole !== null) {
            $row['credit_invoice_role'] = $creditInvoiceRole;
        }
        if ($debitAccountRole !== null) {
            $row['debit_account_role'] = $debitAccountRole;
        }
        if ($creditAccountRole !== null) {
            $row['credit_account_role'] = $creditAccountRole;
        }
        $rows[] = $row;
    }
}
