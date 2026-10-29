<?php

namespace Tests\Unit;

use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckMoneyForwardInvoiceKindServiceTest extends TestCase
{
    public function test_csv_setup_options_exclude_api_only_invoice_kinds(): void
    {
        $service = new FurimaDeckMoneyForwardInvoiceKindService;

        $this->assertSame(FurimaDeckMoneyForwardInvoiceKindService::CSV_SUPPORTED_KINDS, array_keys($service->csvLabels()));
        $this->assertArrayNotHasKey(FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET, $service->csvLabels());
        $this->assertArrayNotHasKey(FurimaDeckMoneyForwardInvoiceKindService::OMIT, $service->csvLabels());
    }

    public function test_purchase_invoice_kind_uses_issuer_and_transaction_date(): void
    {
        $service = new FurimaDeckMoneyForwardInvoiceKindService;

        $this->assertSame('INVOICE_KIND_QUALIFIED', $service->forPurchaseEvidence('standard_10', 'qualified', '2026-10-01'));
        $this->assertSame('INVOICE_KIND_UNQUALIFIED_80', $service->forPurchaseEvidence('standard_10', 'unqualified', '2026-09-30'));
        $this->assertSame('INVOICE_KIND_UNQUALIFIED_70', $service->forPurchaseEvidence('reduced_8', 'unqualified', '2026-10-01'));
        $this->assertSame('INVOICE_KIND_UNQUALIFIED_50', $service->forPurchaseEvidence('standard_10', 'unqualified', '2028-10-01'));
        $this->assertSame('INVOICE_KIND_UNQUALIFIED_30', $service->forPurchaseEvidence('standard_10', 'unqualified', '2030-10-01'));
        $this->assertSame('INVOICE_KIND_UNQUALIFIED', $service->forPurchaseEvidence('standard_10', 'unqualified', '2031-10-01'));
        $this->assertNull($service->forPurchaseEvidence('standard_10', 'unqualified', '2023-09-30'));
    }

    public function test_non_taxable_purchase_does_not_claim_invoice_deduction_classification(): void
    {
        $service = new FurimaDeckMoneyForwardInvoiceKindService;

        $this->assertSame('INVOICE_KIND_NOT_TARGET', $service->forPurchaseEvidence('non_taxable', 'unqualified', '2026-10-15'));
        $this->assertSame('INVOICE_KIND_NOT_TARGET', $service->forPurchaseEvidence('out_of_scope', 'not_applicable', '2026-10-15'));
    }

    public function test_unknown_or_inconsistent_purchase_evidence_is_rejected(): void
    {
        $service = new FurimaDeckMoneyForwardInvoiceKindService;

        foreach ([
            ['standard_10', 'unknown', '2026-10-15'],
            ['unknown', 'qualified', '2026-10-15'],
            ['standard_10', 'not_applicable', '2026-10-15'],
            ['standard_10', 'qualified', '2026-02-30'],
        ] as [$taxCategory, $issuer, $date]) {
            try {
                $service->forPurchaseEvidence($taxCategory, $issuer, $date);
                $this->fail('未確定または矛盾した証憑を送信可能として扱ってしまいました。');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_antique_dealer_exception_uses_no_regular_invoice_kind(): void
    {
        $service = new FurimaDeckMoneyForwardInvoiceKindService;

        $this->assertNull($service->forPurchaseEvidence('standard_10', 'unqualified', '2026-10-15', true));
    }

    public function test_antique_dealer_exception_rejects_qualified_or_non_taxable_evidence(): void
    {
        $service = new FurimaDeckMoneyForwardInvoiceKindService;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('課税対象かつ適格請求書発行事業者以外');
        $service->forPurchaseEvidence('standard_10', 'qualified', '2026-10-15', true);
    }

    public function test_csv_invoice_labels_follow_money_forward_import_date_boundaries(): void
    {
        $service = new FurimaDeckMoneyForwardInvoiceKindService;
        $cases = [
            [FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED, '2026/10/01', '適格'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_80, '2026/09/30', '80％控除'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_70, '2026/10/01', '70％控除'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_70, '2028/09/30', '70％控除'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_50, '2028/10/01', '50％控除'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_50, '2030/09/30', '50％控除'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_30, '2030/10/01', '30％控除'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_30, '2031/09/30', '30％控除'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED, '2031/10/01', '控除なし'],
        ];

        foreach ($cases as [$kind, $date, $expectedLabel]) {
            $this->assertSame($expectedLabel, $service->csvLabel($kind, $date));
        }
    }

    public function test_csv_invoice_labels_reject_wrong_periods_and_api_only_values(): void
    {
        $service = new FurimaDeckMoneyForwardInvoiceKindService;
        $cases = [
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_80, '2026/10/01'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_70, '2028/10/01'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_50, '2030/10/01'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_30, '2031/10/01'],
            [FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED, '2031/09/30'],
            [FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET, '2026/10/01'],
            [FurimaDeckMoneyForwardInvoiceKindService::OMIT, '2026/10/01'],
        ];

        foreach ($cases as [$kind, $date]) {
            try {
                $service->csvLabel($kind, $date);
                $this->fail('期間外またはAPI専用の区分がCSV出力可能として扱われました。');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }
}
