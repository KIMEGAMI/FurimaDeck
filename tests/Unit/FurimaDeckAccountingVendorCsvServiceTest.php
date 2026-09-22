<?php

namespace Tests\Unit;

use App\Models\AccountingEntry;
use App\Services\FurimaDeckAccountingVendorCsvService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckAccountingVendorCsvServiceTest extends TestCase
{
    public function test_vendor_rows_balance_and_render_in_both_official_column_shapes(): void
    {
        config()->set('furimadeck.accounting.export_accounts', [
            'settlement' => '普通預金',
            'sales' => '売上高',
            'fees' => '販売手数料',
            'cost' => '売上原価',
            'inventory' => '商品',
        ]);
        $entry = new AccountingEntry([
            'sale_id' => 42,
            'transaction_date' => CarbonImmutable::create(2026, 9, 19),
            'title' => 'テスト商品',
            'sale_amount' => 5000,
            'purchase_cost' => 1000,
            'selling_fee' => 500,
            'shipping_cost' => 300,
            'other_cost' => 0,
        ]);

        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $rows = $service->journalRows(new Collection([$entry]));

        $this->assertCount(3, $rows);
        $this->assertSame(6800, collect($rows)->sum('debit_amount'));
        $this->assertSame(6800, collect($rows)->sum('credit_amount'));
        $this->assertSame(8, count($service->freeeHeaders()));
        $this->assertSame(22, count($service->moneyForwardHeaders()));
        $this->assertSame('[明細行]', $service->freeeRow($rows[0])[0]);
        $this->assertSame('インポート', $service->moneyForwardRow($rows[0])[21]);
    }

    public function test_vendor_export_requires_explicit_account_mapping(): void
    {
        config()->set('furimadeck.accounting.export_accounts', []);
        $this->expectException(RuntimeException::class);
        app(FurimaDeckAccountingVendorCsvService::class)->accounts();
    }
}
