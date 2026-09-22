<?php

namespace App\Services;

use Illuminate\Support\Collection;
use RuntimeException;

class FurimaDeckAccountingVendorCsvService
{
    /** @return array<string, string> */
    public function accounts(): array
    {
        $accounts = config('furimadeck.accounting.export_accounts', []);
        $required = ['settlement', 'sales', 'fees', 'cost', 'inventory'];
        $missing = collect($required)->filter(fn (string $key): bool => trim((string) ($accounts[$key] ?? '')) === '')->values();
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('会計CSVの勘定科目設定が不足しています: '.$missing->implode(', '));
        }

        return collect($required)->mapWithKeys(fn (string $key): array => [$key => trim((string) $accounts[$key])])->all();
    }

    /** @return array<int, array<string, string|int>> */
    public function journalRows(Collection $entries): array
    {
        $accounts = $this->accounts();
        $rows = [];

        foreach ($entries as $entry) {
            $date = $entry->transaction_date?->format('Y/m/d') ?? '';
            $number = (string) $entry->sale_id;
            $title = (string) $entry->title;
            $this->addRow($rows, $date, $number, $accounts['settlement'], (int) $entry->sale_amount, $accounts['sales'], (int) $entry->sale_amount, $title.' 売上');

            $fees = (int) $entry->selling_fee + (int) $entry->shipping_cost + (int) $entry->other_cost;
            if ($fees > 0) {
                $this->addRow($rows, $date, $number, $accounts['fees'], $fees, $accounts['settlement'], $fees, $title.' 手数料等');
            }
            $cost = (int) $entry->purchase_cost;
            if ($cost > 0) {
                $this->addRow($rows, $date, $number, $accounts['cost'], $cost, $accounts['inventory'], $cost, $title.' 原価');
            }
        }

        return $rows;
    }

    /** @param array<int, array<string, string|int>> $rows */
    public function freeeHeaders(): array
    {
        return ['行区分', '日付', '伝票番号', '借方勘定科目', '借方金額', '貸方勘定科目', '貸方金額', '摘要'];
    }

    /** @param array<int, array<string, string|int>> $rows */
    public function freeeRow(array $row): array
    {
        return ['[明細行]', $row['date'], $row['number'], $row['debit_account'], $row['debit_amount'], $row['credit_account'], $row['credit_amount'], $row['description']];
    }

    public function moneyForwardHeaders(): array
    {
        return ['取引No', '取引日', '借方勘定科目', '借方補助科目', '借方部門', '借方取引先', '借方税区分', '借方インボイス', '借方金額(円)', '借方税額', '貸方勘定科目', '貸方補助科目', '貸方部門', '貸方取引先', '貸方税区分', '貸方インボイス', '貸方金額(円)', '貸方税額', '摘要', '仕訳メモ', 'タグ', 'MF仕訳タイプ'];
    }

    /** @param array<string, string|int> $row */
    public function moneyForwardRow(array $row): array
    {
        return [$row['number'], str_replace('/', '/', $row['date']), $row['debit_account'], '', '', '', '', '', $row['debit_amount'], '', $row['credit_account'], '', '', '', '', '', $row['credit_amount'], '', $row['description'], 'FurimaDeck要確認', '', 'インポート'];
    }

    /** @param array<int, array<string, string|int> $rows */
    private function addRow(array &$rows, string $date, string $number, string $debitAccount, int $debitAmount, string $creditAccount, int $creditAmount, string $description): void
    {
        $rows[] = compact('date', 'number', 'debitAccount', 'debitAmount', 'creditAccount', 'creditAmount', 'description');
        $rows[array_key_last($rows)] = [
            'date' => $date,
            'number' => $number,
            'debit_account' => $debitAccount,
            'debit_amount' => $debitAmount,
            'credit_account' => $creditAccount,
            'credit_amount' => $creditAmount,
            'description' => $description,
        ];
    }
}
