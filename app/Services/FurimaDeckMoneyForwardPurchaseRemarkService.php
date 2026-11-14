<?php

namespace App\Services;

use App\Models\AccountingEntry;
use RuntimeException;

class FurimaDeckMoneyForwardPurchaseRemarkService
{
    private const MAX_REMARK_CHARACTERS = 200;

    public function build(AccountingEntry $entry, bool $includeSpecialCase = true): string
    {
        if ($entry->entry_type !== AccountingEntry::TYPE_PURCHASE) {
            throw new RuntimeException('仕入明細以外の摘要は作成できません。');
        }

        $specialCase = $entry->purchase_special_case_data;
        $specialFields = $includeSpecialCase
            ? app(FurimaDeckPurchaseSpecialCaseService::class)->validatedFields($entry)
            : null;
        $normalizer = app(FurimaDeckPurchaseSpecialCaseService::class);
        $managementId = $normalizer->singleLine((string) $entry->management_id);
        $title = $normalizer->singleLine((string) $entry->title);
        $parts = array_filter([
            'FurimaDeck仕入',
            $managementId !== '' ? '管理ID:'.$managementId : null,
            $title,
        ]);

        if ($specialCase !== null && $includeSpecialCase && $specialFields !== null) {
            $parts = [...$parts, '古物商等特例', '許可番号:'.$specialFields['license_number'], '仕入先:'.$specialFields['counterparty_name'], '住所:'.$specialFields['counterparty_address']];
        }

        $remark = implode(' / ', $parts);
        if (mb_strlen($remark, 'UTF-8') > self::MAX_REMARK_CHARACTERS) {
            throw new RuntimeException('Money Forward摘要が200文字を超えるため送信できません。商品名・管理ID・特例情報を確認してください。');
        }

        return $remark;
    }
}
