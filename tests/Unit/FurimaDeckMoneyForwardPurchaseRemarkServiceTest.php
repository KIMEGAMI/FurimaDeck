<?php

namespace Tests\Unit;

use App\Models\AccountingEntry;
use App\Services\FurimaDeckMoneyForwardPurchaseRemarkService;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckMoneyForwardPurchaseRemarkServiceTest extends TestCase
{
    public function test_antique_dealer_remark_includes_the_required_counterparty_fields(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => '2026-10-15',
            'management_id' => 'SKU-1',
            'title' => '古着ジャケット',
            'expense_breakdown' => [
                'evidence' => [
                    'purchase_unit_cost' => ['tax_category' => 'standard_10', 'invoice_issuer' => 'unqualified'],
                ],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => "東京都公安委員会\n第123456789012号",
                'counterparty_name' => "山田\r\n太郎",
                'counterparty_address' => "東京都千代田区1-2-3\t2F",
                'confirmed_at' => '2026-10-15T00:00:00+09:00',
                'confirmations' => array_fill_keys([
                    'dealer_license',
                    'qualifying_used_goods',
                    'resale_inventory',
                    'supplier_unqualified',
                    'not_specific_metal_scrap',
                ], true),
            ],
        ]);

        $remark = app(FurimaDeckMoneyForwardPurchaseRemarkService::class)->build($entry);

        $this->assertStringContainsString('古物商等特例', $remark);
        $this->assertStringContainsString('山田 太郎', $remark);
        $this->assertStringContainsString('東京都千代田区1-2-3 2F', $remark);
        $this->assertDoesNotMatchRegularExpression('/[\r\n\t]/', $remark);
        $this->assertStringContainsString('東京都公安委員会 第123456789012号', $remark);
        $this->assertLessThanOrEqual(200, mb_strlen($remark, 'UTF-8'));
    }

    public function test_antique_dealer_remark_fails_closed_when_required_data_is_missing(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'purchase_special_case_data' => ['scheme' => 'antique_dealer'],
        ]);

        try {
            app(FurimaDeckMoneyForwardPurchaseRemarkService::class)->build($entry);
            $this->fail('特例情報が不足したまま摘要を作成できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('確認が不足', $exception->getMessage());
        }
    }

    public function test_antique_dealer_remark_after_metal_exclusion_date_fails_closed_without_confirmation(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => '2026-09-01',
            'expense_breakdown' => [
                'evidence' => [
                    'purchase_unit_cost' => ['tax_category' => 'standard_10', 'invoice_issuer' => 'unqualified'],
                ],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => '許可番号',
                'counterparty_name' => '仕入先名',
                'counterparty_address' => '住所',
                'confirmed_at' => '2026-09-01T00:00:00+09:00',
                'confirmations' => array_fill_keys([
                    'dealer_license',
                    'qualifying_used_goods',
                    'resale_inventory',
                    'supplier_unqualified',
                ], true),
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('確認が不足');
        app(FurimaDeckMoneyForwardPurchaseRemarkService::class)->build($entry);
    }

    public function test_remark_over_200_characters_is_rejected_without_truncating_private_fields(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => '2026-10-15',
            'management_id' => 'SKU-1',
            'title' => str_repeat('商品', 50),
            'expense_breakdown' => [
                'evidence' => [
                    'purchase_unit_cost' => ['tax_category' => 'standard_10', 'invoice_issuer' => 'unqualified'],
                ],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => '許可番号',
                'counterparty_name' => '仕入先名',
                'counterparty_address' => str_repeat('住所', 80),
                'confirmed_at' => '2026-10-15T00:00:00+09:00',
                'confirmations' => array_fill_keys([
                    'dealer_license',
                    'qualifying_used_goods',
                    'resale_inventory',
                    'supplier_unqualified',
                    'not_specific_metal_scrap',
                ], true),
            ],
        ]);

        try {
            app(FurimaDeckMoneyForwardPurchaseRemarkService::class)->build($entry);
            $this->fail('摘要が上限超過なのに切り詰めず送信可能になりました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('200文字を超える', $exception->getMessage());
        }
    }

    public function test_antique_dealer_remark_rejects_conflicting_evidence_issuer(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'expense_breakdown' => [
                'evidence' => [
                    'purchase_unit_cost' => ['tax_category' => 'standard_10', 'invoice_issuer' => 'qualified'],
                ],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => '許可番号',
                'counterparty_name' => '仕入先名',
                'counterparty_address' => '住所',
                'confirmed_at' => '2026-10-15T00:00:00+09:00',
                'confirmations' => array_fill_keys([
                    'dealer_license',
                    'qualifying_used_goods',
                    'resale_inventory',
                    'supplier_unqualified',
                ], true),
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('証憑区分が一致しない');
        app(FurimaDeckMoneyForwardPurchaseRemarkService::class)->build($entry);
    }

    public function test_remark_rejects_non_purchase_entries(): void
    {
        $entry = new AccountingEntry(['entry_type' => AccountingEntry::TYPE_SALE]);

        $this->expectException(RuntimeException::class);
        app(FurimaDeckMoneyForwardPurchaseRemarkService::class)->build($entry);
    }
}
