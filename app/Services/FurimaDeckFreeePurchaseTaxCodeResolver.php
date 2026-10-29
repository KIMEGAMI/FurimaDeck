<?php

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

class FurimaDeckFreeePurchaseTaxCodeResolver
{
    private const PURCHASE_TAX_ROOTS = [
        'purchase_with_tax',
        'purchase_with_no_tax',
        'purchase_with_common',
    ];

    public function resolve(
        array $taxCodes,
        string $inventoryDefaultTaxCode,
        string $taxCategory,
        string $invoiceIssuer,
        string $transactionDate,
        bool $antiqueDealerException = false,
    ): string {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $transactionDate);
        if ($date === false || $date->format('Y-m-d') !== $transactionDate) {
            throw new RuntimeException('仕入日の税区分を確認できません。仕訳は送信していません。');
        }
        if (! in_array($taxCategory, ['standard_10', 'reduced_8'], true)
            || ! in_array($invoiceIssuer, ['qualified', 'unqualified'], true)) {
            throw new RuntimeException('freeeの仕入税区分を証憑から一意に解決できません。仕訳は送信していません。');
        }
        if ($antiqueDealerException && $invoiceIssuer !== 'unqualified') {
            throw new RuntimeException('古物商等の特例と証憑の発行者区分が一致しません。仕訳は送信していません。');
        }

        $codes = collect($taxCodes)->filter(static fn (array $tax): bool => ($tax['available'] ?? true) !== false);
        $inventoryTax = $codes->first(fn (array $tax): bool => (string) ($tax['code'] ?? '') === $inventoryDefaultTaxCode);
        $inventoryTaxName = (string) ($inventoryTax['name'] ?? '');
        $root = collect(self::PURCHASE_TAX_ROOTS)->first(static fn (string $candidate): bool => str_starts_with($inventoryTaxName, $candidate.'_'));
        if ($root === null) {
            throw new RuntimeException('freeeの在庫科目の標準税区分から仕入税区分を判定できません。仕訳は送信していません。');
        }

        $rateSuffix = $taxCategory === 'standard_10' ? '_10' : '_reduced_8';
        $taxName = $root.$rateSuffix;
        if ($invoiceIssuer === 'unqualified' && ! $antiqueDealerException) {
            $deductionRate = $this->deductionRateForDate($date);
            if ($deductionRate !== '') {
                $taxName .= '_exempt_'.$deductionRate;
            }
        }

        $matches = $codes->filter(static fn (array $tax): bool => (string) ($tax['name'] ?? '') === $taxName)->values();
        if ($matches->count() !== 1 || ! ctype_digit((string) ($matches->first()['code'] ?? ''))) {
            throw new RuntimeException('freeeに仕入日の証憑区分に一致する有効な税区分がありません。仕訳は送信していません。');
        }

        return (string) $matches->first()['code'];
    }

    private function deductionRateForDate(DateTimeImmutable $date): string
    {
        $value = $date->format('Y-m-d');

        return match (true) {
            $value < '2023-10-01' => '',
            $value < '2026-10-01' => '80',
            $value < '2028-10-01' => '70',
            $value < '2030-10-01' => '50',
            $value < '2031-10-01' => '30',
            default => throw new RuntimeException('freeeの適格請求書経過措置終了後の税区分は自動判定できません。仕訳は送信していません.'),
        };
    }
}
