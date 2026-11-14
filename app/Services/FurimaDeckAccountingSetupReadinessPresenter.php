<?php

namespace App\Services;

class FurimaDeckAccountingSetupReadinessPresenter
{
    private const ROLE_LABELS = [
        'settlement' => '入金先',
        'sales' => '売上',
        'fees' => '販売手数料',
        'shipping' => '送料',
        'other' => 'その他費用',
        'cost' => '仕入原価',
        'inventory' => '商品在庫',
    ];

    /** @param list<string> $missing @return list<string> */
    public function labels(string $provider, array $missing): array
    {
        $labels = [];
        foreach (array_unique($missing) as $item) {
            $labels[] = $this->label($provider, $item);
        }

        return array_values($labels);
    }

    private function label(string $provider, string $item): string
    {
        if ($provider === 'freee') {
            return match (true) {
                $item === 'company_id' => 'freeeの事業所',
                $item === 'account_item_ids.distinct_roles' => '仕訳の役割ごとに異なる勘定科目',
                $item === 'tax_codes.distinct_roles' => '仕訳の役割ごとの税区分',
                str_starts_with($item, 'account_item_ids.') => $this->roleLabel($item, 'account_item_ids.', '勘定科目'),
                str_starts_with($item, 'tax_codes.') => $this->roleLabel($item, 'tax_codes.', '税区分'),
                default => 'freeeの仕訳設定を再確認',
            };
        }

        return match (true) {
            $item === 'account_statement_types_check' => '勘定科目が貸借のどちら側で使われるか確認',
            $item === 'business_tax_periods' => '取引日を含む課税・免税の対象期間',
            $item === 'business_tax_status_confirmation' => '課税・免税状態の確認（未確認のため送信停止）',
            str_starts_with($item, 'account_ids.') => $this->roleLabel($item, 'account_ids.', '勘定科目'),
            str_starts_with($item, 'sub_account_ids.') => $this->roleLabel($item, 'sub_account_ids.', '補助科目の有無'),
            str_starts_with($item, 'tax_ids.') => $this->roleLabel($item, 'tax_ids.', '税区分'),
            str_starts_with($item, 'invoice_kinds.') => $this->roleLabel($item, 'invoice_kinds.', 'インボイス区分'),
            default => 'Money Forwardの仕訳設定を再確認',
        };
    }

    private function roleLabel(string $item, string $prefix, string $setting): string
    {
        $role = substr($item, strlen($prefix));
        $roleLabel = self::ROLE_LABELS[$role] ?? '仕訳';

        return $roleLabel.'の'.$setting;
    }
}
