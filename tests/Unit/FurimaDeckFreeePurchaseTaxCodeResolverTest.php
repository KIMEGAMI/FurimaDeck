<?php

namespace Tests\Unit;

use App\Services\FurimaDeckFreeePurchaseTaxCodeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckFreeePurchaseTaxCodeResolverTest extends TestCase
{
    #[DataProvider('unqualifiedRateCases')]
    public function test_it_resolves_the_freee_tax_code_for_the_transaction_date(string $date, string $rate, string $expectedCode): void
    {
        $taxCodes = [
            ['code' => '136', 'name' => 'purchase_with_tax_10'],
            ['code' => '189', 'name' => 'purchase_with_tax_10_exempt_80'],
            ['code' => '234', 'name' => 'purchase_with_tax_10_exempt_70'],
            ['code' => '190', 'name' => 'purchase_with_tax_10_exempt_50'],
            ['code' => '258', 'name' => 'purchase_with_tax_10_exempt_30'],
            ['code' => '1360', 'name' => 'purchase_with_tax_reduced_8'],
            ['code' => '187', 'name' => 'purchase_with_tax_reduced_8_exempt_80'],
            ['code' => '233', 'name' => 'purchase_with_tax_reduced_8_exempt_70'],
            ['code' => '257', 'name' => 'purchase_with_tax_reduced_8_exempt_30'],
        ];
        $result = app(FurimaDeckFreeePurchaseTaxCodeResolver::class)->resolve(
            $taxCodes,
            '136',
            $rate,
            'unqualified',
            $date,
        );

        $this->assertSame($expectedCode, $result);
    }

    public static function unqualifiedRateCases(): array
    {
        return [
            'pre-invoice regime' => ['2023-09-30', 'standard_10', '136'],
            '80 percent period' => ['2026-09-30', 'standard_10', '189'],
            '70 percent period' => ['2026-10-01', 'standard_10', '234'],
            '50 percent period' => ['2028-10-01', 'standard_10', '190'],
            '30 percent period' => ['2030-10-01', 'standard_10', '258'],
            'reduced tax period' => ['2026-10-01', 'reduced_8', '233'],
        ];
    }

    public function test_qualified_supplier_uses_inventory_account_tax_family(): void
    {
        $result = app(FurimaDeckFreeePurchaseTaxCodeResolver::class)->resolve([
            ['code' => '800', 'name' => 'purchase_with_common_10'],
            ['code' => '801', 'name' => 'purchase_with_common_reduced_8'],
        ], '800', 'standard_10', 'qualified', '2026-10-01');

        $this->assertSame('800', $result);
    }

    public function test_antique_dealer_exception_uses_standard_tax_code_only_for_unqualified_supplier(): void
    {
        $resolver = app(FurimaDeckFreeePurchaseTaxCodeResolver::class);
        $taxCodes = [
            ['code' => '136', 'name' => 'purchase_with_tax_10'],
            ['code' => '234', 'name' => 'purchase_with_tax_10_exempt_70'],
        ];

        $this->assertSame('136', $resolver->resolve($taxCodes, '136', 'standard_10', 'unqualified', '2026-10-01', true));
        $this->expectException(RuntimeException::class);
        $resolver->resolve($taxCodes, '136', 'standard_10', 'qualified', '2026-10-01', true);
    }

    public function test_it_fails_closed_when_the_tax_code_is_unavailable_or_unrecognized(): void
    {
        $this->expectException(RuntimeException::class);
        app(FurimaDeckFreeePurchaseTaxCodeResolver::class)->resolve([
            ['code' => '136', 'name' => 'purchase_with_tax_10'],
            ['code' => '234', 'name' => 'purchase_with_tax_10_exempt_70', 'available' => false],
        ], '136', 'standard_10', 'unqualified', '2026-10-01');
    }
}
