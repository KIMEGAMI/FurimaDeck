<?php

namespace Tests\Unit;

use App\Services\FurimaDeckPurchaseSpecialCaseService;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckPurchaseSpecialCaseServiceTest extends TestCase
{
    public function test_metal_exclusion_confirmation_starts_on_the_effective_date(): void
    {
        $service = app(FurimaDeckPurchaseSpecialCaseService::class);

        $this->assertNotContains('not_specific_metal_scrap', $service->requiredConfirmations('2026-08-31'));
        $this->assertContains('not_specific_metal_scrap', $service->requiredConfirmations('2026-09-01'));
    }

    public function test_confirmation_check_rejects_unconfirmed_metal_exclusion_after_effective_date(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('確認が不足');

        app(FurimaDeckPurchaseSpecialCaseService::class)->assertConfirmations([
            'confirmations' => [
                'dealer_license' => true,
                'qualifying_used_goods' => true,
                'resale_inventory' => true,
                'supplier_unqualified' => true,
            ],
        ], '2026-09-01');
    }

    public function test_confirmation_check_does_not_apply_metal_exclusion_before_effective_date(): void
    {
        app(FurimaDeckPurchaseSpecialCaseService::class)->assertConfirmations([
            'confirmations' => [
                'dealer_license' => true,
                'qualifying_used_goods' => true,
                'resale_inventory' => true,
                'supplier_unqualified' => true,
            ],
        ], '2026-08-31');

        $this->assertTrue(true);
    }
}
