<?php

namespace App\Services;

use App\Models\AccountingEntry;
use RuntimeException;

class FurimaDeckPurchaseSpecialCaseService
{
    public const SPECIFIED_METAL_EXCLUSION_EFFECTIVE_FROM = '2026-09-01';

    /** @return list<string> */
    public function requiredConfirmations(?string $purchaseDate): array
    {
        $confirmations = ['dealer_license', 'qualifying_used_goods', 'resale_inventory', 'supplier_unqualified'];
        $date = $purchaseDate === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $purchaseDate);

        if ($date !== false && $date->format('Y-m-d') === $purchaseDate
            && $purchaseDate >= self::SPECIFIED_METAL_EXCLUSION_EFFECTIVE_FROM) {
            $confirmations[] = 'not_specific_metal_scrap';
        }

        return $confirmations;
    }

    /** @param array<string, mixed> $data */
    public function assertConfirmations(array $data, ?string $purchaseDate): void
    {
        foreach ($this->requiredConfirmations($purchaseDate) as $confirmation) {
            if (($data['confirmations'][$confirmation] ?? false) !== true) {
                throw new RuntimeException('古物商等の特例の確認が不足しています。仕入日と対象商品の条件を確認するまで仕訳を作成・送信できません。');
            }
        }
    }

    /** @return array{license_number: string, counterparty_name: string, counterparty_address: string}|null */
    public function validatedFields(AccountingEntry $entry): ?array
    {
        $data = $entry->purchase_special_case_data;
        if ($data === null) {
            return null;
        }

        if ($entry->entry_type !== AccountingEntry::TYPE_PURCHASE) {
            throw new RuntimeException('仕入明細以外に古物商等特例を適用できません。');
        }
        if (($data['scheme'] ?? null) !== 'antique_dealer') {
            throw new RuntimeException('古物商等特例の種類を確認できないため、仕訳を作成できません。');
        }
        $this->assertConfirmations($data, $entry->transaction_date?->toDateString());

        $evidence = (array) data_get($entry->expense_breakdown, 'evidence.purchase_unit_cost', []);
        if (($evidence['invoice_issuer'] ?? null) !== 'unqualified'
            || ! in_array($evidence['tax_category'] ?? null, ['standard_10', 'reduced_8'], true)
            || trim((string) ($data['confirmed_at'] ?? '')) === '') {
            throw new RuntimeException('古物商等特例と商品代の証憑区分が一致しないため、仕訳を作成できません。');
        }

        $fields = [
            'license_number' => $this->singleLine((string) ($data['license_number'] ?? '')),
            'counterparty_name' => $this->singleLine((string) ($data['counterparty_name'] ?? '')),
            'counterparty_address' => $this->singleLine((string) ($data['counterparty_address'] ?? '')),
        ];
        if (in_array('', $fields, true)) {
            throw new RuntimeException('古物商等特例の許可番号・仕入先名・住所が不足しているため、仕訳を作成できません。');
        }

        return $fields;
    }

    public function singleLine(string $value): string
    {
        $normalized = preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', trim($value));
        if ($normalized === null) {
            throw new RuntimeException('摘要情報の形式を確認できないため、仕訳を作成できません。');
        }

        return trim($normalized);
    }
}
