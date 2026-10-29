<?php

namespace Tests\Unit;

use App\Services\FurimaDeckMoneyForwardBusinessPeriodService;
use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckMoneyForwardBusinessPeriodServiceTest extends TestCase
{
    public function test_status_is_selected_by_transaction_date_without_reusing_a_legacy_global_value(): void
    {
        $service = new FurimaDeckMoneyForwardBusinessPeriodService;
        $settings = [
            'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::OMIT,
            'business_tax_periods' => $service->normalize([
                ['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED],
                ['from' => '2025-01-01', 'to' => '2025-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
            ]),
        ];

        $this->assertSame(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE, $service->statusForDate($settings, '2025-07-01'));
        $this->assertSame(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED, $service->statusForDate($settings, '2026-07-01'));
    }

    public function test_legacy_invoice_kinds_normalize_to_unconfirmed_business_status(): void
    {
        $service = new FurimaDeckMoneyForwardBusinessPeriodService;
        $periods = array_map(
            static fn (string $status): array => [
                'from' => '2026-01-01',
                'to' => '2026-12-31',
                'invoice_kind' => $status,
            ],
            FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_LEGACY_VALUES,
        );

        foreach ($periods as $period) {
            $normalized = $service->normalize([$period]);
            $this->assertSame(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED, $normalized[0]['invoice_kind']);
        }

        $this->assertSame(
            FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES,
            array_keys(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_LABELS),
        );
        $this->assertCount(3, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES);
    }

    public function test_unknown_stored_status_is_displayed_as_unconfirmed(): void
    {
        $periods = (new FurimaDeckMoneyForwardBusinessPeriodService)->normalizeForDisplay([
            ['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED],
            ['from' => '2026-02-01', 'to' => '2026-12-31', 'invoice_kind' => 'UNRECOGNIZED_STATUS'],
            'invalid-row',
        ]);

        $this->assertSame(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED, $periods[0]['invoice_kind']);
        $this->assertSame(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED, $periods[1]['invoice_kind']);
        $this->assertSame([
            'from' => '',
            'to' => '',
            'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED,
        ], $periods[2]);
    }

    public function test_missing_or_ambiguous_period_never_falls_back_to_legacy_global_status(): void
    {
        $service = new FurimaDeckMoneyForwardBusinessPeriodService;

        foreach ([
            ['invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET],
            ['business_tax_periods' => [
                ['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
                ['from' => '2027-01-01', 'to' => '2027-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
            ]],
        ] as $settings) {
            try {
                $service->statusForDate($settings, '2028-01-01');
                $this->fail('期間外の日付に別の期間や旧設定を適用してしまいました。');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_overlapping_periods_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('期間が重複');

        (new FurimaDeckMoneyForwardBusinessPeriodService)->normalize([
            ['from' => '2026-01-01', 'to' => '2026-06-30', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET],
            ['from' => '2026-06-30', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::OMIT],
        ]);
    }

    public function test_stored_periods_are_revalidated_before_date_resolution(): void
    {
        $this->expectException(RuntimeException::class);

        (new FurimaDeckMoneyForwardBusinessPeriodService)->statusForDate([
            'business_tax_periods' => [
                ['from' => '2026-02-30', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
            ],
        ], '2026-06-01');
    }

    public function test_periods_are_sorted_and_invalid_dates_are_rejected(): void
    {
        $service = new FurimaDeckMoneyForwardBusinessPeriodService;
        $normalized = $service->normalize([
            ['from' => '2027-01-01', 'to' => '2027-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
            ['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
        ]);

        $this->assertSame('2026-01-01', $normalized[0]['from']);
        $this->assertSame('2027-01-01', $normalized[1]['from']);

        try {
            $service->normalize([
                ['from' => '2026-02-30', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
            ]);
            $this->fail('存在しない日付を対象期間として保存できてしまいました。');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }
    }
}
