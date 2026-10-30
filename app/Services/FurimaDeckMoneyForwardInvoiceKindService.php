<?php

namespace App\Services;

use App\Models\Product;
use RuntimeException;

class FurimaDeckMoneyForwardInvoiceKindService
{
    public const NOT_TARGET = 'INVOICE_KIND_NOT_TARGET';

    public const QUALIFIED = 'INVOICE_KIND_QUALIFIED';

    public const UNQUALIFIED_80 = 'INVOICE_KIND_UNQUALIFIED_80';

    public const UNQUALIFIED_70 = 'INVOICE_KIND_UNQUALIFIED_70';

    public const UNQUALIFIED_50 = 'INVOICE_KIND_UNQUALIFIED_50';

    public const UNQUALIFIED_30 = 'INVOICE_KIND_UNQUALIFIED_30';

    public const UNQUALIFIED = 'INVOICE_KIND_UNQUALIFIED';

    public const OMIT = 'INVOICE_KIND_OMIT';

    public const CSV_SUPPORTED_KINDS = [
        self::QUALIFIED,
        self::UNQUALIFIED_80,
        self::UNQUALIFIED_70,
        self::UNQUALIFIED_50,
        self::UNQUALIFIED_30,
        self::UNQUALIFIED,
    ];

    public const BUSINESS_STATUS_UNCONFIRMED = 'BUSINESS_TAX_STATUS_UNCONFIRMED';

    public const BUSINESS_STATUS_TAXABLE = 'BUSINESS_TAX_STATUS_TAXABLE';

    public const BUSINESS_STATUS_EXEMPT = 'BUSINESS_TAX_STATUS_EXEMPT';

    public const BUSINESS_SETTING_LEGACY_VALUES = [
        self::NOT_TARGET,
        self::QUALIFIED,
        self::UNQUALIFIED_80,
        self::UNQUALIFIED_70,
        self::UNQUALIFIED_50,
        self::UNQUALIFIED_30,
        self::UNQUALIFIED,
        self::OMIT,
    ];

    public const BUSINESS_SETTING_VALUES = [
        self::BUSINESS_STATUS_TAXABLE,
        self::BUSINESS_STATUS_EXEMPT,
        self::BUSINESS_STATUS_UNCONFIRMED,
    ];

    public const BUSINESS_SETTING_LABELS = [
        self::BUSINESS_STATUS_TAXABLE => '課税事業者を確認済み（その期間のCSV出力候補）',
        self::BUSINESS_STATUS_EXEMPT => '免税事業者を確認済み（税区分・インボイス欄を空欄でCSV出力）',
        self::BUSINESS_STATUS_UNCONFIRMED => '課税・免税の状態未確認（Money Forward CSV停止）',
    ];

    public const LABELS = [
        self::NOT_TARGET => '対象外（APIのみ）',
        self::QUALIFIED => '適格',
        self::UNQUALIFIED_80 => '80％控除',
        self::UNQUALIFIED_70 => '70％控除',
        self::UNQUALIFIED_50 => '50％控除',
        self::UNQUALIFIED_30 => '30％控除',
        self::UNQUALIFIED => '控除なし',
        self::OMIT => '区分を送信しない（APIのみ）',
    ];

    public function isValid(string $kind): bool
    {
        return array_key_exists($kind, self::LABELS);
    }

    /** @return array<string, string> */
    public function csvLabels(): array
    {
        return array_intersect_key(self::LABELS, array_flip(self::CSV_SUPPORTED_KINDS));
    }

