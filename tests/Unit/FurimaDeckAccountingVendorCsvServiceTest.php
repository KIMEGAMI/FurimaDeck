<?php

namespace Tests\Unit;

use App\Models\AccountingEntry;
use App\Services\FurimaDeckAccountingVendorCsvService;
use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckAccountingVendorCsvServiceTest extends TestCase
{
    public function test_vendor_csv_refuses_unsupported_accounting_entry_types(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => 'adjustment',
            'transaction_date' => CarbonImmutable::create(2026, 10, 19),
            'title' => '未対応取引',
            'sale_amount' => 1210,
        ]);
        $service = app(FurimaDeckAccountingVendorCsvService::class);

        foreach ([null, 'freee', 'money_forward'] as $provider) {
            try {
                $service->journalRows(new Collection([$entry]), null, $provider);
                $this->fail('未対応の会計データ種別をCSVに出力しました。');
            } catch (RuntimeException $exception) {
                $this->assertSame('対応していない会計データ種別が含まれるため、仕訳CSVを出力していません。', $exception->getMessage());
            }
        }
    }

    public function test_vendor_rows_balance_and_render_in_both_official_column_shapes(): void
    {
        config()->set('furimadeck.accounting.export_accounts', [
            'settlement' => '普通預金',
            'sales' => '売上高',
            'fees' => '販売手数料',
            'shipping' => '荷造運賃',
            'other' => 'その他経費',
            'cost' => '売上原価',
            'inventory' => '商品',
        ]);
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_SALE,
            'sale_id' => 42,
            'transaction_date' => CarbonImmutable::create(2026, 9, 19),
            'title' => 'テスト商品',
            'sale_amount' => 5000,
            'purchase_cost' => 1000,
            'selling_fee' => 500,
            'shipping_cost' => 300,
            'other_cost' => 0,
        ]);
        $entry->setAttribute($entry->getKeyName(), 1042);

        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $rows = $service->journalRows(new Collection([$entry]));

        $this->assertCount(4, $rows);
        $this->assertSame(6800, collect($rows)->sum('debit_amount'));
        $this->assertSame(6800, collect($rows)->sum('credit_amount'));
        $this->assertSame(['普通預金', '販売手数料', '荷造運賃', '売上原価'], collect($rows)->pluck('debit_account')->all());
        $this->assertSame(10, count($service->freeeHeaders()));
        $this->assertSame([
            '[表題行]', '日付', '伝票番号', '借方勘定科目', '借方金額', '借方税区分',
            '貸方勘定科目', '貸方金額', '貸方税区分', '摘要',
        ], $service->freeeHeaders());
        $this->assertSame('[表題行]', $service->freeeHeaders()[0]);
        $this->assertNotContains('借方税額', $service->freeeHeaders());
        $this->assertNotContains('貸方税額', $service->freeeHeaders());
        $this->assertSame(27, count($service->moneyForwardHeaders()));
        $this->assertSame('[明細行]', $service->freeeRow($rows[0], ['settlement' => '対象外', 'sales' => '課税売上10%'])[0]);
        $this->assertSame('対象外', $service->freeeRow($rows[0], ['settlement' => '対象外', 'sales' => '課税売上10%'])[5]);
        $this->assertCount(count($service->freeeHeaders()), $service->freeeRow($rows[0], ['settlement' => '対象外', 'sales' => '課税売上10%']));
        $mfRow = $service->moneyForwardRow($rows[0], ['settlement' => '対象外', 'sales' => '課税売上10%'], ['settlement' => '普通預金補助'], ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
        $this->assertSame('インポート', $mfRow[21]);
        $this->assertSame('普通預金補助', $mfRow[3]);
        $this->assertSame('対象外', $mfRow[6]);
        $this->assertSame('適格', $mfRow[7]);
        $this->assertSame('課税売上10%', $mfRow[14]);
        $this->assertSame('適格', $mfRow[15]);
        $this->assertNotContains('FD_REVIEW', $mfRow);
        $this->assertCount(27, $mfRow);
    }

    public function test_freee_csv_sale_and_cost_rows_match_the_verified_import_amounts_and_tax_mapping(): void
    {
        config()->set('furimadeck.accounting.export_accounts', [
            'settlement' => '売掛金',
            'sales' => '売上高',
            'fees' => '販売手数料',
            'shipping' => '荷造運賃',
            'other' => 'その他経費',
            'cost' => '仕入高',
            'inventory' => '商品',
        ]);
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_SALE,
            'sale_id' => 20261019,
            'transaction_date' => CarbonImmutable::create(2026, 10, 19),
            'title' => '2026/10/19',
            'sale_amount' => 1210,
            'purchase_cost' => 110,
            'selling_fee' => 0,
            'shipping_cost' => 0,
            'other_cost' => 0,
        ]);
        $entry->setAttribute($entry->getKeyName(), 90261019);
        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $rows = $service->journalRows(new Collection([$entry]));
        $taxNames = [
            'settlement' => '対象外',
            'sales' => '課税売上10%',
            'cost' => '課対仕入10%',
            'inventory' => '対象外',
        ];

        $this->assertSame([
            ['[明細行]', '2026/10/19', '900000090261019', '売掛金', 1210, '対象外', '売上高', 1210, '課税売上10%', '2026/10/19 売上'],
            ['[明細行]', '2026/10/19', '900000090261019', '仕入高', 110, '課対仕入10%', '商品', 110, '対象外', '2026/10/19 原価'],
        ], array_map(fn (array $row): array => $service->freeeRow($row, $taxNames), $rows));
        $this->assertSame(1320, collect($rows)->sum('debit_amount'));
        $this->assertSame(1320, collect($rows)->sum('credit_amount'));
    }

    public function test_freee_rows_use_the_configured_numeric_namespace_and_reject_ids_that_do_not_fit(): void
    {
        config()->set('furimadeck.accounting.export_accounts', [
            'settlement' => '売掛金', 'sales' => '売上高', 'fees' => '販売手数料',
            'cost' => '仕入高', 'inventory' => '商品',
        ]);
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_SALE,
            'transaction_date' => CarbonImmutable::create(2026, 10, 19),
            'title' => 'テスト商品',
            'sale_amount' => 1210,
        ]);
        $entry->setAttribute($entry->getKeyName(), 1042);
        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $taxNames = ['settlement' => '対象外', 'sales' => '課税売上10%'];

        $rows = $service->journalRows(new Collection([$entry]));
        $this->assertSame('900000000001042', $service->freeeRow($rows[0], $taxNames)[2]);

        $entry->setAttribute($entry->getKeyName(), 123456789012345);
        $rows = $service->journalRows(new Collection([$entry]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('安全に作成できない');
        $service->freeeRow($rows[0], $taxNames);
    }

    public function test_freee_row_rejects_values_outside_official_field_limits(): void
    {
        $baseRow = [
            'date' => '2026/10/19',
            'number' => '90261019',
            'debit_role' => 'settlement',
            'debit_account' => '売掛金',
            'debit_amount' => 1210,
            'credit_role' => 'sales',
            'credit_account' => '売上高',
            'credit_amount' => 1210,
            'description' => 'テスト売上',
        ];
        $taxNames = ['settlement' => '対象外', 'sales' => '課税売上10%'];
        $invalidRows = [
            '日付' => [...$baseRow, 'date' => '2026/02/30'],
            '伝票番号' => [...$baseRow, 'number' => str_repeat('9', 21)],
            '勘定科目名' => [...$baseRow, 'debit_account' => str_repeat('科', 31)],
            '摘要' => [...$baseRow, 'description' => str_repeat('a', 1_025)],
        ];
        $service = app(FurimaDeckAccountingVendorCsvService::class);

        foreach ($invalidRows as $field => $row) {
            try {
                $service->freeeRow($row, $taxNames);
                $this->fail($field.'の形式不正を拒否しませんでした。');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_sale_and_purchase_voucher_numbers_cannot_collide_across_id_sequences(): void
    {
        $sale = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_SALE,
            'sale_id' => 77,
            'transaction_date' => CarbonImmutable::create(2026, 10, 3),
            'title' => '売却品',
            'sale_amount' => 3000,
            'purchase_cost' => 0,
            'selling_fee' => 0,
            'shipping_cost' => 0,
            'other_cost' => 0,
        ]);
        $sale->setAttribute($sale->getKeyName(), 91);
        $purchase = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => CarbonImmutable::create(2026, 10, 3),
            'title' => '仕入品',
            'purchase_cost' => 1200,
            'other_cost' => 0,
            'expense_breakdown' => [
                'purchase_quantity' => 1,
                'purchase_unit_cost' => 1200,
                'payment_sources' => ['freee' => 'bank-source'],
            ],
        ]);
        $purchase->setAttribute($purchase->getKeyName(), 77);

        $rows = app(FurimaDeckAccountingVendorCsvService::class)->journalRows(
            new Collection([$sale, $purchase]),
            [
                'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '販売手数料',
                'shipping' => '荷造運賃', 'other' => 'その他経費', 'cost' => '売上原価',
                'inventory' => '商品', 'purchase_payment_bank-source' => '買掛金',
            ],
            null,
        );

        $this->assertSame(['91'], collect($rows)->take(1)->pluck('number')->unique()->values()->all());
        $this->assertSame(['77'], collect($rows)->skip(1)->pluck('number')->unique()->values()->all());
    }

    public function test_vendor_export_requires_explicit_account_mapping(): void
    {
        config()->set('furimadeck.accounting.export_accounts', []);
        $this->expectException(RuntimeException::class);
        app(FurimaDeckAccountingVendorCsvService::class)->accounts();
    }

    public function test_unclassified_sale_expense_cannot_be_silently_exported_to_generic_csv(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_SALE,
            'sale_id' => 43,
            'transaction_date' => CarbonImmutable::create(2026, 10, 18),
            'title' => '費目確認商品',
            'sale_amount' => 5000,
            'purchase_cost' => 1000,
            'selling_fee' => 500,
            'shipping_cost' => 300,
            'other_cost' => 200,
            'expense_breakdown' => ['packing_cost' => 200],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('会計サービス別の支払元を確認できない');
        app(FurimaDeckAccountingVendorCsvService::class)->journalRows(new Collection([$entry]));
    }

    public function test_provider_csv_refuses_sales_with_unclassified_shipping_payment_source(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_SALE,
            'sale_id' => 44,
            'transaction_date' => CarbonImmutable::create(2026, 10, 18),
            'title' => '送料の支払元未確認商品',
            'sale_amount' => 5000,
            'purchase_cost' => 1000,
            'selling_fee' => 0,
            'shipping_cost' => 300,
            'other_cost' => 0,
        ]);

        foreach (['freee', 'money_forward'] as $provider) {
            try {
                app(FurimaDeckAccountingVendorCsvService::class)->journalRows(new Collection([$entry]), null, $provider);
                $this->fail($provider.'向けに支払元未確認の販売送料を出力しました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('販売送料の支払元が未確認', $exception->getMessage());
            }
        }
    }

    public function test_purchase_entry_requires_its_selected_provider_payment_account(): void
    {
        config()->set('furimadeck.accounting.export_accounts', [
            'settlement' => '普通預金',
            'sales' => '売上高',
            'fees' => '販売手数料',
            'shipping' => '荷造運賃',
            'other' => 'その他経費',
            'cost' => '売上原価',
            'inventory' => '商品',
        ]);
        $purchase = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => CarbonImmutable::create(2026, 10, 3),
            'title' => '支払元未設定の仕入商品',
            'id' => 7,
            'purchase_cost' => 1200,
            'other_cost' => 0,
            'expense_breakdown' => [
                'purchase_quantity' => 1,
                'purchase_unit_cost' => 1200,
                'payment_sources' => ['freee' => '1'],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('選択済み支払元科目が会計サービス設定にありません');
        app(FurimaDeckAccountingVendorCsvService::class)->journalRows(new Collection([$purchase]));
    }

    public function test_provider_specific_purchase_csv_is_blocked_while_generic_export_is_preserved(): void
    {
        $purchase = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => CarbonImmutable::create(2026, 10, 3),
            'management_id' => 'SKU-77',
            'title' => '仕入商品',
            'purchase_cost' => 1200,
            'other_cost' => 200,
            'expense_breakdown' => [
                'purchase_quantity' => 1,
                'purchase_unit_cost' => 1200,
                'purchase_shipping_cost' => 200,
                'other_purchase_expense' => 0,
                'payment_sources' => ['freee' => '1', 'money_forward' => 'bank-2'],
            ],
        ]);
        $purchase->setAttribute($purchase->getKeyName(), 77);
        $accounts = [
            'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '支払手数料', 'cost' => '売上原価',
            'inventory' => '商品', 'purchase_payment_1' => 'freee銀行', 'purchase_payment_bank-2' => 'Money Forward銀行',
        ];

        $service = app(FurimaDeckAccountingVendorCsvService::class);
        foreach (['freee', 'money_forward'] as $provider) {
            try {
                $service->journalRows(new Collection([$purchase]), $accounts, $provider);
                $this->fail($provider.'向けの仕入CSVを出力しました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('仕入CSVはFurimaDeck 1.0の対応対象外', $exception->getMessage());
            }
        }

        $rows = $service->journalRows(new Collection([$purchase]), $accounts);

        $this->assertCount(2, $rows);
        $this->assertSame(1400, collect($rows)->sum('debit_amount'));
        $this->assertSame(1400, collect($rows)->sum('credit_amount'));
        $this->assertSame(['freee銀行', 'freee銀行'], collect($rows)->pluck('credit_account')->all());
        $this->assertSame(['77', '77'], collect($rows)->pluck('number')->all());
        $this->assertSame('purchase_tax_77_purchase_unit_cost', $rows[0]['debit_role']);
        $this->assertSame('purchase_payment_tax_77_purchase_unit_cost', $rows[0]['credit_role']);
    }

    public function test_provider_specific_csv_blocks_separately_paid_sale_expenses(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_SALE,
            'transaction_date' => CarbonImmutable::create(2026, 10, 3),
            'title' => '別途費用のある販売',
            'sale_amount' => 5000,
            'purchase_cost' => 1000,
            'selling_fee' => 0,
            'shipping_cost' => 0,
            'other_cost' => 200,
            'expense_breakdown' => ['packing_cost' => 200],
        ]);

        foreach (['freee', 'money_forward'] as $provider) {
            try {
                app(FurimaDeckAccountingVendorCsvService::class)->journalRows(new Collection([$entry]), null, $provider);
                $this->fail($provider.'向けに未検証の別途費用を出力しました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('別途支払った販売費用を含む明細', $exception->getMessage());
            }
        }
    }

    public function test_provider_specific_csv_blocks_marketplace_fees_until_real_import_is_verified(): void
    {
        $entry = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_SALE,
            'transaction_date' => CarbonImmutable::create(2026, 10, 3),
            'title' => '販売手数料あり商品',
            'sale_amount' => 5000,
            'purchase_cost' => 1000,
            'selling_fee' => 500,
            'shipping_cost' => 0,
            'other_cost' => 0,
        ]);

        foreach (['freee', 'money_forward'] as $provider) {
            try {
                app(FurimaDeckAccountingVendorCsvService::class)->journalRows(new Collection([$entry]), null, $provider);
                $this->fail($provider.'向けに未検証の販売手数料明細を出力しました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('販売手数料を含む明細は、実取込の検証が完了していない', $exception->getMessage());
            }
        }
    }

    public function test_purchase_csv_validates_and_normalizes_antique_dealer_details(): void
    {
        $purchase = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => CarbonImmutable::create(2026, 10, 3),
            'management_id' => "SKU-\n77",
            'title' => "仕入\r\n商品",
            'purchase_cost' => 1200,
            'other_cost' => 0,
            'expense_breakdown' => [
                'purchase_quantity' => 1,
                'purchase_unit_cost' => 1200,
                'payment_sources' => ['freee' => 'bank-source'],
                'evidence' => [
                    'purchase_unit_cost' => ['tax_category' => 'standard_10', 'invoice_issuer' => 'unqualified'],
                ],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => "許可\n番号",
                'counterparty_name' => "仕入\r\n先",
                'counterparty_address' => "東京都\n千代田区",
                'confirmed_at' => '2026-10-15T00:00:00+09:00',
                'confirmations' => array_fill_keys(['dealer_license', 'qualifying_used_goods', 'resale_inventory', 'supplier_unqualified', 'not_specific_metal_scrap'], true),
            ],
        ]);
        $purchase->setAttribute($purchase->getKeyName(), 78);
        $accounts = ['inventory' => '商品', 'purchase_payment_bank-source' => '普通預金'];

        $rows = app(FurimaDeckAccountingVendorCsvService::class)->journalRows(new Collection([$purchase]), $accounts);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('許可番号:許可 番号', $rows[0]['description']);
        $this->assertStringContainsString('仕入先:仕入 先', $rows[0]['description']);
        $this->assertStringContainsString('住所:東京都 千代田区', $rows[0]['description']);
        $this->assertStringContainsString('管理ID:SKU- 77', $rows[0]['description']);
        $this->assertDoesNotMatchRegularExpression('/[\r\n\t]/', $rows[0]['description']);
    }

    public function test_purchase_csv_rejects_antique_dealer_when_source_evidence_conflicts(): void
    {
        $purchase = new AccountingEntry([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'transaction_date' => CarbonImmutable::create(2026, 10, 3),
            'title' => '仕入商品',
            'purchase_cost' => 1200,
            'other_cost' => 0,
            'expense_breakdown' => [
                'purchase_quantity' => 1,
                'purchase_unit_cost' => 1200,
                'payment_sources' => ['freee' => 'bank-source'],
                'evidence' => [
                    'purchase_unit_cost' => ['tax_category' => 'standard_10', 'invoice_issuer' => 'qualified'],
                ],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => '許可番号',
                'counterparty_name' => '仕入先',
                'counterparty_address' => '住所',
                'confirmed_at' => '2026-10-15T00:00:00+09:00',
                'confirmations' => array_fill_keys(['dealer_license', 'qualifying_used_goods', 'resale_inventory', 'supplier_unqualified', 'not_specific_metal_scrap'], true),
            ],
        ]);
        $purchase->setAttribute($purchase->getKeyName(), 79);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('証憑区分が一致しない');
        app(FurimaDeckAccountingVendorCsvService::class)->journalRows(
            new Collection([$purchase]),
            ['inventory' => '商品', 'purchase_payment_bank-source' => '普通預金'],
        );
    }

    public function test_shipping_requires_its_own_account_when_amount_is_present(): void
    {
        config()->set('furimadeck.accounting.export_accounts', [
            'settlement' => '普通預金', 'sales' => '売上高', 'fees' => '販売手数料',
            'cost' => '売上原価', 'inventory' => '商品',
        ]);
        $entry = new AccountingEntry([
            'id' => 42, 'entry_type' => AccountingEntry::TYPE_SALE, 'sale_id' => 4200, 'transaction_date' => CarbonImmutable::create(2026, 9, 19),
            'title' => 'テスト商品', 'sale_amount' => 5000, 'selling_fee' => 500,
            'shipping_cost' => 300, 'other_cost' => 0, 'purchase_cost' => 1000,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shipping');
        app(FurimaDeckAccountingVendorCsvService::class)->journalRows(new Collection([$entry]));
    }

    public function test_money_forward_rejects_transaction_numbers_longer_than_nine_digits(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('9桁以内');
        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'number' => '1234567890', 'date' => '2026/09/19',
            'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], [], [], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
    }

    public function test_money_forward_row_rejects_missing_malformed_and_impossible_dates(): void
    {
        $baseRow = [
            'number' => '42', 'date' => '2026/09/19',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ];
        $taxNames = ['settlement' => '対象外', 'sales' => '課税売上10%'];
        $invoiceKinds = ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'];
        $service = app(FurimaDeckAccountingVendorCsvService::class);

        foreach (['', '2026-09-19', '2026/02/30'] as $date) {
            try {
                $service->moneyForwardRow(array_replace($baseRow, ['date' => $date]), $taxNames, [], $invoiceKinds, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
                $this->fail('不正なMoney Forward取引日を許可しました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('YYYY/MM/DD', $exception->getMessage());
            }
        }
    }

    public function test_money_forward_row_rejects_non_positive_or_non_integer_amounts(): void
    {
        $baseRow = [
            'number' => '42', 'date' => '2026/09/19',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ];
        $taxNames = ['settlement' => '対象外', 'sales' => '課税売上10%'];
        $invoiceKinds = ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'];
        $service = app(FurimaDeckAccountingVendorCsvService::class);

        foreach ([['debit_amount' => 0], ['credit_amount' => -1], ['debit_amount' => '1.5'], ['credit_amount' => null]] as $invalidAmount) {
            try {
                $service->moneyForwardRow(array_replace($baseRow, $invalidAmount), $taxNames, [], $invoiceKinds, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
                $this->fail('不正なMoney Forward金額を許可しました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('1円以上の整数', $exception->getMessage());
            }
        }
    }

    public function test_money_forward_row_rejects_unbalanced_amounts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('借方・貸方金額が一致しません');
        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'number' => '42', 'date' => '2026/09/19',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 99,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], [], [
            'settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED',
        ], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
    }

    public function test_freee_row_requires_explicit_tax_names_for_both_sides(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('税区分が未設定');
        app(FurimaDeckAccountingVendorCsvService::class)->freeeRow([
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], ['settlement' => '対象外']);
    }

    public function test_freee_row_rejects_amounts_below_one_yen_or_non_integer_values(): void
    {
        $baseRow = [
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ];
        $invalidAmounts = [
            ['debit_amount' => 0],
            ['credit_amount' => 0],
            ['debit_amount' => -1],
            ['credit_amount' => '1.5'],
        ];
        $taxNames = ['settlement' => '対象外', 'sales' => '課税売上10%'];
        $service = app(FurimaDeckAccountingVendorCsvService::class);

        foreach ($invalidAmounts as $invalidAmount) {
            try {
                $service->freeeRow(array_replace($baseRow, $invalidAmount), $taxNames);
                $this->fail('1円未満または整数以外の金額を許可しました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('1円以上の整数', $exception->getMessage());
            }
        }
    }

    public function test_freee_row_rejects_unbalanced_amounts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('借方・貸方金額が一致しません');
        app(FurimaDeckAccountingVendorCsvService::class)->freeeRow([
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 99,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%']);
    }

    public function test_money_forward_csv_matches_all_twenty_seven_import_columns(): void
    {
        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $row = $service->moneyForwardRow([
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], [], ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);

        $this->assertSame([
            '取引No', '取引日', '借方勘定科目', '借方補助科目', '借方部門', '借方取引先', '借方税区分', '借方インボイス', '借方金額(円)', '借方税額',
            '貸方勘定科目', '貸方補助科目', '貸方部門', '貸方取引先', '貸方税区分', '貸方インボイス', '貸方金額(円)', '貸方税額', '摘要', '仕訳メモ',
            'タグ', 'MF仕訳タイプ', '決算整理仕訳', '作成日時', '作成者', '最終更新日時', '最終更新者',
        ], $service->moneyForwardHeaders());
        $this->assertCount(27, $row);
        $this->assertSame('適格', $row[7]);
        $this->assertSame('適格', $row[15]);
        $this->assertSame('', $row[9]);
        $this->assertSame('', $row[17]);
        $this->assertSame(['', '', '', ''], array_slice($row, 23, 4));
        $this->assertSame(['作成日時', '作成者', '最終更新日時', '最終更新者'], array_slice($service->moneyForwardHeaders(), 23, 4));
    }

    public function test_money_forward_exempt_row_leaves_tax_and_invoice_columns_blank(): void
    {
        $row = [
            'date' => '2026/10/26', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ];

        $rendered = app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow(
            $row,
            [],
            [],
            [],
            FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT,
        );

        $this->assertSame('', $rendered[6]);
        $this->assertSame('', $rendered[7]);
        $this->assertSame('', $rendered[14]);
        $this->assertSame('', $rendered[15]);
        $this->assertSame('FurimaDeck要確認', $rendered[19]);
    }

    public function test_money_forward_row_rejects_unconfirmed_business_status(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('課税・免税状態を確認できない');

        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'date' => '2026/10/26', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '売掛金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], [], [], [], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED);
    }

    public function test_money_forward_csv_rejects_blank_tax_names(): void
    {
        $row = [
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('税区分が未設定');
        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow(
            $row,
            [],
            [],
            ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'],
            FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
        );
    }

    public function test_money_forward_csv_still_rejects_missing_tax_names_for_taxable_business(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('税区分が未設定');
        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], [], [], ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
    }

    public function test_money_forward_rejects_descriptions_over_two_hundred_characters(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('摘要は200字以内');
        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => str_repeat('あ', 201),
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], [], ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
    }

    public function test_money_forward_rejects_account_names_over_the_official_limit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('勘定科目名は30字以内');
        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => str_repeat('科', 31), 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], [], ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
    }

    public function test_money_forward_rejects_sub_account_names_over_the_official_limit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('補助科目名は30字以内');
        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], ['settlement' => str_repeat('補', 31)], ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
    }

    public function test_money_forward_accepts_account_and_sub_account_names_at_the_official_limit(): void
    {
        $row = app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'date' => '2026/09/19', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => str_repeat('科', 30), 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], ['settlement' => str_repeat('補', 30)], [
            'settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED',
        ], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);

        $this->assertSame(str_repeat('科', 30), $row[2]);
        $this->assertSame(str_repeat('補', 30), $row[3]);
    }

    public function test_freee_rejects_fields_that_exceed_limits_after_formula_sanitization(): void
    {
        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $limits = config('furimadeck.accounting.csv_limits.freee');
        $baseRow = [
            'date' => '2026/10/19',
            'number' => '90261019',
            'debit_role' => 'settlement',
            'debit_account' => '普通預金',
            'debit_amount' => 1210,
            'credit_role' => 'sales',
            'credit_account' => '売上高',
            'credit_amount' => 1210,
            'description' => 'テスト売上',
        ];
        $taxNames = ['settlement' => '対象外', 'sales' => '課税売上10%'];
        $rows = [
            '勘定科目名' => [...$baseRow, 'debit_account' => '='.str_repeat('a', $limits['max_account_name_characters'] - 1)],
            '摘要' => [...$baseRow, 'description' => '='.str_repeat('a', $limits['max_description_characters'] - 1)],
        ];

        foreach ($rows as $field => $row) {
            try {
                $service->sanitizeRowForProvider($service->freeeRow($row, $taxNames), 'freee');
                $this->fail($field.'の数式対策後上限超過を拒否しませんでした。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('数式対策後', $exception->getMessage());
            }
        }
    }

    public function test_money_forward_rejects_fields_that_exceed_limits_after_formula_sanitization(): void
    {
        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $limits = config('furimadeck.accounting.csv_limits.money_forward');
        $baseRow = [
            'date' => '2026/10/19',
            'number' => '90261019',
            'debit_role' => 'settlement',
            'debit_account' => '普通預金',
            'debit_amount' => 1210,
            'credit_role' => 'sales',
            'credit_account' => '売上高',
            'credit_amount' => 1210,
            'description' => 'テスト売上',
        ];
        $taxNames = ['settlement' => '対象外', 'sales' => '課税売上10%'];
        $invoiceKinds = ['settlement' => 'INVOICE_KIND_QUALIFIED', 'sales' => 'INVOICE_KIND_QUALIFIED'];
        $cases = [
            '勘定科目名' => [
                [...$baseRow, 'debit_account' => '='.str_repeat('a', $limits['max_account_name_characters'] - 1)],
                [],
            ],
            '補助科目名' => [$baseRow, ['settlement' => '-'.str_repeat('a', $limits['max_sub_account_name_characters'] - 1)]],
            '摘要' => [...$baseRow, 'description' => '='.str_repeat('a', $limits['max_description_characters'] - 1)],
        ];

        foreach ($cases as $field => $case) {
            [$row, $subAccounts] = array_is_list($case) ? $case : [$case, []];
            try {
                $vendorRow = $service->moneyForwardRow($row, $taxNames, $subAccounts, $invoiceKinds, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
                $service->sanitizeRowForProvider($vendorRow, 'money_forward');
                $this->fail($field.'の数式対策後上限超過を拒否しませんでした。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('数式対策後', $exception->getMessage());
            }
        }
    }

    public function test_formula_sanitization_handles_unicode_separator_prefixes_for_both_vendors(): void
    {
        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $cases = [
            'freee' => [9, "\u{00A0}=1+1"],
            'money_forward' => [18, "\u{2003}+1+1"],
        ];

        foreach ($cases as $provider => [$descriptionIndex, $description]) {
            $row = array_fill(0, $descriptionIndex + 1, '');
            $row[$descriptionIndex] = $description;
            $sanitized = $service->sanitizeRowForProvider($row, $provider);

            $this->assertSame("'".$description, $sanitized[$descriptionIndex]);
        }
    }

    public function test_invalid_utf8_is_rejected_before_vendor_csv_output(): void
    {
        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $cases = ['freee' => 9, 'money_forward' => 18];

        foreach ($cases as $provider => $descriptionIndex) {
            $row = array_fill(0, $descriptionIndex + 1, '');
            $row[$descriptionIndex] = "\xFF=1+1";
            $exception = null;

            try {
                $service->sanitizeRowForProvider($row, $provider);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            $this->assertNotNull($exception, $provider.' CSVが不正なUTF-8を受け入れました。');
            $this->assertStringContainsString('UTF-8', $exception->getMessage());
        }
    }

    public function test_non_scalar_csv_cells_are_rejected_without_array_conversion_warnings(): void
    {
        $service = app(FurimaDeckAccountingVendorCsvService::class);
        $cases = ['freee' => 9, 'money_forward' => 18];

        foreach ($cases as $provider => $descriptionIndex) {
            $row = array_fill(0, $descriptionIndex + 1, '');
            $row[$descriptionIndex] = ['=1+1'];
            $exception = null;

            try {
                $service->sanitizeRowForProvider($row, $provider);
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }

            $this->assertNotNull($exception, $provider.' CSVが配列セルを受け入れました。');
            $this->assertStringContainsString('項目の形式', $exception->getMessage());
        }
    }

    public function test_money_forward_csv_rejects_invoice_kinds_that_import_as_qualified_when_blank(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('空欄は「適格」として扱われる');
        app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'date' => '2026/10/12', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], [], [
            'settlement' => 'INVOICE_KIND_NOT_TARGET', 'sales' => 'INVOICE_KIND_OMIT',
        ], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
    }

    public function test_money_forward_csv_uses_the_date_effective_invoice_deduction_label(): void
    {
        $row = app(FurimaDeckAccountingVendorCsvService::class)->moneyForwardRow([
            'date' => '2026/10/12', 'number' => '42',
            'debit_role' => 'settlement', 'debit_account' => '普通預金', 'debit_amount' => 100,
            'credit_role' => 'sales', 'credit_account' => '売上高', 'credit_amount' => 100,
            'description' => 'テスト',
        ], ['settlement' => '対象外', 'sales' => '課税売上10%'], [], [
            'settlement' => 'INVOICE_KIND_UNQUALIFIED_70', 'sales' => 'INVOICE_KIND_QUALIFIED',
        ], FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);

        $this->assertSame('70％控除', $row[7]);
        $this->assertSame('適格', $row[15]);
    }
}
