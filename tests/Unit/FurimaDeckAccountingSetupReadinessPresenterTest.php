<?php

namespace Tests\Unit;

use App\Services\FurimaDeckAccountingSetupReadinessPresenter;
use Tests\TestCase;

class FurimaDeckAccountingSetupReadinessPresenterTest extends TestCase
{
    public function test_freee_configuration_gaps_are_named_in_plain_japanese(): void
    {
        $labels = app(FurimaDeckAccountingSetupReadinessPresenter::class)->labels('freee', [
            'company_id',
            'account_item_ids.settlement',
            'tax_codes.sales',
            'account_item_ids.distinct_roles',
            'company_id',
        ]);

        $this->assertSame([
            'freeeの事業所',
            '入金先の勘定科目',
            '売上の税区分',
            '仕訳の役割ごとに異なる勘定科目',
        ], $labels);
    }

    public function test_money_forward_configuration_gaps_explain_tax_and_invoice_fields(): void
    {
        $labels = app(FurimaDeckAccountingSetupReadinessPresenter::class)->labels('money_forward', [
            'account_statement_types_check',
            'business_tax_periods',
            'invoice_kinds.fees',
            'tax_ids.cost',
            'business_tax_status_confirmation',
        ]);

        $this->assertSame([
            '勘定科目が貸借のどちら側で使われるか確認',
            '取引日を含む課税・免税の対象期間',
            '販売手数料のインボイス区分',
            '仕入原価の税区分',
            '課税・免税状態の確認（未確認のため送信停止）',
        ], $labels);
    }
}