    public function csvLabel(string $kind, string $date): string
    {
        $dateValue = \DateTimeImmutable::createFromFormat('!Y/m/d', $date);
        if ($dateValue === false || $dateValue->format('Y/m/d') !== $date) {
            throw new RuntimeException('Money Forward CSVの日付形式が正しくありません。CSVは出力していません。');
        }

        $label = match ($kind) {
            self::QUALIFIED => self::LABELS[self::QUALIFIED],
            self::UNQUALIFIED_80 => self::LABELS[self::UNQUALIFIED_80],
            self::UNQUALIFIED_70 => self::LABELS[self::UNQUALIFIED_70],
            self::UNQUALIFIED_50 => self::LABELS[self::UNQUALIFIED_50],
            self::UNQUALIFIED_30 => self::LABELS[self::UNQUALIFIED_30],
            self::UNQUALIFIED => self::LABELS[self::UNQUALIFIED],
            default => null,
        };

        if ($label === null || ! $this->isCsvLabelAvailable($kind, $dateValue)) {
            throw new RuntimeException('Money Forward CSVではこのインボイス区分を正確に表せません。空欄は「適格」として扱われるため、区分を確認するまでCSVを出力していません。');
        }

        return $label;
    }

    public function forPurchaseEvidence(string $taxCategory, string $invoiceIssuer, string $date, bool $antiqueDealerException = false): ?string
    {
        $transactionDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($transactionDate === false || $transactionDate->format('Y-m-d') !== $date) {
            throw new RuntimeException('仕入証憑の日付が正しくありません。仕訳は送信していません。');
        }
        if (! in_array($taxCategory, Product::PURCHASE_TAX_CATEGORIES, true)
            || ! in_array($invoiceIssuer, Product::PURCHASE_INVOICE_ISSUERS, true)
            || $taxCategory === 'unknown' || $invoiceIssuer === 'unknown') {
            throw new RuntimeException('仕入証憑の税区分または発行者区分が未確定です。仕訳は送信していません。');
        }
        if ($antiqueDealerException) {
            if ($invoiceIssuer !== 'unqualified' || ! in_array($taxCategory, ['standard_10', 'reduced_8'], true)) {
                throw new RuntimeException('古物商等特例は、課税対象かつ適格請求書発行事業者以外の仕入に限って摘要へ記録できます。証憑を確認するまで仕訳は送信していません。');
            }

            return null;
        }

        if (in_array($taxCategory, ['exempt', 'non_taxable', 'out_of_scope'], true)) {
            return self::NOT_TARGET;
        }
        if ($invoiceIssuer === 'not_applicable') {
            throw new RuntimeException('課税仕入の証憑に発行者区分「対象外」が指定されています。証憑を確認するまで仕訳は送信していません。');
        }
        if ($invoiceIssuer === 'qualified') {
            return self::QUALIFIED;
        }

        $periods = (array) config('furimadeck.accounting.invoice_transition', []);

        return match (true) {
            $date < (string) ($periods['invoice_system_start'] ?? '') => null,
            $date < (string) ($periods['unqualified_80_end'] ?? '') => self::UNQUALIFIED_80,
            $date < (string) ($periods['unqualified_70_end'] ?? '') => self::UNQUALIFIED_70,
            $date < (string) ($periods['unqualified_50_end'] ?? '') => self::UNQUALIFIED_50,
            $date < (string) ($periods['unqualified_30_end'] ?? '') => self::UNQUALIFIED_30,
            default => self::UNQUALIFIED,
        };
    }

    private function isCsvLabelAvailable(string $kind, \DateTimeImmutable $date): bool
    {
        $date = $date->format('Y-m-d');

        $periods = (array) config('furimadeck.accounting.invoice_transition', []);

        return match ($kind) {
            self::QUALIFIED => true,
            self::UNQUALIFIED_80 => $date >= (string) ($periods['invoice_system_start'] ?? '') && $date < (string) ($periods['unqualified_80_end'] ?? ''),
            self::UNQUALIFIED_70 => $date >= (string) ($periods['unqualified_80_end'] ?? '') && $date < (string) ($periods['unqualified_70_end'] ?? ''),
            self::UNQUALIFIED_50 => $date >= (string) ($periods['unqualified_70_end'] ?? '') && $date < (string) ($periods['unqualified_50_end'] ?? ''),
            self::UNQUALIFIED_30 => $date >= (string) ($periods['unqualified_50_end'] ?? '') && $date < (string) ($periods['unqualified_30_end'] ?? ''),
            self::UNQUALIFIED => $date >= (string) ($periods['unqualified_30_end'] ?? ''),
            default => false,
        };
    }
}
