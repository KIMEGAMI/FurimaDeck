<?php

namespace Tests\Feature;

use App\Jobs\SendMoneyForwardAccountingEntryJob;
use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\AccountingPurchaseCapReview;
use App\Models\AccountingSyncBatch;
use App\Models\Marketplace;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckAccountingJournalComparator;
use App\Services\FurimaDeckAccountingJournalVerificationService;
use App\Services\FurimaDeckAccountingProviderScopeService;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use App\Services\FurimaDeckInvoiceTransitionCapService;
use App\Services\FurimaDeckMoneyForwardAccountingSetupService;
use App\Services\FurimaDeckMoneyForwardAccountingSyncService;
use App\Services\FurimaDeckMoneyForwardCsvMappingService;
use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckMoneyForwardAccountingSyncTest extends TestCase
{
    public function test_setup_lists_only_accounts_compatible_with_each_role(): void
    {
        $eligible = app(FurimaDeckMoneyForwardAccountingSetupService::class)->eligibleAccountIds([
            ['id' => 'asset', 'name' => '普通預金', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET', 'sub_accounts' => [['id' => 'asset-sub', 'name' => '事業用']]],
            ['id' => 'liability', 'name' => '未払金', 'account_group' => 'LIABILITY', 'financial_statement_type' => 'BALANCE_SHEET'],
            ['id' => 'revenue', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
            ['id' => 'expense', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
            ['id' => 'wrong-statement', 'name' => '不正区分', 'account_group' => 'ASSET', 'financial_statement_type' => 'PROFIT_LOSS'],
            ['id' => 'unknown', 'account_group' => '', 'financial_statement_type' => ''],
        ]);

        $this->assertSame(['asset', 'asset-sub'], $eligible['settlement']);
        $this->assertSame(['asset', 'asset-sub'], $eligible['inventory']);
        $this->assertSame(['revenue'], $eligible['sales']);
        $this->assertSame(['expense'], $eligible['fees']);
        $this->assertSame(['expense'], $eligible['cost']);
        $this->assertNotContains('unknown', $eligible['cost']);
        $this->assertSame(['asset', 'asset-sub', 'liability'], $eligible['purchase_payment_source']);
        $this->assertNotContains('wrong-statement', $eligible['purchase_payment_source']);
    }

    public function test_setup_excludes_unavailable_accounts_and_their_subaccounts(): void
    {
        $eligible = app(FurimaDeckMoneyForwardAccountingSetupService::class)->eligibleAccountIds([
            [
                'id' => 'disabled-asset',
                'name' => '無効な預金',
                'account_group' => 'ASSET',
                'financial_statement_type' => 'BALANCE_SHEET',
                'available' => false,
                'sub_accounts' => [['id' => 'disabled-asset-sub', 'name' => '無効な補助']],
            ],
            [
                'id' => 'disabled-expense',
                'name' => '無効な売上原価',
                'account_group' => 'EXPENSE',
                'financial_statement_type' => 'PROFIT_LOSS',
                'available' => false,
            ],
            [
                'id' => 'enabled-expense',
                'name' => '有効な費用',
                'account_group' => 'EXPENSE',
                'financial_statement_type' => 'PROFIT_LOSS',
                'available' => true,
            ],
        ]);

        $this->assertSame([], $eligible['settlement']);
        $this->assertSame([], $eligible['inventory']);
        $this->assertSame(['enabled-expense'], $eligible['fees']);
        $this->assertSame(['enabled-expense'], $eligible['cost']);
        $this->assertSame([], $eligible['purchase_payment_source']);
    }

    public function test_saved_unavailable_cost_account_invalidates_current_configuration(): void
    {
        [$user] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                static fn (int $index): array => ['id' => 'tax-'.$index, 'name' => '税区分'.$index, 'available' => true],
                range(11, 17),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET', 'available' => true],
                ['id' => 'sales-id', 'name' => '売上高', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS', 'available' => true],
                ['id' => 'fees-id', 'name' => '支払手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS', 'available' => true],
                ['id' => 'shipping-id', 'name' => '荷造運賃', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS', 'available' => true],
                ['id' => 'cost-id', 'name' => '売上原価', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS', 'available' => false],
                ['id' => 'inventory-id', 'name' => '商品', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET', 'available' => true],
            ]]),
        ]);

        try {
            app(FurimaDeckMoneyForwardAccountingSetupService::class)->assertCurrentConfiguration(
                $user,
                $connection,
                FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT,
            );
            $this->fail('Money Forwardで無効化された原価科目を含む設定が有効扱いになりました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('勘定科目', $exception->getMessage());
        }

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-accounting.test/api/v3/accounts?available=true');
    }

    public function test_setup_excludes_tax_categories_marked_unavailable_by_money_forward(): void
    {
        [$user] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => [
                ['id' => 'active-tax', 'name' => '対象外', 'available' => true],
                ['id' => 'inactive-tax', 'name' => '課税売上10%', 'available' => false],
            ]]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => []]),
        ]);

        $options = app(FurimaDeckMoneyForwardAccountingSetupService::class)->options($user);

        $this->assertSame(['active-tax'], collect($options['taxes']['taxes'])->pluck('id')->all());
    }

    public function test_setup_remains_visible_when_money_forward_has_no_available_tax_categories(): void
    {
        [$user] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => []]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => []]),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.money-forward.setup'))
            ->assertOk()
            ->assertSee('事業者の課税・免税状態と対象期間')
            ->assertSee('課税・免税の状態未確認（Money Forward CSV停止）')
            ->assertSee('対象期間ごとに「状態未確認」を設定して保存できます')
            ->assertSee('該当する取引の証憑で確認してください。')
            ->assertSee('税務上の課税・免税の判定そのものではありません。')
            ->assertSee('国税庁の公表サイト', false)
            ->assertSee('T」を除いた13桁')
            ->assertSee('消費税の課税・免税を判断しないでください。')
            ->assertSee('Money Forwardサポート回答（2026/10/26）')
            ->assertSee('空欄の実際の表示と仕訳内容は最新CSVのプレビューで確認してください。')
            ->assertSee('FurimaDeckは免税確認済みの取引で両欄を空欄にしてCSVを出力しますが、')
            ->assertSee('簡易課税・原則課税で税区分欄が空欄または省略された場合の扱いは')
            ->assertSee('href="https://biz.moneyforward.com/support/tax-return/guide/import-books/ib01.html"', false)
            ->assertSee('value="'.FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED.'"', false)
            ->assertDontSee('value="'.FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET.'"', false)
            ->assertDontSee('value="'.FurimaDeckMoneyForwardInvoiceKindService::OMIT.'"', false);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-accounting.test/api/v3/taxes?available=true');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-accounting.test/api/v3/accounts?available=true');
    }

    public function test_unconfirmed_business_status_can_be_saved_without_tax_master_and_blocks_api_send(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $storedConnection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $storedSettings = $storedConnection->settings;
        $storedSettings['purchase_tax_ids'] = ['standard_10' => 'previous-tax-id'];
        $storedConnection->update(['settings' => $storedSettings]);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => []]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '売掛金', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'name' => '売上', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'name' => '手数料', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'name' => '仕入', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'name' => '商品', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.money-forward.setup.save'), [
                'account_ids' => [
                    'settlement' => 'settlement-id', 'sales' => 'sales-id', 'fees' => 'fees-id',
                    'cost' => 'cost-id', 'inventory' => 'inventory-id',
                ],
                'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED]],
            ])
            ->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']))
            ->assertSessionHas('success');

        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->fresh();
        $this->assertSame(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED, $connection->settings['business_tax_periods'][0]['invoice_kind']);
        $this->assertSame(['standard_10' => 'previous-tax-id'], $connection->settings['purchase_tax_ids']);
        $this->assertSame($this->invoiceKindsFor(), $connection->settings['invoice_kinds']);
        $missing = app(FurimaDeckMoneyForwardAccountingSyncService::class)->missingConfiguration($connection);
        $this->assertContains('business_tax_status_confirmation', $missing);
        $this->assertNotContains('invoice_kinds.settlement', $missing);

        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('状態未確認のMoney Forward事業者へAPI送信できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('状態が未確認', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_taxable_business_status_requires_counterparty_invoice_kinds(): void
    {
        [$user] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => []]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => []]),
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.money-forward.setup.save'), [
                'account_ids' => [
                    'settlement' => 'settlement-id', 'sales' => 'sales-id', 'fees' => 'fees-id',
                    'cost' => 'cost-id', 'inventory' => 'inventory-id',
                ],
                'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
            ])
            ->assertSessionHasErrors([
                'invoice_kinds.settlement',
                'invoice_kinds.sales',
                'invoice_kinds.fees',
                'invoice_kinds.cost',
                'invoice_kinds.inventory',
            ]);

        $this->get(route('furimadeck-accounting.money-forward.setup'))
            ->assertOk()
            ->assertSee('value="'.FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE.'" selected', false)
            ->assertSee('name="invoice_kinds[settlement]"', false);
    }

    public function test_selected_optional_accounts_can_be_saved_without_optional_invoice_kinds(): void
    {
        [$user] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                static fn (int $index): array => ['id' => 'tax-'.$index, 'name' => '税区分'.$index],
                range(11, 17),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'name' => '売上高', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'name' => '販売手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'name' => '売上原価', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'name' => '商品', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'shipping-id', 'name' => '荷造運賃', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'other-id', 'name' => '雑費', 'tax_id' => 'tax-17', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
            ]]),
        ]);

        $invoiceKinds = $this->invoiceKindsFor(FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED);
        unset($invoiceKinds['shipping'], $invoiceKinds['other']);

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.money-forward.setup.save'), [
                'account_ids' => [
                    'settlement' => 'settlement-id', 'sales' => 'sales-id', 'fees' => 'fees-id',
                    'cost' => 'cost-id', 'inventory' => 'inventory-id',
                    'shipping' => 'shipping-id', 'other' => 'other-id',
                ],
                'invoice_kinds' => $invoiceKinds,
                'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
            ])
            ->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']))
            ->assertSessionHas('success');

        $settings = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->fresh()->settings;
        $this->assertSame('shipping-id', $settings['account_ids']['shipping']);
        $this->assertSame('other-id', $settings['account_ids']['other']);
        $this->assertArrayNotHasKey('shipping', $settings['invoice_kinds']);
        $this->assertArrayNotHasKey('other', $settings['invoice_kinds']);
    }

    public function test_money_forward_csv_is_blocked_when_business_status_is_unconfirmed(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['business_tax_periods'][0]['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED;
        $connection->update(['settings' => $settings]);

        try {
            app(FurimaDeckMoneyForwardCsvMappingService::class)->forUser($user, collect([$entry]));
            $this->fail('状態未確認なのにMoney Forward仕訳CSVを出力できる状態でした。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('状態が未確認', $exception->getMessage());
        }
    }

    public function test_money_forward_csv_rejects_bank_account_when_sale_receipt_is_not_recorded(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                static fn (int $index): array => ['id' => 'tax-'.$index, 'name' => '税区分'.$index, 'available' => true],
                range(11, 17),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET', 'available' => true],
                ['id' => 'sales-id', 'name' => '売上高', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS', 'available' => true],
                ['id' => 'fees-id', 'name' => '販売手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS', 'available' => true],
                ['id' => 'shipping-id', 'name' => '荷造運賃', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS', 'available' => true],
                ['id' => 'cost-id', 'name' => '売上原価', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS', 'available' => true],
                ['id' => 'inventory-id', 'name' => '商品', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET', 'available' => true],
            ]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('銀行口座科目のままではCSVを出力しません');
        app(FurimaDeckMoneyForwardCsvMappingService::class)->forUser($user, collect([$entry]));
    }

    public function test_money_forward_csv_mapping_uses_configured_account_name_limit(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        config()->set('furimadeck.accounting.csv_limits.money_forward.max_account_name_characters', 2);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                static fn (int $index): array => ['id' => 'tax-'.$index, 'name' => '税区分'.$index],
                range(11, 16),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'name' => '売上高', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'name' => '販売手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'name' => '荷造運賃', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'name' => '売上原価', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'name' => '商品', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('勘定科目・補助科目・税区分を確認できません');
        app(FurimaDeckMoneyForwardCsvMappingService::class)->forUser($user, collect([$entry]));
    }

    public function test_optional_account_invoice_kinds_do_not_block_core_sale_mapping(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = (array) $connection->settings;
        unset($settings['invoice_kinds']['shipping'], $settings['invoice_kinds']['other']);
        $settings['account_ids']['other'] = 'other-id';
        $settings['sub_account_ids']['other'] = null;
        $settings['tax_ids']['other'] = 'tax-17';
        $connection->update(['settings' => $settings]);

        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                static fn (int $index): array => ['id' => 'tax-'.$index, 'name' => '税区分'.$index, 'available' => true],
                range(11, 17),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'name' => '売上高', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'name' => '販売手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'name' => '荷造運賃', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'other-id', 'name' => 'その他経費', 'tax_id' => 'tax-17', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'name' => '売上原価', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'name' => '商品', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        $mapping = app(FurimaDeckMoneyForwardCsvMappingService::class)->forUser($user, collect([$entry]));

        $this->assertSame('売上高', $mapping['accounts']['sales']);
        $this->assertArrayNotHasKey('shipping', $mapping['invoice_kinds']);
        $this->assertArrayNotHasKey('other', $mapping['invoice_kinds']);
    }

    public function test_exempt_business_csv_mapping_does_not_require_tax_or_invoice_classification(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                static fn (int $index): array => ['id' => 'tax-'.$index, 'name' => '税区分'.$index, 'available' => true],
                range(11, 16),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'name' => '売上高', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'name' => '販売手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'name' => '荷造運賃', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'name' => '仕入高', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'name' => '商品', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['business_tax_periods'][0]['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT;
        $settings['tax_ids'] = array_fill_keys(['settlement', 'sales', 'fees', 'cost', 'inventory'], null);
        $settings['invoice_kinds'] = [];
        $connection->update(['settings' => $settings]);

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'connections']))
            ->assertOk()
            ->assertSee('課税・免税を確認済みとした期間は、明細条件も満たせばCSVを作成できます。')
            ->assertSee('免税確認済みの期間が1件あります。CSVの税区分・インボイス欄は空欄になります。');

        $mapping = app(FurimaDeckMoneyForwardCsvMappingService::class)->forUser($user, collect([$entry->fresh()]));
        $entryDate = $entry->fresh()->transaction_date->format('Y/m/d');
        $this->assertSame(FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT, $mapping['business_status_by_date'][$entryDate]);
        $this->assertSame([], $mapping['invoice_kinds']);
        $this->assertSame('', $mapping['tax_names']['sales']);
    }

    public function test_failed_money_forward_sync_with_prior_journal_evidence_cannot_be_resent(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'failed',
            'external_transaction_id' => 'mf-existing-journal',
        ]);
        Http::fake();

        foreach ([
            ['external_transaction_id' => 'mf-existing-journal', 'synced_at' => null],
            ['external_transaction_id' => null, 'synced_at' => now()],
        ] as $journalEvidence) {
            $sync->update($journalEvidence);

            try {
                app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry->fresh());
                $this->fail('外部仕訳の記録がある失敗状態を再送できてしまいました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('外部仕訳IDまたは同期済み記録', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_exempt_business_without_tax_master_omits_tax_and_invoice_fields(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => []]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '売掛金', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'name' => '売上', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'name' => '手数料', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'name' => '送料', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'name' => '仕入', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'name' => '商品', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-exempt-no-tax-journal']], 201),
        ]);

        app(FurimaDeckMoneyForwardAccountingSetupService::class)->save($user, [
            'account_ids' => [
                'settlement' => 'settlement-id', 'sales' => 'sales-id', 'fees' => 'fees-id',
                'shipping' => 'shipping-id', 'cost' => 'cost-id', 'inventory' => 'inventory-id',
            ],
            'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT]],
            'invoice_kinds' => $this->invoiceKindsFor('INVOICE_KIND_OMIT'),
        ]);

        $savedSettings = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->fresh()->settings;
        $this->assertSame([
            'settlement' => null, 'sales' => null, 'fees' => null,
            'shipping' => null, 'cost' => null, 'inventory' => null,
        ], $savedSettings['tax_ids']);
        $this->assertSame([], app(FurimaDeckMoneyForwardAccountingSyncService::class)->missingConfiguration(
            $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->fresh(),
        ));

        $id = app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);

        $this->assertSame('mf-exempt-no-tax-journal', $id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && $request->data()['journal']['tags'] === []
            && collect($request->data()['journal']['branches'])->every(fn (array $branch): bool => ! array_key_exists('tax_id', $branch['debitor'])
                && ! array_key_exists('invoice_kind', $branch['debitor'])
                && ! array_key_exists('tax_id', $branch['creditor'])
                && ! array_key_exists('invoice_kind', $branch['creditor'])));
    }

    public function test_exempt_business_omits_account_default_tax_ids_when_tax_master_is_empty(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $defaultTaxIds = [
            'settlement' => 'account-tax-settlement',
            'sales' => 'account-tax-sales',
            'fees' => 'account-tax-fees',
            'shipping' => 'account-tax-shipping',
            'cost' => 'account-tax-cost',
            'inventory' => 'account-tax-inventory',
        ];
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => []]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '売掛金', 'tax_id' => $defaultTaxIds['settlement'], 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => $defaultTaxIds['sales'], 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => $defaultTaxIds['fees'], 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => $defaultTaxIds['shipping'], 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => $defaultTaxIds['cost'], 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => $defaultTaxIds['inventory'], 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-exempt-default-tax-journal']], 201),
        ]);

        app(FurimaDeckMoneyForwardAccountingSetupService::class)->save($user, [
            'account_ids' => [
                'settlement' => 'settlement-id', 'sales' => 'sales-id', 'fees' => 'fees-id',
                'shipping' => 'shipping-id', 'cost' => 'cost-id', 'inventory' => 'inventory-id',
            ],
            'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT]],
            'invoice_kinds' => $this->invoiceKindsFor('INVOICE_KIND_OMIT'),
        ]);

        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $this->assertSame([
            'settlement' => null, 'sales' => null, 'fees' => null,
            'shipping' => null, 'cost' => null, 'inventory' => null,
        ], $connection->fresh()->settings['tax_ids']);
        $this->assertSame('mf-exempt-default-tax-journal', app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && collect($request->data()['journal']['branches'])->every(fn (array $branch): bool => ! array_key_exists('tax_id', $branch['debitor'])
                && ! array_key_exists('tax_id', $branch['creditor'])
                && ! array_key_exists('invoice_kind', $branch['debitor'])
                && ! array_key_exists('invoice_kind', $branch['creditor'])));
    }

    public function test_taxable_invoice_kind_cannot_be_saved_without_available_tax_categories(): void
    {
        [$user] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $previousSettings = $connection->settings;
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => []]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        try {
            app(FurimaDeckMoneyForwardAccountingSetupService::class)->save($user, [
                'account_ids' => [
                    'settlement' => 'settlement-id', 'sales' => 'sales-id', 'fees' => 'fees-id',
                    'cost' => 'cost-id', 'inventory' => 'inventory-id',
                ],
                'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
                'invoice_kinds' => $this->invoiceKindsFor(),
            ]);
            $this->fail('課税扱いで税区分が空のまま設定を保存できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('税区分マスタが空です', $exception->getMessage());
        }

        $this->assertSame($previousSettings, $connection->fresh()->settings);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_setup_accepts_business_tax_status_values(): void
    {
        [$user] = $this->entryFixture();
        $accountIds = [
            'settlement' => 'settlement-id',
            'sales' => 'sales-id',
            'fees' => 'fees-id',
            'cost' => 'cost-id',
            'inventory' => 'inventory-id',
        ];

        foreach (FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES as $invoiceKind) {
            $this->actingAs($user)
                ->post(route('furimadeck-accounting.money-forward.setup.save'), [
                    'account_ids' => $accountIds,
                    'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => $invoiceKind]],
                    'invoice_kinds' => $this->invoiceKindsFor(FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED),
                ])
                ->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']))
                ->assertSessionHasNoErrors();

            $this->assertSame(
                $invoiceKind,
                $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->fresh()->settings['business_tax_periods'][0]['invoice_kind'],
            );
        }
    }

    public function test_setup_accepts_only_csv_supported_invoice_kinds(): void
    {
        [$user] = $this->entryFixture();
        $accountIds = [
            'settlement' => 'settlement-id',
            'sales' => 'sales-id',
            'fees' => 'fees-id',
            'cost' => 'cost-id',
            'inventory' => 'inventory-id',
        ];

        foreach (FurimaDeckMoneyForwardInvoiceKindService::CSV_SUPPORTED_KINDS as $csvInvoiceKind) {
            $this->actingAs($user)
                ->post(route('furimadeck-accounting.money-forward.setup.save'), [
                    'account_ids' => $accountIds,
                    'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
                    'invoice_kinds' => $this->invoiceKindsFor($csvInvoiceKind),
                ])
                ->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']))
                ->assertSessionHasNoErrors();
        }

        foreach ([FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET, FurimaDeckMoneyForwardInvoiceKindService::OMIT] as $apiOnlyInvoiceKind) {
            $this->actingAs($user)
                ->post(route('furimadeck-accounting.money-forward.setup.save'), [
                    'account_ids' => $accountIds,
                    'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
                    'invoice_kinds' => $this->invoiceKindsFor($apiOnlyInvoiceKind),
                ])
                ->assertSessionHasErrors([
                    'invoice_kinds.settlement',
                    'invoice_kinds.sales',
                    'invoice_kinds.fees',
                    'invoice_kinds.cost',
                    'invoice_kinds.inventory',
                ]);
        }
    }

    public function test_setup_keeps_legacy_invoice_kinds_unconfirmed_until_business_status_is_verified(): void
    {
        [$user] = $this->entryFixture();
        $accountIds = [
            'settlement' => 'settlement-id',
            'sales' => 'sales-id',
            'fees' => 'fees-id',
            'cost' => 'cost-id',
            'inventory' => 'inventory-id',
        ];

        foreach (FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_LEGACY_VALUES as $legacyStatus) {
            $this->actingAs($user)
                ->post(route('furimadeck-accounting.money-forward.setup.save'), [
                    'account_ids' => $accountIds,
                    'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => $legacyStatus]],
                    'invoice_kinds' => $this->invoiceKindsFor(FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED),
                ])
                ->assertRedirect(route('furimadeck-accounting.index', ['section' => 'connections']))
                ->assertSessionHasNoErrors();

            $this->assertSame(
                FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED,
                $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->fresh()->settings['business_tax_periods'][0]['invoice_kind'],
            );
            $this->assertContains(
                'business_tax_status_confirmation',
                app(FurimaDeckMoneyForwardAccountingSyncService::class)->missingConfiguration(
                    $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->fresh(),
                ),
            );
        }
    }

    public function test_money_forward_setup_shows_a_legacy_invoice_kind_as_unconfirmed(): void
    {
        [$user] = $this->entryFixture();
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['business_tax_periods'][0]['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::QUALIFIED;
        $connection->update(['settings' => $settings]);

        $response = $this->actingAs($user)->get(route('furimadeck-accounting.money-forward.setup'))->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('課税・免税の状態未確認（Money Forward CSV停止）', $content);
        $this->assertStringContainsString('自分の税務上の区分はMoney Forwardの画面表示だけでは判断できません。', $content);
        $this->assertSame(1, preg_match('/<select name="business_tax_periods\[0\]\[invoice_kind\]"[^>]*>(.*?)<\/select>/s', $content, $periodSelect));
        $this->assertStringContainsString('value="BUSINESS_TAX_STATUS_UNCONFIRMED" selected', $periodSelect[1]);
        $this->assertStringNotContainsString('value="INVOICE_KIND_QUALIFIED"', $periodSelect[1]);
    }

    public function test_any_unconfirmed_period_is_reported_by_global_money_forward_readiness(): void
    {
        $roles = ['settlement', 'sales', 'fees', 'cost', 'inventory'];
        $connection = new AccountingConnection(['settings' => [
            'account_ids' => array_fill_keys($roles, 'account-id'),
            'sub_account_ids' => array_fill_keys($roles, null),
            'tax_ids' => array_fill_keys($roles, 'tax-id'),
            'invoice_kinds' => array_fill_keys($roles, FurimaDeckMoneyForwardInvoiceKindService::NOT_TARGET),
            'account_statement_types_checked' => true,
            'business_tax_periods' => [
                ['from' => '2025-01-01', 'to' => '2025-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE],
                ['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED],
            ],
        ]]);

        $missing = app(FurimaDeckMoneyForwardAccountingSyncService::class)->missingConfiguration($connection);

        $this->assertContains('business_tax_status_confirmation', $missing);
        $this->assertNotContains('business_tax_periods', $missing);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', ['--database' => 'furimadeck', '--path' => 'database/migrations/furimadeck'])->assertSuccessful();
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        config()->set('furimadeck.accounting.provider_api_enabled', true);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        config()->set('furimadeck.accounting.oauth.money_forward.accounting_api_base', 'https://api-accounting.test');
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');
        parent::tearDown();
    }

    public function test_csv_pending_entry_cannot_be_sent_to_money_forward(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'csv_pending']);
        Http::fake();

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry->fresh());
            $this->fail('CSV出力済み明細を送信できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('送信済み', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_unconfirmed_purchase_entry_is_blocked_before_money_forward_network_access(): void
    {
        [$user, $entry] = $this->entryFixture(true, 0, false);
        $entry->update([
            'entry_type' => AccountingEntry::TYPE_PURCHASE,
            'product_id' => $entry->sale->product_id,
            'sale_id' => null,
            'source_status' => 'review',
            'confirmed_at' => null,
        ]);
        Http::fake();

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry->fresh());
            $this->fail('未確定の仕入明細をMoney Forwardへ送信できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('確認済み', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, $entry->providerSyncs()->count());
    }

    public function test_confirmed_purchase_entry_builds_inventory_journal_from_evidence_and_selected_payment_source(): void
    {
        [$user, $saleEntry] = $this->entryFixture(true, 0, false);
        $product = $user->products()->findOrFail($saleEntry->sale->product_id);
        $capReview = AccountingPurchaseCapReview::query()->create([
            'user_id' => $user->id,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'counterparty_fingerprint' => app(FurimaDeckInvoiceTransitionCapService::class)->counterpartyFingerprint('免税仕入先'),
            'counterparty_name' => '免税仕入先',
            'external_qualifying_purchase_amount' => 0,
            'reviewed_through' => '2026-12-31',
            'furimadeck_history_complete' => true,
            'external_history_complete' => true,
            'reviewed_at' => now(),
        ]);
        $product->update([
            'purchase_shipping_cost' => 200,
            'other_purchase_expense' => 50,
            'purchase_payment_sources' => ['money_forward' => 'bank-source'],
            'purchase_evidence' => [
                'purchase_unit_cost' => ['document_type' => 'receipt', 'tax_category' => 'standard_10', 'invoice_issuer' => 'qualified', 'date_matches_entry_confirmed' => true, 'reviewed_at' => now()->toIso8601String()],
                'purchase_shipping_cost' => ['document_type' => 'receipt', 'tax_category' => 'reduced_8', 'invoice_issuer' => 'unqualified', 'date_matches_entry_confirmed' => true, 'reviewed_at' => now()->toIso8601String(), 'cap_review_id' => $capReview->id],
                'other_purchase_expense' => ['document_type' => 'invoice', 'tax_category' => 'non_taxable', 'invoice_issuer' => 'not_applicable', 'date_matches_entry_confirmed' => true, 'reviewed_at' => now()->toIso8601String()],
            ],
        ]);
        $purchase = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product->fresh());
        $purchase->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['purchase_payment_accounts'] = [
            'bank-source' => ['account_id' => 'bank-source', 'sub_account_id' => null, 'tax_id' => 'tax-11', 'name' => '普通預金'],
        ];
        $settings['purchase_tax_ids'] = ['standard_10' => 'tax-14', 'reduced_8' => 'tax-16', 'non_taxable' => 'tax-13'];
        $connection->update(['settings' => $settings]);

        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                fn (int $index): array => ['id' => 'tax-'.$index], range(11, 16),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'bank-source', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-purchase-journal']], 201),
        ]);

        $options = app(FurimaDeckMoneyForwardAccountingSetupService::class)->options($user);
        $this->assertContains('bank-source', collect($options['accounts']['accounts'])->pluck('id')->map('strval')->all());
        $this->assertContains('bank-source', app(FurimaDeckMoneyForwardAccountingSetupService::class)->eligibleAccountIds($options['accounts']['accounts'])['purchase_payment_source']);

        $id = app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $purchase->fresh());

        $this->assertSame('mf-purchase-journal', $id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && count($request->data()['journal']['branches']) === 3
            && $request->data()['journal']['branches'][0]['debitor']['value'] === 1000
            && $request->data()['journal']['branches'][0]['debitor']['account_id'] === 'inventory-id'
            && $request->data()['journal']['branches'][0]['debitor']['tax_id'] === 'tax-14'
            && $request->data()['journal']['branches'][0]['debitor']['invoice_kind'] === 'INVOICE_KIND_QUALIFIED'
            && $request->data()['journal']['branches'][0]['creditor']['value'] === 1000
            && $request->data()['journal']['branches'][0]['creditor']['account_id'] === 'bank-source'
            && $request->data()['journal']['branches'][0]['creditor']['invoice_kind'] === 'INVOICE_KIND_NOT_TARGET'
            && $request->data()['journal']['branches'][1]['debitor']['value'] === 200
            && $request->data()['journal']['branches'][1]['debitor']['tax_id'] === 'tax-16'
            && $request->data()['journal']['branches'][1]['debitor']['invoice_kind'] === 'INVOICE_KIND_UNQUALIFIED_80'
            && str_contains($request->data()['journal']['branches'][1]['remark'], '仕入先:免税仕入先')
            && str_contains($request->data()['journal']['branches'][1]['remark'], '80%控除対象')
            && $request->data()['journal']['branches'][2]['debitor']['value'] === 50
            && $request->data()['journal']['branches'][2]['debitor']['tax_id'] === 'tax-13'
            && $request->data()['journal']['branches'][2]['debitor']['invoice_kind'] === 'INVOICE_KIND_NOT_TARGET');
    }

    public function test_antique_dealer_purchase_sends_book_only_special_case_and_keeps_supplier_details_encrypted(): void
    {
        [$user, $saleEntry] = $this->entryFixture(true, 0, false);
        $product = $user->products()->findOrFail($saleEntry->sale->product_id);
        $product->update([
            'purchase_payment_sources' => ['money_forward' => 'bank-source'],
            'purchase_evidence' => [
                'purchase_unit_cost' => ['document_type' => 'receipt', 'tax_category' => 'standard_10', 'invoice_issuer' => 'unqualified', 'date_matches_entry_confirmed' => true, 'reviewed_at' => now()->toIso8601String()],
            ],
            'purchase_special_case_data' => [
                'scheme' => 'antique_dealer',
                'license_number' => '東京都公安委員会 第123456789012号',
                'counterparty_name' => '古物仕入先',
                'counterparty_address' => '東京都千代田区1-2-3',
                'confirmed_at' => now()->toIso8601String(),
                'confirmations' => array_fill_keys(['dealer_license', 'qualifying_used_goods', 'resale_inventory', 'supplier_unqualified', 'not_specific_metal_scrap'], true),
            ],
        ]);
        $purchase = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product->fresh());
        $purchase->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['purchase_payment_accounts'] = [
            'bank-source' => ['account_id' => 'bank-source', 'sub_account_id' => null, 'tax_id' => 'tax-11', 'name' => '普通預金'],
        ];
        $settings['purchase_tax_ids'] = ['standard_10' => 'tax-14'];
        $connection->update(['settings' => $settings]);

        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(fn (int $index): array => ['id' => 'tax-'.$index], range(11, 16))]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'bank-source', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-antique-purchase']], 201),
        ]);

        app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $purchase->fresh());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && ! array_key_exists('invoice_kind', $request->data()['journal']['branches'][0]['debitor'])
            && $request->data()['journal']['branches'][0]['creditor']['invoice_kind'] === 'INVOICE_KIND_NOT_TARGET'
            && str_contains($request->data()['journal']['branches'][0]['remark'], '古物商等特例')
            && str_contains($request->data()['journal']['branches'][0]['remark'], '第123456789012号')
            && str_contains($request->data()['journal']['branches'][0]['remark'], '古物仕入先')
            && str_contains($request->data()['journal']['branches'][0]['remark'], '東京都千代田区1-2-3'));

        $sync = $purchase->providerSync('money_forward')->fresh();
        $this->assertStringContainsString('[暗号化された仕入摘要]', $sync->request_payload['branches'][0]['remark']);
        $this->assertStringNotContainsString('東京都千代田区1-2-3', json_encode($sync->request_payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('東京都千代田区1-2-3', json_encode($sync->sensitive_request_payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_unavailable_payment_source_tax_id_blocks_money_forward_journal(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['purchase_payment_accounts'] = [
            'bank-source' => ['account_id' => 'bank-source', 'sub_account_id' => null, 'tax_id' => 'removed-tax', 'name' => '普通預金'],
        ];
        $connection->update(['settings' => $settings]);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                fn (int $index): array => ['id' => 'tax-'.$index], range(11, 16),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'bank-source', 'name' => '普通預金', 'tax_id' => 'removed-tax', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('Money Forwardの税区分マスタにない支払元税区分で送信してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('有効な税区分マスタにありません', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v3/journals'));
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
    }

    public function test_confirmed_entry_is_sent_to_money_forward_and_marked_synced(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-journal-1']], 201)]);

        $id = app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);

        $this->assertSame('mf-journal-1', $id);
        $this->assertSame('synced', $entry->fresh()->sync_status);
        $this->assertSame(10000, $entry->providerSync('money_forward')->request_payload['branches'][0]['debitor']['value']);
        $this->assertNull($entry->providerSync('money_forward')->verified_at);
        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api-accounting.test/api/v3/journals') {
                return false;
            }
            if (! $request->hasHeader('Content-Type', 'application/json')) {
                return false;
            }
            $journal = $request->data()['journal'];

            return $journal['journal_type'] === 'journal_entry'
                && array_key_exists('tags', $journal)
                && $journal['tags'] === []
                && count($journal['branches']) === 4
                && $journal['branches'][0]['debitor']['value'] === 10000
                && $journal['branches'][0]['debitor']['tax_id'] === 'tax-11'
                && $journal['branches'][0]['creditor']['tax_id'] === 'tax-12'
                && $journal['branches'][1]['debitor']['tax_id'] === 'tax-13'
                && $journal['branches'][1]['creditor']['tax_id'] === 'tax-11'
                && $journal['branches'][2]['debitor']['tax_id'] === 'tax-16'
                && $journal['branches'][2]['debitor']['value'] === 500
                && $journal['branches'][3]['debitor']['tax_id'] === 'tax-14'
                && $journal['branches'][3]['creditor']['tax_id'] === 'tax-15';
        });
    }

    public function test_money_forward_api_uses_invoice_kind_configured_for_each_account_role(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['invoice_kinds'] = [
            'settlement' => 'INVOICE_KIND_NOT_TARGET',
            'sales' => 'INVOICE_KIND_QUALIFIED',
            'fees' => 'INVOICE_KIND_UNQUALIFIED_70',
            'shipping' => 'INVOICE_KIND_UNQUALIFIED_80',
            'cost' => 'INVOICE_KIND_UNQUALIFIED',
            'inventory' => 'INVOICE_KIND_OMIT',
        ];
        $connection->update(['settings' => $settings]);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                fn (int $index): array => ['id' => 'tax-'.$index], range(11, 16),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-role-invoice-kinds']], 201),
        ]);

        app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && $request->data()['journal']['branches'][0]['debitor']['invoice_kind'] === 'INVOICE_KIND_NOT_TARGET'
            && $request->data()['journal']['branches'][0]['creditor']['invoice_kind'] === 'INVOICE_KIND_QUALIFIED'
            && $request->data()['journal']['branches'][1]['debitor']['invoice_kind'] === 'INVOICE_KIND_UNQUALIFIED_70'
            && $request->data()['journal']['branches'][1]['creditor']['invoice_kind'] === 'INVOICE_KIND_NOT_TARGET'
            && $request->data()['journal']['branches'][2]['debitor']['invoice_kind'] === 'INVOICE_KIND_UNQUALIFIED_80'
            && $request->data()['journal']['branches'][3]['debitor']['invoice_kind'] === 'INVOICE_KIND_UNQUALIFIED'
            && ! array_key_exists('invoice_kind', $request->data()['journal']['branches'][3]['creditor']));
    }

    public function test_ambiguous_http_responses_require_verification_before_retry(): void
    {
        foreach ([408, 425, 429] as $status) {
            [$user, $entry] = $this->entryFixture();
            Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response(['errors' => [['code' => 'TRANSIENT']]], $status)]);

            try {
                app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
                $this->fail('曖昧なHTTP応答は二重登録防止のため要照合になる必要があります。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('応答が不確定', $exception->getMessage());
            }

            $sync = $entry->providerSync('money_forward')->fresh();
            $this->assertSame('sync_unknown', $sync->status);
            $this->assertSame(1, $sync->attempt_count);
        }
    }

    public function test_journal_401_refreshes_token_and_retries_the_same_entry_only_once(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.oauth.money_forward', [
            'client_id' => 'mf-client',
            'client_secret' => 'mf-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/money-forward',
            'token_url' => 'https://api.biz.test/token',
            'api_base' => 'https://api.biz.test',
            'accounting_api_base' => 'https://api-accounting.test',
            'scope' => 'mfc/accounting.read mfc/accounting.write',
        ]);
        $journalAttempts = 0;
        $tokenRefreshes = 0;
        Http::fake(function ($request) use (&$journalAttempts, &$tokenRefreshes) {
            if ($request->url() === 'https://api.biz.test/token') {
                $tokenRefreshes++;

                return Http::response(['access_token' => 'mf-new-access', 'refresh_token' => 'mf-new-refresh', 'expires_in' => 3600]);
            }
            if ($request->url() === 'https://api.biz.test/v2/tenant') {
                return Http::response(['tenant_code' => 'test-tenant']);
            }
            if (str_starts_with($request->url(), 'https://api-accounting.test/api/v3/taxes')) {
                return Http::response(['taxes' => array_map(fn (int $index): array => ['id' => 'tax-'.$index], range(11, 16))]);
            }
            if (str_starts_with($request->url(), 'https://api-accounting.test/api/v3/accounts')) {
                return Http::response(['accounts' => [
                    ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                    ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ]]);
            }
            if ($request->url() === 'https://api-accounting.test/api/v3/journals' && $request->method() === 'POST') {
                $journalAttempts++;

                return $journalAttempts === 1
                    ? Http::response(['errors' => []], 401)
                    : Http::response(['journal' => ['id' => 'mf-refreshed-journal']], 201);
            }

            return Http::response([], 500);
        });

        $externalId = app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);

        $sync = $entry->providerSync('money_forward');
        $this->assertSame('mf-refreshed-journal', $externalId);
        $this->assertSame('synced', $sync->status);
        $this->assertSame('mf-refreshed-journal', $sync->external_transaction_id);
        $this->assertSame(1, $sync->attempt_count);
        $this->assertSame(2, $journalAttempts);
        $this->assertSame(1, $tokenRefreshes);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && $request->hasHeader('Authorization', 'Bearer mf-new-access'));
    }

    public function test_failed_token_refresh_after_journal_401_does_not_retry_or_mark_result_unknown(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.oauth.money_forward', [
            'client_id' => 'mf-client',
            'client_secret' => 'mf-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/money-forward',
            'token_url' => 'https://api.biz.test/token',
            'api_base' => 'https://api.biz.test',
            'accounting_api_base' => 'https://api-accounting.test',
            'scope' => 'mfc/accounting.read mfc/accounting.write',
        ]);
        $journalAttempts = 0;
        Http::fake(function ($request) use (&$journalAttempts) {
            if ($request->url() === 'https://api.biz.test/token') {
                return Http::response(['error' => 'invalid_grant'], 400);
            }
            if ($request->url() === 'https://api.biz.test/v2/tenant') {
                return Http::response(['tenant_code' => 'test-tenant']);
            }
            if (str_starts_with($request->url(), 'https://api-accounting.test/api/v3/taxes')) {
                return Http::response(['taxes' => array_map(fn (int $index): array => ['id' => 'tax-'.$index], range(11, 16))]);
            }
            if (str_starts_with($request->url(), 'https://api-accounting.test/api/v3/accounts')) {
                return Http::response(['accounts' => [
                    ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                    ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ]]);
            }
            if ($request->url() === 'https://api-accounting.test/api/v3/journals' && $request->method() === 'POST') {
                $journalAttempts++;

                return Http::response(['errors' => []], 401);
            }

            return Http::response([], 500);
        });

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('認証更新に失敗した場合は仕訳再送せずに失敗する必要があります。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('仕訳は再送していません', $exception->getMessage());
        }

        $sync = $entry->providerSync('money_forward');
        $this->assertSame('failed', $sync->status);
        $this->assertNull($sync->external_transaction_id);
        $this->assertSame(1, $sync->attempt_count);
        $this->assertSame(1, $journalAttempts);
    }

    public function test_shipping_is_not_sent_as_a_fee_when_its_account_is_missing(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        unset($settings['account_ids']['shipping'], $settings['sub_account_ids']['shipping'], $settings['tax_ids']['shipping']);
        $connection->update(['settings' => $settings]);

        $this->expectExceptionMessage('shipping');
        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
        } finally {
            Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v3/journals'));
        }
    }

    public function test_other_expense_uses_its_own_account_in_the_journal(): void
    {
        [$user, $entry] = $this->entryFixture(false, 200);
        $detail = [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'qualified',
            'date_matches_entry_confirmed' => true,
            'reviewed_at' => now()->toIso8601String(),
            'payment_sources' => ['money_forward' => 'bank-source'],
        ];
        $entry->sale->update(['accounting_expense_details' => ['packing_cost' => $detail]]);
        $breakdown = (array) $entry->expense_breakdown;
        $breakdown['sale_expenses']['packing_cost'] = array_intersect_key($detail, array_flip(['document_type', 'tax_category', 'invoice_issuer', 'date_matches_entry_confirmed', 'reviewed_at']));
        $entry->update(['expense_breakdown' => $breakdown]);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['account_ids']['other'] = 'other-id';
        $settings['sub_account_ids']['other'] = null;
        $settings['tax_ids']['other'] = 'tax-17';
        $settings['purchase_tax_ids']['standard_10'] = 'tax-17';
        $settings['purchase_payment_accounts']['bank-source'] = [
            'account_id' => 'bank-source', 'sub_account_id' => null, 'tax_id' => 'tax-11', 'name' => '普通預金',
        ];
        $connection->update(['settings' => $settings]);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                fn (int $index): array => ['id' => 'tax-'.$index], range(11, 17),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'other-id', 'tax_id' => 'tax-17', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'bank-source', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-other']], 201),
        ]);

        app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && collect($request->data()['journal']['branches'])->contains(fn ($branch): bool => $branch['debitor']['account_id'] === 'other-id'
                && $branch['debitor']['tax_id'] === 'tax-17'
                && $branch['debitor']['invoice_kind'] === 'INVOICE_KIND_QUALIFIED'
                && $branch['creditor']['account_id'] === 'bank-source'
                && $branch['creditor']['tax_id'] === 'tax-11'
                && $branch['creditor']['invoice_kind'] === 'INVOICE_KIND_NOT_TARGET'
                && $branch['debitor']['value'] === 200));
    }

    public function test_exempt_business_sale_expense_omits_tax_and_invoice_fields_with_tax_master_available(): void
    {
        [$user, $entry] = $this->entryFixture(false, 200);
        $detail = [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'qualified',
            'date_matches_entry_confirmed' => true,
            'reviewed_at' => now()->toIso8601String(),
            'payment_sources' => ['money_forward' => 'bank-source'],
        ];
        $entry->sale->update(['accounting_expense_details' => ['packing_cost' => $detail]]);
        $breakdown = (array) $entry->expense_breakdown;
        $breakdown['sale_expenses']['packing_cost'] = array_intersect_key($detail, array_flip(['document_type', 'tax_category', 'invoice_issuer', 'date_matches_entry_confirmed', 'reviewed_at']));
        $entry->update(['expense_breakdown' => $breakdown]);

        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['account_ids']['other'] = 'other-id';
        $settings['sub_account_ids']['other'] = null;
        $settings['tax_ids']['other'] = 'tax-17';
        $settings['purchase_tax_ids']['standard_10'] = 'tax-17';
        $settings['purchase_payment_accounts']['bank-source'] = [
            'account_id' => 'bank-source', 'sub_account_id' => null, 'tax_id' => 'tax-11', 'name' => '普通預金',
        ];
        $settings['business_tax_periods'][0]['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT;
        $settings['invoice_kinds'] = $this->invoiceKindsFor('INVOICE_KIND_OMIT');
        $connection->update(['settings' => $settings]);

        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                fn (int $index): array => ['id' => 'tax-'.$index], range(11, 17),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'other-id', 'tax_id' => 'tax-17', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'bank-source', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-exempt-expense-journal']], 201),
        ]);

        $this->assertSame('mf-exempt-expense-journal', app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && collect($request->data()['journal']['branches'])->contains(fn (array $branch): bool => $branch['debitor']['account_id'] === 'other-id'
                && $branch['creditor']['account_id'] === 'bank-source'
                && $branch['debitor']['value'] === 200
                && ! array_key_exists('tax_id', $branch['debitor'])
                && ! array_key_exists('invoice_kind', $branch['debitor'])
                && ! array_key_exists('tax_id', $branch['creditor'])
                && ! array_key_exists('invoice_kind', $branch['creditor'])));
    }

    public function test_money_forward_csv_blocks_itemized_expense_with_unrepresentable_payment_invoice_kind(): void
    {
        [$user, $entry] = $this->entryFixture(false, 200);
        $detail = [
            'document_type' => 'receipt',
            'tax_category' => 'standard_10',
            'invoice_issuer' => 'qualified',
            'date_matches_entry_confirmed' => true,
            'reviewed_at' => now()->toIso8601String(),
            'payment_sources' => ['money_forward' => 'bank-source'],
        ];
        $entry->sale->update(['accounting_expense_details' => ['packing_cost' => $detail]]);
        $breakdown = (array) $entry->expense_breakdown;
        $breakdown['sale_expenses']['packing_cost'] = array_intersect_key($detail, array_flip(['document_type', 'tax_category', 'invoice_issuer', 'date_matches_entry_confirmed', 'reviewed_at']));
        $entry->update(['expense_breakdown' => $breakdown]);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['account_ids']['other'] = 'other-id';
        $settings['sub_account_ids']['other'] = null;
        $settings['tax_ids']['other'] = 'tax-17';
        $settings['purchase_tax_ids']['standard_10'] = 'tax-17';
        $settings['purchase_payment_accounts']['bank-source'] = [
            'account_id' => 'bank-source', 'sub_account_id' => null, 'tax_id' => 'tax-11', 'name' => '普通預金',
        ];
        $connection->update(['settings' => $settings]);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                fn (int $index): array => ['id' => 'tax-'.$index], range(11, 17),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'name' => '売上高', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'name' => '支払手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'name' => '荷造運賃', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'other-id', 'name' => 'その他経費', 'tax_id' => 'tax-17', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'name' => '売上原価', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'name' => '商品', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'bank-source', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('別途支払った費用に必要なインボイス区分を正確に表現できません');
        try {
            app(FurimaDeckMoneyForwardCsvMappingService::class)->forUser($user, collect([$entry]));
        } finally {
            Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v3/journals'));
        }
    }

    public function test_money_forward_transaction_id_without_journal_id_is_not_treated_as_verified_send(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['transaction_id' => 'transaction-only']], 201)]);

        $this->expectExceptionMessage('仕訳IDを確認できません');
        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
        } finally {
            $this->assertSame('sync_unknown', $entry->providerSync('money_forward')->status);
            $this->assertNotNull($entry->providerSync('money_forward')->request_payload);
        }
    }

    public function test_legacy_confirm_without_per_entry_review_cannot_send_or_queue_to_money_forward(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['confirmed_at' => null]);
        Http::fake();
        Queue::fake();

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry->fresh());
            $this->fail('Legacy bulk confirmation must not authorize an external journal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('明細ごとに確認済み', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->actingAs($user)->post(route('furimadeck-accounting.send.money-forward.batch'), ['year' => 2026])
            ->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_sale_with_unreconciled_purchase_is_blocked_before_money_forward_network_access(): void
    {
        [$user, $entry] = $this->entryFixture(true, 0, false);
        Http::fake();

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('仕入未照合の商品をMoney Forwardへ送信できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('この商品の仕入会計明細が見つかりません', $exception->getMessage());
            $this->assertStringContainsString('この売上CSVは保留です', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseMissing('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'money_forward',
        ], 'furimadeck');
    }

    public function test_money_forward_purchase_external_id_without_content_verification_does_not_unblock_sale(): void
    {
        [$user, $entry] = $this->entryFixture();
        $purchase = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        $purchase->providerSyncs()->where('provider', 'money_forward')->update(['verified_at' => null]);
        Http::fake();

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('外部仕入IDだけで内容未照合なのにMoney Forward売上を送信できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Money Forward側で未照合', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_batch_does_not_queue_sales_with_unreconciled_purchases(): void
    {
        [$user] = $this->entryFixture(true, 0, false);
        Http::fake();
        Queue::fake();

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.send.money-forward.batch'), ['year' => 2026])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, '未照合明細は保留中です'));

        $this->assertSame(0, AccountingSyncBatch::query()->count());
        $this->assertSame(0, $user->accountingSyncBatches()->count());
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_SALE)
            ->whereHas('providerSyncs', fn ($query) => $query->where('provider', 'money_forward'))->count());
        Queue::assertNothingPushed();
    }

    public function test_cancelled_or_returned_sale_cannot_send_a_previously_confirmed_entry(): void
    {
        foreach (['cancelled', 'returned'] as $status) {
            [$user, $entry] = $this->entryFixture();
            $entry->sale()->update(['status' => $status]);
            Http::fake();

            try {
                app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
                $this->fail('A '.$status.' sale must not create an external journal.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('売上記録と会計データが一致しません', $exception->getMessage());
            }

            Http::assertNothingSent();
            $this->assertDatabaseMissing('accounting_entry_syncs', [
                'accounting_entry_id' => $entry->id,
                'provider' => 'money_forward',
            ], 'furimadeck');
        }
    }

    public function test_legacy_single_tax_id_does_not_satisfy_per_account_tax_configuration(): void
    {
        $legacyConnection = new AccountingConnection(['settings' => [
            'account_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'cost' => '4', 'inventory' => '5'],
            'sub_account_ids' => ['settlement' => null, 'sales' => null, 'fees' => null, 'cost' => null, 'inventory' => null],
            'tax_id' => 'legacy-tax-id',
        ]]);

        $missing = app(FurimaDeckMoneyForwardAccountingSyncService::class)->missingConfiguration($legacyConnection);

        $this->assertSame([
            'business_tax_periods',
            'tax_ids.settlement',
            'tax_ids.sales',
            'tax_ids.fees',
            'tax_ids.cost',
            'tax_ids.inventory',
            'account_statement_types_check',
            'invoice_kinds.settlement',
            'invoice_kinds.sales',
            'invoice_kinds.fees',
            'invoice_kinds.cost',
            'invoice_kinds.inventory',
        ], $missing);
    }

    public function test_legacy_global_invoice_kind_cannot_be_assumed_for_an_entry_period(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        unset($settings['business_tax_periods']);
        $connection->update(['settings' => $settings]);
        Http::fake();

        $this->assertContains('business_tax_periods', app(FurimaDeckMoneyForwardAccountingSyncService::class)->missingConfiguration($connection->fresh()));
        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('インボイス区分を推定して送信してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('取引日を含む課税・免税の確認期間', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
    }

    public function test_changed_money_forward_tax_setting_blocks_journal_before_post(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                fn (int $index): array => ['id' => 'tax-'.$index],
                range(11, 16),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => 'tax-13', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('保存後に変更された税区分で仕訳を送信してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('保存時から変わりました', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v3/journals'));
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
        $this->assertDatabaseMissing('accounting_entry_syncs', ['accounting_entry_id' => $entry->id, 'provider' => 'money_forward'], 'furimadeck');
    }

    public function test_money_forward_master_lookup_failure_blocks_journal_before_post(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        Http::fake(['https://api-accounting.test/api/v3/taxes*' => Http::response([], 503)]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('会計マスタを確認できなければ仕訳を送信してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('会計マスタ取得に失敗', $exception->getMessage());
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v3/journals'));
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
        $this->assertDatabaseMissing('accounting_entry_syncs', ['accounting_entry_id' => $entry->id, 'provider' => 'money_forward'], 'furimadeck');
    }

    public function test_previous_account_settings_cannot_send_without_statement_type_check(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        unset($settings['account_statement_types_checked']);
        $connection->update(['settings' => $settings]);
        Http::fake();

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('Unverified account roles must not be sent.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Money Forwardの仕訳設定が不足しています。', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
    }

    public function test_money_forward_setup_uses_tax_ids_from_selected_account_defaults(): void
    {
        [$user] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => [
                ['id' => 'tax-11', 'name' => '対象外'], ['id' => 'tax-12', 'name' => '課税売上10%'], ['id' => 'tax-13', 'name' => '課対仕入10%'],
            ]]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'a1', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'a2', 'name' => '売上', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a3', 'name' => '手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a6', 'name' => '送料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a7', 'name' => 'その他費用', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a4', 'name' => '仕入', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a5', 'name' => '商品', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        $this->actingAs($user)->get(route('furimadeck-accounting.money-forward.setup'))
            ->assertOk()
            ->assertSee('送料')
            ->assertSee('その他費用');

        app(FurimaDeckMoneyForwardAccountingSetupService::class)->save($user, [
            'account_ids' => ['settlement' => 'a1', 'sales' => 'a2', 'fees' => 'a3', 'shipping' => 'a6', 'other' => 'a7', 'cost' => 'a4', 'inventory' => 'a5'],
            'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
            'invoice_kinds' => $this->invoiceKindsFor(),
        ]);

        $this->assertSame(['settlement' => 'tax-11', 'sales' => 'tax-12', 'fees' => 'tax-13', 'shipping' => 'tax-13', 'other' => 'tax-13', 'cost' => 'tax-13', 'inventory' => 'tax-11'], $connection->fresh()->settings['tax_ids']);
        $this->assertTrue($connection->fresh()->settings['account_statement_types_checked']);
    }

    public function test_setup_rejects_asset_account_selected_for_sales_without_changing_settings(): void
    {
        [$user] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $previousSettings = $connection->settings;
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => [['id' => 'tax-11', 'name' => '対象外']]]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'a1', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'a2', 'name' => '貯蔵品', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'a3', 'name' => '支払手数料', 'tax_id' => 'tax-11', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a4', 'name' => '仕入高', 'tax_id' => 'tax-11', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a5', 'name' => '商品', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        try {
            app(FurimaDeckMoneyForwardAccountingSetupService::class)->save($user, [
                'account_ids' => ['settlement' => 'a1', 'sales' => 'a2', 'fees' => 'a3', 'cost' => 'a4', 'inventory' => 'a5'],
                'invoice_kind' => 'INVOICE_KIND_NOT_TARGET',
                'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
                'invoice_kinds' => $this->invoiceKindsFor(),
            ]);
            $this->fail('An asset account must not be accepted as sales.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('入力欄と合いません', $exception->getMessage());
        }

        $this->assertSame($previousSettings, $connection->fresh()->settings);
    }

    public function test_setup_rejects_a_bank_account_as_sale_settlement_without_changing_settings(): void
    {
        [$user] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $previousSettings = $connection->settings;
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => [['id' => 'tax-11', 'name' => '対象外']]]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'bank', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'receivable', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales', 'name' => '売上高', 'tax_id' => 'tax-11', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees', 'name' => '支払手数料', 'tax_id' => 'tax-11', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost', 'name' => '仕入高', 'tax_id' => 'tax-11', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory', 'name' => '商品', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
        ]);

        try {
            app(FurimaDeckMoneyForwardAccountingSetupService::class)->save($user, [
                'account_ids' => ['settlement' => 'bank', 'sales' => 'sales', 'fees' => 'fees', 'cost' => 'cost', 'inventory' => 'inventory'],
                'purchase_payment_account_ids_present' => '1',
                'purchase_payment_account_ids' => [],
                'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED]],
            ]);
            $this->fail('銀行科目を売上の借方に保存してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('売上借方には「売掛金」', $exception->getMessage());
        }

        $this->assertSame($previousSettings, $connection->fresh()->settings);
    }

    public function test_selected_sub_account_is_sent_with_its_parent_account_and_own_tax(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => [
                ['id' => 'tax-11', 'name' => '対象外'], ['id' => 'tax-12', 'name' => '課税売上'], ['id' => 'tax-13', 'name' => '課対仕入'],
            ]]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'a1', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'a2', 'name' => '売上高', 'tax_id' => 'tax-11', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS', 'sub_accounts' => [['id' => 'sub-sale', 'name' => '販売売上', 'tax_id' => 'tax-12']]],
                ['id' => 'a3', 'name' => '手数料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a6', 'name' => '送料', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a4', 'name' => '仕入高', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'a5', 'name' => '商品', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'journal-sub']], 201),
        ]);

        app(FurimaDeckMoneyForwardAccountingSetupService::class)->save($user, [
            'account_ids' => ['settlement' => 'a1', 'sales' => 'sub-sale', 'fees' => 'a3', 'shipping' => 'a6', 'cost' => 'a4', 'inventory' => 'a5'],
            'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
            'invoice_kinds' => $this->invoiceKindsFor(),
        ]);
        $settings = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail()->settings;
        $this->assertSame('a2', $settings['account_ids']['sales']);
        $this->assertSame('sub-sale', $settings['sub_account_ids']['sales']);
        $this->assertSame('tax-12', $settings['tax_ids']['sales']);

        app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && $request->data()['journal']['branches'][0]['creditor']['account_id'] === 'a2'
            && $request->data()['journal']['branches'][0]['creditor']['sub_account_id'] === 'sub-sale'
            && $request->data()['journal']['branches'][0]['creditor']['tax_id'] === 'tax-12');
    }

    public function test_disabled_batch_does_not_queue_or_send_entries(): void
    {
        [$user] = $this->entryFixture();
        config()->set('furimadeck.accounting.direct_journal_send_enabled', false);
        Queue::fake();
        Http::fake();

        $this->actingAs($user)->post(route('furimadeck-accounting.send.money-forward.batch'), ['year' => 2026])->assertNotFound();
        $this->assertSame(0, AccountingSyncBatch::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_direct_money_forward_service_send_stays_disabled_when_release_gate_is_closed(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.provider_api_enabled', false);
        config()->set('furimadeck.accounting.direct_journal_send_enabled', true);
        Http::fake();

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('FurimaDeck 1.0ではMoney Forwardへ仕訳をAPI送信できてはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1.0', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_freee_and_money_forward_keep_independent_send_history_for_the_same_entry(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.freee_sync', [
            'api_base' => 'https://api.freee.test',
            'company_id' => '123',
            'tax_codes' => ['settlement' => '11', 'sales' => '12', 'fees' => '13', 'shipping' => '16', 'cost' => '14', 'inventory' => '15'],
            'account_item_ids' => ['settlement' => '1', 'sales' => '2', 'fees' => '3', 'shipping' => '6', 'cost' => '4', 'inventory' => '5'],
        ]);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'freee-access',
            'refresh_token' => 'freee-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
        ]);
        Http::fake([
            'https://api.freee.test/api/1/account_items*' => Http::response(['account_items' => [
                ['id' => 1, 'default_tax_code' => 11],
                ['id' => 2, 'default_tax_code' => 12],
                ['id' => 3, 'default_tax_code' => 13],
                ['id' => 4, 'default_tax_code' => 14],
                ['id' => 5, 'default_tax_code' => 15],
                ['id' => 6, 'default_tax_code' => 16],
            ]]),
            'https://api.freee.test/api/1/manual_journals' => Http::response(['manual_journal' => ['id' => 'freee-1']], 201),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-1']], 201),
        ]);

        app(FurimaDeckFreeeAccountingSyncService::class)->send($user, $entry);
        app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);

        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
            'status' => 'synced',
            'external_transaction_id' => 'freee-1',
        ], 'furimadeck');
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-1',
        ], 'furimadeck');
        $this->assertSame('freee-1', $entry->fresh()->external_transaction_id, 'Legacy external ID is preserved instead of being overwritten by the second provider.');
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('以前の登録記録あり・確認が必要');
    }

    public function test_money_forward_read_back_verifies_saved_payload_and_keeps_mismatch_blocked(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10', 'journal_type' => 'journal_entry', 'memo' => 'FurimaDeck #'.$entry->management_id,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward', 'status' => 'synced', 'external_transaction_id' => 'mf-123',
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection(AccountingConnection::query()->where('user_id', $user->id)->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        $mismatched = $journal;
        $mismatched['branches'][0]['debitor']['value'] = 101;
        Http::fakeSequence()->push(['journal' => ['id' => 'mf-123', ...$journal]])
            ->push(['journal' => ['id' => 'mf-123', ...$mismatched]]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('success');
        $this->assertNotNull($sync->fresh()->verified_at);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api-accounting.test/api/v3/journals/mf-123');

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('error');
        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame('external_content_mismatch:branches', $sync->fresh()->last_error);
        $this->assertSame('synced', $sync->fresh()->status);
    }

    public function test_legacy_unscoped_money_forward_journal_is_bound_only_after_attestation_and_exact_read_back(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-legacy-unscoped',
            'request_payload' => $journal,
            'verified_at' => now(),
        ]);
        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('送信時の事業者IDが保存されていないため、通常の自動照合は停止しています。')
            ->assertSeeText('登録先の確認が必要')
            ->assertSeeText('過去の照合日時（事業者未紐付け）')
            ->assertSee('確認した事業者で読み戻す');
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-legacy-unscoped' => Http::response([
                'journal' => ['id' => 'mf-legacy-unscoped', ...$journal],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']), [
            'bind_legacy_scope' => '1',
            'confirm_current_account' => '1',
            'confirm_external_transaction_id' => '1',
            'confirm_read_only' => '1',
        ])->assertSessionHas('success');

        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $this->assertSame(
            app(FurimaDeckAccountingProviderScopeService::class)->fingerprintForConnection($connection, 'money_forward'),
            $sync->fresh()->provider_account_scope_hash,
        );
        $this->assertSame('synced', $sync->fresh()->status);
        $this->assertNotNull($sync->fresh()->verified_at);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-accounting.test/api/v3/journals/mf-legacy-unscoped');
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_money_forward_read_back_preserves_percent_encoded_opaque_journal_ids(): void
    {
        [$user, $entry] = $this->entryFixture();
        $externalId = 'opaque%2Bjournal%3D';
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => $externalId,
            'request_payload' => $journal,
        ]);
        Http::fake([
            'https://api-accounting.test/api/v3/journals/opaque%252Bjournal%253D' => Http::response([
                'journal' => ['id' => $externalId, ...$journal],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']), [
            'bind_legacy_scope' => '1',
            'confirm_current_account' => '1',
            'confirm_external_transaction_id' => '1',
            'confirm_read_only' => '1',
        ])->assertSessionHas('success');

        $this->assertSame($externalId, $sync->fresh()->external_transaction_id);
        $this->assertNotNull($sync->fresh()->verified_at);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-accounting.test/api/v3/journals/opaque%252Bjournal%253D');
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_legacy_unscoped_read_back_requires_every_user_attestation_before_network_access(): void
    {
        [$user, $entry] = $this->entryFixture();
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-unscoped-no-attestation',
            'request_payload' => ['transaction_date' => '2026-01-10'],
        ]);
        Http::preventStrayRequests();

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']), [
            'bind_legacy_scope' => '1',
            'confirm_current_account' => '1',
            'confirm_external_transaction_id' => '1',
        ])->assertSessionHasErrors('confirm_read_only');

        $this->assertNull($sync->fresh()->provider_account_scope_hash);
        $this->assertNull($sync->fresh()->verified_at);
        Http::assertNothingSent();
    }

    public function test_legacy_unscoped_mismatch_does_not_bind_current_money_forward_tenant(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-unscoped-mismatch',
            'request_payload' => $journal,
        ]);
        $mismatch = $journal;
        $mismatch['branches'][0]['debitor']['value'] = 101;
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-unscoped-mismatch' => Http::response([
                'journal' => ['id' => 'mf-unscoped-mismatch', ...$mismatch],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']), [
            'bind_legacy_scope' => '1',
            'confirm_current_account' => '1',
            'confirm_external_transaction_id' => '1',
            'confirm_read_only' => '1',
        ])->assertSessionHas('error');

        $this->assertNull($sync->fresh()->provider_account_scope_hash);
        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame('synced', $sync->fresh()->status);
    }

    public function test_legacy_unscoped_invoice_default_binds_business_but_keeps_invoice_classification_unverified(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax'],
            ]],
        ];
        $remote = $journal;
        $remote['branches'][0]['debitor']['invoice_kind'] = FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET;
        $remote['branches'][0]['creditor']['invoice_kind'] = FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET;
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-unscoped-default-tax-kind',
            'request_payload' => $journal,
        ]);
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-unscoped-default-tax-kind' => Http::response([
                'journal' => ['id' => 'mf-unscoped-default-tax-kind', ...$remote],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']), [
            'bind_legacy_scope' => '1',
            'confirm_current_account' => '1',
            'confirm_external_transaction_id' => '1',
            'confirm_read_only' => '1',
        ])->assertSessionHas('error', '送信した仕訳内容は一致し、現在の事業者を記録しました。ただし、送信していないインボイス区分「対象外」が返ったため、正しい区分か確認できず未照合のままです。登録済み仕訳の修正・再送は行っていません。');

        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $freshSync = $sync->fresh();
        $this->assertSame(
            app(FurimaDeckAccountingProviderScopeService::class)->fingerprintForConnection($connection, 'money_forward'),
            $freshSync->provider_account_scope_hash,
        );
        $this->assertSame(AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED, $freshSync->last_error);
        $this->assertNull($freshSync->verified_at);
        $this->assertSame('synced', $freshSync->status);
    }

    public function test_legacy_unscoped_read_back_does_not_bind_if_tenant_changes_during_the_read(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-unscoped-race',
            'request_payload' => $journal,
        ]);
        Http::fake(function () use ($connection, $journal) {
            $connection->update(['external_account_id' => 'tenant-changed-during-read']);

            return Http::response(['journal' => ['id' => 'mf-unscoped-race', ...$journal]]);
        });

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']), [
            'bind_legacy_scope' => '1',
            'confirm_current_account' => '1',
            'confirm_external_transaction_id' => '1',
            'confirm_read_only' => '1',
        ])->assertSessionHas('error');

        $this->assertNull($sync->fresh()->provider_account_scope_hash);
        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame('synced', $sync->fresh()->status);
    }

    public function test_money_forward_read_back_refuses_same_journal_id_after_tenant_switch(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = AccountingConnection::query()->where('user_id', $user->id)->where('provider', 'money_forward')->firstOrFail();
        $payload = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'same-id-on-both-tenants',
            'request_payload' => $payload,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'money_forward'),
        ]);
        $connection->update(['external_account_id' => 'tenant-after-reconnect']);
        Http::preventStrayRequests();

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('error');

        $this->assertNull($sync->fresh()->verified_at);
        Http::assertNothingSent();
    }

    public function test_freee_read_back_refuses_same_journal_id_after_company_switch(): void
    {
        [$user, $entry] = $this->entryFixture();
        $connection = AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'freee-access',
            'refresh_token' => 'freee-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => ['company_id' => '12'],
        ]);
        $payload = [
            'company_id' => 12,
            'issue_date' => '2026-01-10',
            'adjustment' => false,
            'ref_number' => $entry->management_id,
            'details' => [
                ['entry_side' => 'debit', 'account_item_id' => 1, 'tax_code' => 2, 'amount' => 100, 'description' => 'sale'],
                ['entry_side' => 'credit', 'account_item_id' => 3, 'tax_code' => 4, 'amount' => 100, 'description' => 'sale'],
            ],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'synced',
            'external_transaction_id' => 'same-journal-id-on-both-companies',
            'request_payload' => $payload,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'freee'),
        ]);
        $connection->update(['settings' => ['company_id' => '13']]);
        Http::preventStrayRequests();

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'freee']))
            ->assertSessionHas('error');

        $this->assertNull($sync->fresh()->verified_at);
        Http::assertNothingSent();
    }

    public function test_money_forward_verification_uses_encrypted_remark_without_exposing_it_in_public_payload(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => '古物商等特例 / 仕入先:本人確認済の仕入先 / 住所:東京都内',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
            ]],
        ];
        $publicPayload = $journal;
        $publicPayload['branches'][0]['remark'] = '[暗号化された仕入摘要]';
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-private-remark',
            'request_payload' => $publicPayload,
            'sensitive_request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection(AccountingConnection::query()->where('user_id', $user->id)->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-private-remark' => Http::response([
                'journal' => ['id' => 'mf-private-remark', ...$journal],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('success');

        $freshSync = $sync->fresh();
        $this->assertNotNull($freshSync->verified_at);
        $this->assertSame($journal, $freshSync->accountingPayload());
        $this->assertSame($publicPayload, $freshSync->request_payload);
        $this->assertStringNotContainsString('東京都内', (string) $freshSync->getRawOriginal('sensitive_request_payload'));
        $this->assertArrayNotHasKey('sensitive_request_payload', $freshSync->toArray());
    }

    public function test_returned_sale_snapshot_can_be_reconciled_and_verified_without_resending(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->sale->update(['status' => 'returned', 'returned_at' => now()]);
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'sync_unknown',
            'last_error' => 'network_error',
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection(AccountingConnection::query()->where('user_id', $user->id)->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-returned' => Http::response([
                'journal' => ['id' => 'mf-returned', ...$journal],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'mark_synced',
            'external_transaction_id' => 'mf-returned',
        ])->assertSessionHas('success');

        $this->assertSame('synced', $sync->fresh()->status);
        $this->assertSame('returned', $entry->sale->fresh()->status);
        $this->assertNotNull($sync->fresh()->verified_at);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('success');
        $this->assertSame('synced', $sync->fresh()->status);
        $this->assertSame('returned', $entry->sale->fresh()->status);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_confirmed_absence_after_return_is_saved_as_not_resendable_and_remains_visible(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->sale->update(['status' => 'returned', 'returned_at' => now()]);
        $connection = AccountingConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', 'money_forward')
            ->firstOrFail();
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'sync_unknown',
            'last_error' => 'network_error',
            'request_payload' => ['transaction_date' => '2026-01-10'],
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'money_forward'),
        ]);
        Http::fake();

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'retry',
            'verified_absent' => '1',
        ])->assertSessionHas('success', '会計サービスに仕訳がないことを記録しました。売上は取消・返品済みのため再送しません。');

        $this->assertSame('not_synced', $sync->fresh()->status);
        $this->assertSame(AccountingEntrySync::VERIFIED_ABSENT_INACTIVE_SALE, $sync->fresh()->last_error);
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
        Http::assertNothingSent();

        $this->actingAs($user)->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('登録なし確認済み・再出力なし')
            ->assertSee('取消・返品済みのため再出力しません')
            ->assertDontSee('Money Forwardへ送信');
    }

    public function test_money_forward_read_back_rejects_and_identifies_provider_defaults_for_omitted_exempt_fields(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-exempt-123',
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        $remote = $journal;
        $remote['branches'][0]['debitor'] += [
            'tax_id' => 'provider-default-debit-tax',
            'sub_account_id' => 'provider-default-debit-sub-account',
            'invoice_kind' => 'INVOICE_KIND_NOT_TARGET',
        ];
        $remote['branches'][0]['creditor'] += [
            'tax_id' => 'provider-default-credit-tax',
            'invoice_kind' => 'INVOICE_KIND_NOT_TARGET',
        ];
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-exempt-123' => Http::response([
                'journal' => ['id' => 'mf-exempt-123', ...$remote],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('error');

        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame(
            'external_content_mismatch:debit_tax,debit_sub,debit_invoice,credit_tax,credit_invoice',
            $sync->fresh()->last_error,
        );
    }

    public function test_money_forward_invoice_kind_provider_default_stays_unverified_without_resending(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-provider-default-123',
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        $remote = $journal;
        $remote['branches'][0]['debitor']['invoice_kind'] = 'INVOICE_KIND_NOT_TARGET';
        $remote['branches'][0]['creditor']['invoice_kind'] = 'INVOICE_KIND_NOT_TARGET';
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-provider-default-123' => Http::response([
                'journal' => ['id' => 'mf-provider-default-123', ...$remote],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('error', 'Money Forwardの読戻しで、送信していないインボイス区分「対象外」が返りました。ほかの送信項目は一致していますが、この区分が正しいか確認できないため未照合とし、登録済み仕訳の修正・再送は行っていません。');

        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame('synced', $sync->fresh()->status);
        $this->assertSame(AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED, $sync->fresh()->last_error);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-accounting.test/api/v3/journals/mf-provider-default-123');
        Http::assertSentCount(1);
    }

    public function test_money_forward_invoice_kind_provider_default_can_be_manually_confirmed_after_fresh_read(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax'],
            ]],
        ];
        $externalId = 'mf-manual-default-123';
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => $externalId,
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
            'last_error' => AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED,
        ]);
        $remote = $journal;
        $remote['branches'][0]['debitor']['invoice_kind'] = FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET;
        $remote['branches'][0]['creditor']['invoice_kind'] = FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET;
        Http::fake([
            'https://api-accounting.test/api/v3/journals/'.$externalId => Http::response([
                'journal' => ['id' => $externalId, ...$remote],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'confirm_mf_default_invoice_kind',
            'confirm_mf_invoice_kind_correct' => '1',
            'confirm_mf_no_resend' => '1',
        ])->assertSessionHas('success');

        $fresh = $sync->fresh();
        $this->assertSame('synced', $fresh->status);
        $this->assertSame($externalId, $fresh->external_transaction_id);
        $this->assertNull($fresh->verified_at);
        $this->assertTrue($fresh->hasManuallyConfirmedProviderInvoiceKindDefault());
        $this->assertSame($user->id, $fresh->manual_invoice_kind_confirmed_by_user_id);
        $this->assertNotNull($fresh->manual_invoice_kind_confirmed_at);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fresh->manual_invoice_kind_evidence_hash);
        $this->assertDatabaseHas('accounting_entry_sync_invoice_kind_audits', [
            'accounting_entry_sync_id' => $fresh->id,
            'accounting_entry_id' => $entry->id,
            'user_id' => $user->id,
            'actor_user_id' => $user->id,
            'provider' => 'money_forward',
            'event_type' => 'manual_default_confirmed',
            'external_transaction_id' => $externalId,
            'reason' => 'user_confirmed_provider_default_as_correct_no_resend',
        ], 'furimadeck');
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api-accounting.test/api/v3/journals/'.$externalId);
        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry->fresh());
            $this->fail('手動確認済みのMoney Forward仕訳を再送できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('送信済み、または処理中', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_money_forward_default_invoice_kind_manual_confirmation_is_blocked_when_tax_status_is_unconfirmed(): void
    {
        [$user, $entry] = $this->entryFixture();
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-unconfirmed-default-123',
            'request_payload' => ['transaction_date' => '2026-01-10', 'branches' => [['remark' => 'sale']]],
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
            'last_error' => AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED,
        ]);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['business_tax_periods'][0]['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED;
        $connection->update(['settings' => $settings]);
        Http::fake();

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'confirm_mf_default_invoice_kind',
            'confirm_mf_invoice_kind_correct' => '1',
            'confirm_mf_no_resend' => '1',
        ])->assertSessionHas('error', 'Money Forwardの課税・免税状態が未確認です。区分を確認するまで手動確認を記録できません。');

        $this->assertNull($sync->fresh()->manual_invoice_kind_confirmed_at);
        $this->assertSame(AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED, $sync->fresh()->last_error);
        $this->assertDatabaseMissing('accounting_entry_sync_invoice_kind_audits', ['accounting_entry_sync_id' => $sync->id], 'furimadeck');
        Http::assertNothingSent();
    }

    public function test_money_forward_default_invoice_kind_manual_confirmation_is_invalidated_when_readback_changes(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax'],
            ]],
        ];
        $externalId = 'mf-changing-default-123';
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => $externalId,
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
            'last_error' => AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED,
        ]);
        $remote = $journal;
        $remote['branches'][0]['debitor']['invoice_kind'] = FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET;
        $remote['branches'][0]['creditor']['invoice_kind'] = FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET;
        $changedRemote = $remote;
        $changedRemote['branches'][0]['remark'] = 'changed externally';
        Http::fake([
            'https://api-accounting.test/api/v3/journals/'.$externalId => Http::sequence()
                ->push(['journal' => ['id' => $externalId, ...$remote]])
                ->push(['journal' => ['id' => $externalId, ...$changedRemote]]),
        ]);
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'confirm_mf_default_invoice_kind',
            'confirm_mf_invoice_kind_correct' => '1',
            'confirm_mf_no_resend' => '1',
        ])->assertSessionHas('success');

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('error');

        $fresh = $sync->fresh();
        $this->assertSame('synced', $fresh->status);
        $this->assertSame($externalId, $fresh->external_transaction_id);
        $this->assertFalse($fresh->hasManuallyConfirmedProviderInvoiceKindDefault());
        $this->assertNull($fresh->manual_invoice_kind_confirmed_at);
        $this->assertSame(2, DB::connection('furimadeck')->table('accounting_entry_sync_invoice_kind_audits')
            ->where('accounting_entry_sync_id', $sync->id)->count());
        $this->assertDatabaseHas('accounting_entry_sync_invoice_kind_audits', [
            'accounting_entry_sync_id' => $sync->id,
            'event_type' => 'manual_confirmation_invalidated',
        ], 'furimadeck');
        Http::assertSentCount(2);
    }

    public function test_money_forward_manual_confirmation_action_is_not_rendered_for_unconfirmed_tax_status(): void
    {
        [$user] = $this->entryFixture();
        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_SALE)->firstOrFail();
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['business_tax_periods'][0]['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED;
        $connection->update(['settings' => $settings]);
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-view-unconfirmed-123',
            'request_payload' => ['transaction_date' => '2026-01-10', 'branches' => [['remark' => 'sale']]],
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'money_forward'),
            'last_error' => AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED,
        ]);
        Http::fake();

        $response = $this->actingAs($user)->get(route('furimadeck-accounting.index', ['section' => 'send', 'year' => 2026]));

        $response->assertOk();
        $response->assertViewHas('moneyForwardBusinessTaxStatusConfirmedByEntry', fn ($statuses): bool => $statuses[$entry->id] === false);
        $response->assertDontSee('confirm_mf_default_invoice_kind');
        Http::assertNothingSent();
    }

    public function test_money_forward_manual_confirmation_action_is_rendered_only_for_confirmed_tax_status(): void
    {
        [$user] = $this->entryFixture();
        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_SALE)->firstOrFail();
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-view-confirmed-123',
            'request_payload' => ['transaction_date' => '2026-01-10', 'branches' => [['remark' => 'sale']]],
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($connection, 'money_forward'),
            'last_error' => AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED,
        ]);
        Http::fake();

        $response = $this->actingAs($user)->get(route('furimadeck-accounting.index', ['section' => 'send', 'year' => 2026]));

        $response->assertOk();
        $response->assertViewHas('moneyForwardBusinessTaxStatusConfirmedByEntry', fn ($statuses): bool => $statuses[$entry->id] === true);
        $renderedEntry = $response->viewData('accountingEntries')->getCollection()->firstWhere('id', $entry->id);
        $this->assertNotNull($renderedEntry, 'テスト用の売上明細が確認画面に表示されていません。');
        $renderedSync = $renderedEntry->providerSync('money_forward');
        $this->assertSame(AccountingEntrySync::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED, $renderedSync->last_error);
        $this->assertSame('synced', $renderedSync->status);
        $this->assertNotNull($renderedSync->request_payload);
        $this->assertNotEmpty($renderedSync->provider_account_scope_hash);
        $this->assertTrue($renderedSync->hasUnverifiedProviderInvoiceKindDefault());
        $this->assertSame('connected', $response->viewData('accountingConnections')->get('money_forward')->status);
        $response->assertSee('name="action" value="confirm_mf_default_invoice_kind"', false);
        Http::assertNothingSent();
    }

    public function test_money_forward_read_back_rejects_invoice_kind_mismatch_without_tax_id(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id'],
                'creditor' => [
                    'value' => 100,
                    'account_id' => 'credit-id',
                    'invoice_kind' => 'INVOICE_KIND_NOT_TARGET',
                ],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-invoice-only-mismatch',
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        $remote = $journal;
        $remote['branches'][0]['creditor']['invoice_kind'] = 'INVOICE_KIND_QUALIFIED';
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-invoice-only-mismatch' => Http::response([
                'journal' => ['id' => 'mf-invoice-only-mismatch', ...$remote],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('error');

        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame('external_content_mismatch:credit_invoice', $sync->fresh()->last_error);
    }

    public function test_money_forward_read_back_accepts_money_forward_app_name_added_to_memo(): void
    {
        config()->set('furimadeck.accounting.oauth.money_forward.application_name', 'FurimaDeck Development');
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-app-name-123',
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        $remote = [...$journal, 'memo' => $journal['memo']."\nFurimaDeck Development"];
        Http::fake([
            'https://api-accounting.test/api/v3/journals/mf-app-name-123' => Http::response([
                'journal' => ['id' => 'mf-app-name-123', ...$remote],
            ]),
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('success');

        $this->assertNotNull($sync->fresh()->verified_at);
        $this->assertNull($sync->fresh()->last_error);
    }

    public function test_freee_read_back_requires_matching_company_id_and_never_verifies_legacy_without_snapshot(): void
    {
        [$user, $entry] = $this->entryFixture();
        config()->set('furimadeck.accounting.freee_sync.api_base', 'https://api.freee.test');
        AccountingConnection::query()->create([
            'user_id' => $user->id, 'provider' => 'freee', 'access_token' => 'freee-access',
            'refresh_token' => 'freee-refresh', 'expires_at' => now()->addHour(), 'status' => 'connected',
            'settings' => ['company_id' => '12'],
        ]);
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee', 'status' => 'synced', 'external_transaction_id' => '123',
        ]);
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'freee']))
            ->assertSessionHas('error');

        $payload = [
            'company_id' => 12, 'issue_date' => '2026-01-10', 'adjustment' => false,
            'ref_number' => $entry->management_id,
            'details' => [
                ['entry_side' => 'debit', 'account_item_id' => 1, 'tax_code' => 2, 'amount' => 100, 'description' => 'sale'],
                ['entry_side' => 'credit', 'account_item_id' => 3, 'tax_code' => 4, 'amount' => 100, 'description' => 'sale'],
            ],
        ];
        $sync->update([
            'request_payload' => $payload,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)->fingerprint('freee', '12'),
        ]);
        Http::fakeSequence()->push(['manual_journal' => ['id' => 123, ...$payload]])
            ->push(['manual_journal' => ['id' => 123, ...$payload, 'company_id' => 13]]);
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'freee']))
            ->assertSessionHas('success');
        $this->assertNotNull($sync->fresh()->verified_at);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'freee']))
            ->assertSessionHas('error');
        $this->assertNull($sync->fresh()->verified_at);
    }

    public function test_freee_read_back_refreshes_expired_token_once_without_changing_sync_state_on_missing_remote_journal(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        config()->set('furimadeck.accounting.freee_sync.api_base', 'https://api.freee.test');
        config()->set('furimadeck.accounting.oauth.freee', [
            'client_id' => 'freee-client',
            'client_secret' => 'freee-secret',
            'redirect_uri' => 'http://127.0.0.1/callback/freee',
            'token_url' => 'https://accounts.secure.freee.co.jp/public_api/token',
        ]);
        $user->accountingConnections()->create([
            'provider' => 'freee',
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => ['company_id' => '321'],
        ]);
        $sync = $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => 'synced',
            'external_transaction_id' => '123',
            'request_payload' => ['company_id' => 321],
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)->fingerprint('freee', '321'),
        ]);
        $tokenRequests = 0;
        $journalRequests = 0;
        Http::fake(function ($request) use (&$tokenRequests, &$journalRequests) {
            if ($request->url() === 'https://accounts.secure.freee.co.jp/public_api/token') {
                $tokenRequests++;

                return Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 21600]);
            }
            if (str_contains($request->url(), '/api/1/manual_journals/123')) {
                $journalRequests++;

                return $request->hasHeader('Authorization', 'Bearer old-access')
                    ? Http::response(['code' => 'expired_access_token'], 401)
                    : Http::response([], 404);
            }

            return Http::response([], 404);
        });

        try {
            app(FurimaDeckAccountingJournalVerificationService::class)->verify($user, $entry, 'freee');
            $this->fail('見つからない外部仕訳を照合済みにしてはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('会計ソフト側に仕訳が見つかりません', $exception->getMessage());
        }

        $this->assertSame(1, $tokenRequests);
        $this->assertSame(2, $journalRequests);
        $this->assertSame('synced', $sync->fresh()->status);
        $this->assertSame('external_journal_missing', $sync->fresh()->last_error);
        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame('new-access', $user->accountingConnections()->where('provider', 'freee')->firstOrFail()->access_token);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/1/manual_journals/123')
            && $request->hasHeader('Authorization', 'Bearer new-access'));
    }

    public function test_another_users_entry_cannot_be_verified(): void
    {
        [$user, $entry] = $this->entryFixture();
        $other = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        Http::fake();
        $this->actingAs($other)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_deleted_remote_journal_clears_old_verification_without_making_it_resendable(): void
    {
        [$user, $entry] = $this->entryFixture();
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward', 'status' => 'synced', 'external_transaction_id' => 'mf-deleted',
            'request_payload' => ['transaction_date' => '2026-01-10'], 'verified_at' => now()->subDay(),
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        Http::fake(['https://api-accounting.test/api/v3/journals/mf-deleted' => Http::response([], 404)]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('error');
        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame('external_journal_missing', $sync->fresh()->last_error);
        $this->assertSame('synced', $sync->fresh()->status);
    }

    public function test_verification_does_not_mark_a_different_external_id_as_verified(): void
    {
        [$user, $entry] = $this->entryFixture();
        $journal = [
            'transaction_date' => '2026-01-10', 'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
            ]],
        ];
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward', 'status' => 'synced', 'external_transaction_id' => 'mf-old',
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        Http::fake(function () use ($sync, $journal) {
            $sync->update(['external_transaction_id' => 'mf-new']);

            return Http::response(['journal' => ['id' => 'mf-old', ...$journal]]);
        });

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']))
            ->assertSessionHas('error');
        $this->assertNull($sync->fresh()->verified_at);
        $this->assertSame('mf-new', $sync->fresh()->external_transaction_id);
    }

    public function test_legacy_unknown_provider_state_is_not_sent_until_explicitly_resolved(): void
    {
        [$user, $entry] = $this->entryFixture();
        $entry->update(['sync_status' => 'synced', 'external_transaction_id' => 'historical-id']);
        Http::fake();

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('過去の登録記録の確認が必要')
            ->assertDontSee('外部IDを記録')
            ->assertSee('過去の登録結果を確認できないため、二重登録防止のためCSV再出力を停止しています。')
            ->assertDontSee('未登録を確認して再送可能にする');

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'retry', 'verified_absent' => '1',
        ])->assertSessionHas('error');
        $this->assertSame('synced', $entry->fresh()->sync_status);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('送信先不明の記録は自動再送できてはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('確認', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_sync_unknown_can_be_resolved_as_sent_or_verified_absent_by_owner(): void
    {
        [$user, $entry] = $this->entryFixture();
        $sync = $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'sync_unknown', 'last_error' => 'network_error']);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'mark_synced',
            'external_transaction_id' => 'verified-mf-id',
        ])->assertSessionHas('error');
        $this->assertSame('sync_unknown', $sync->fresh()->status);
        Http::assertNothingSent();

        $journal = [
            'transaction_date' => '2026-01-10', 'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #'.$entry->management_id,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 100, 'account_id' => 'debit-id', 'tax_id' => 'debit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
                'creditor' => ['value' => 100, 'account_id' => 'credit-id', 'tax_id' => 'credit-tax', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
            ]],
        ];
        $sync->update([
            'request_payload' => $journal,
            'provider_account_scope_hash' => app(FurimaDeckAccountingProviderScopeService::class)
                ->fingerprintForConnection($user->accountingConnections()->where('provider', 'money_forward')->firstOrFail(), 'money_forward'),
        ]);
        $mismatched = $journal;
        $mismatched['branches'][0]['debitor']['value'] = 101;
        Http::fakeSequence()->push(['journal' => ['id' => 'verified-mf-id', ...$mismatched]])
            ->push(['journal' => ['id' => 'verified-mf-id', ...$journal]]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'mark_synced', 'external_transaction_id' => 'verified-mf-id',
        ])->assertSessionHas('error');
        $this->assertSame('sync_unknown', $sync->fresh()->status);

        $markResponse = $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'mark_synced', 'external_transaction_id' => 'verified-mf-id',
        ]);
        $markResponse->assertSessionHas('success');
        $this->assertNotNull($sync->fresh()->verified_at);
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'verified-mf-id',
        ], 'furimadeck');

        $entry->providerSyncs()->where('provider', 'money_forward')->update(['status' => 'sync_unknown']);
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'retry',
            'verified_absent' => '1',
        ])->assertSessionHas('error');
        $this->assertSame('sync_unknown', $sync->fresh()->status);
        $this->assertSame('verified-mf-id', $sync->fresh()->external_transaction_id);

        $sync->update(['external_transaction_id' => null]);
        $this->actingAs($user)->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
            'action' => 'retry', 'verified_absent' => '1',
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('accounting_entry_syncs', [
            'accounting_entry_id' => $entry->id,
            'provider' => 'money_forward',
            'status' => 'not_synced',
        ], 'furimadeck');

        $otherUser = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $entry->providerSyncs()->where('provider', 'money_forward')->update(['status' => 'sync_unknown']);
        $this->actingAs($otherUser)
            ->post(route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']), [
                'action' => 'mark_synced',
                'external_transaction_id' => 'unauthorized-id',
            ])
            ->assertNotFound();
        $this->assertSame('sync_unknown', $entry->providerSyncs()->where('provider', 'money_forward')->value('status'));
    }

    public function test_api_failure_is_reported_and_diagnostic_survives_transaction_rollback(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [['code' => 'invalid_query_parameter_value', 'message' => 'Sensitive response details must not be shown Target: journal.branches[0].debitor.tax_id TargetValue: private-tax-value']],
        ], 400)]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('A rejected request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 400', $exception->getMessage());
            $this->assertStringContainsString('invalid_query_parameter_value', $exception->getMessage());
            $this->assertStringContainsString('target_journal.branches[0].debitor.tax_id', $exception->getMessage());
            $this->assertStringNotContainsString('Sensitive response details', $exception->getMessage());
            $this->assertStringNotContainsString('private-tax-value', $exception->getMessage());
        }

        $this->assertSame('http_400_invalid_query_parameter_value_target_journal.branches[0].debitor.tax_id', $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'));
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
    }

    public function test_api_failure_saves_only_a_sanitized_provider_request_id_for_support(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [['code' => 'invalid_request_body_value', 'message' => 'Invalid body Target: base']],
        ], 400, ['X-Request-Id' => 'mfreq-20261011-abc123'])]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('A rejected request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('request_id=mfreq-20261011-abc123', $exception->getMessage());
        }

        $this->assertSame(
            'http_400_invalid_request_body_value_target_base|request_id=mfreq-20261011-abc123',
            $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'),
        );
        $this->assertSame(
            'http_400_invalid_request_body_value_target_base|request_id=mfreq-20261011-abc123',
            $entry->providerSync('money_forward')->fresh()->last_error,
        );
    }

    public function test_api_failure_ignores_provider_request_ids_with_unsafe_characters(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [['code' => 'invalid_request_body_value', 'message' => 'Invalid body Target: base']],
        ], 400, ['X-Request-Id' => 'request-id with spaces'])]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('A rejected request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('request-id with spaces', $exception->getMessage());
        }

        $this->assertSame(
            'http_400_invalid_request_body_value_target_base',
            $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'),
        );
    }

    public function test_base_target_diagnostic_maps_value_to_payload_path_without_storing_value(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [['code' => 'invalid_request_body_value', 'message' => 'Invalid body Target: base TargetValue: journal_entry']],
        ], 400)]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('A rejected request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('_target_base_value_journal_type', $exception->getMessage());
            $this->assertStringNotContainsString('journal_entry', $exception->getMessage());
        }

        $this->assertSame(
            'http_400_invalid_request_body_value_target_base_value_journal_type',
            $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'),
        );
    }

    public function test_base_target_diagnostic_maps_unique_url_encoded_payload_value_without_storing_value(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [['code' => 'invalid_request_body_value', 'message' => 'Invalid body Target: base TargetValue: sales%2Did']],
        ], 400)]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('A rejected request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('_target_base_value_branches.0.creditor.account_id', $exception->getMessage());
            $this->assertStringNotContainsString('sales%2Did', $exception->getMessage());
        }

        $this->assertSame(
            'http_400_invalid_request_body_value_target_base_value_branches.0.creditor.account_id',
            $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'),
        );
    }

    public function test_base_target_diagnostic_maps_json_object_to_unique_payload_path_without_storing_values(): void
    {
        [$user, $entry] = $this->entryFixture();
        $targetValue = json_encode([
            'value' => 10000,
            'invoice_kind' => 'INVOICE_KIND_NOT_TARGET',
            'tax_id' => 'tax-11',
            'account_id' => 'settlement-id',
        ], JSON_THROW_ON_ERROR);
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [[
                'code' => 'invalid_request_body_value',
                'message' => 'Invalid body Target: base TargetValue: '.$targetValue,
            ]],
        ], 400)]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('A rejected request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('_target_base_value_branches.0.debitor', $exception->getMessage());
            $this->assertStringNotContainsString('tax-11', $exception->getMessage());
            $this->assertStringNotContainsString('settlement-id', $exception->getMessage());
            $this->assertStringNotContainsString('INVOICE_KIND_NOT_TARGET', $exception->getMessage());
        }

        $this->assertSame(
            'http_400_invalid_request_body_value_target_base_value_branches.0.debitor',
            $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'),
        );
    }

    public function test_base_target_diagnostic_does_not_guess_when_target_value_matches_multiple_payload_paths(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [[
                'code' => 'invalid_request_body_value',
                'message' => 'Invalid body Target: base TargetValue: 10000',
            ]],
        ], 400)]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('A rejected request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('_target_base_value_unmapped', $exception->getMessage());
            $this->assertStringNotContainsString('_value_branches.', $exception->getMessage());
            $this->assertStringNotContainsString('10000', $exception->getMessage());
        }

        $this->assertSame(
            'http_400_invalid_request_body_value_target_base_value_unmapped',
            $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'),
        );
    }

    public function test_base_target_diagnostic_does_not_pair_target_value_from_another_error(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [
                ['code' => 'invalid_request_body_value', 'message' => 'Invalid body Target: base'],
                ['code' => 'invalid_transaction_date', 'message' => 'Invalid date Target: transaction_date TargetValue: 2026-01-10'],
            ],
        ], 400)]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('A rejected request must not be reported as successful.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('_target_base', $exception->getMessage());
            $this->assertStringContainsString('invalid_transaction_date', $exception->getMessage());
            $this->assertStringNotContainsString('_target_base_value_', $exception->getMessage());
            $this->assertStringNotContainsString('2026-01-10', $exception->getMessage());
        }

        $this->assertSame(
            'http_400_invalid_request_body_value_invalid_transaction_date_target_base_transaction_date',
            $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'),
        );
    }

    public function test_exempt_business_invoice_kind_rejection_has_specific_safe_guidance(): void
    {
        [$user, $entry] = $this->entryFixture();
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response([
            'errors' => [[
                'code' => 'invalid_request_body_value',
                'message' => '免税事業者にインボイス区分を登録できません Target: invoice_kind TargetValue: INVOICE_KIND_NOT_TARGET',
            ]],
        ], 400)]);

        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);
            $this->fail('免税事業者に適格請求書区分の仕訳を登録してはいけません。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('免税扱い', $exception->getMessage());
            $this->assertStringContainsString('「免税事業者（区分を送信しない）」', $exception->getMessage());
            $this->assertStringContainsString('invoice_kind_exempt_business_conflict', $exception->getMessage());
            $this->assertStringNotContainsString('TargetValue', $exception->getMessage());
        }

        $this->assertSame(
            'http_400_invalid_request_body_value_invoice_kind_exempt_business_conflict',
            $user->accountingConnections()->where('provider', 'money_forward')->value('last_error'),
        );
        $this->assertSame('failed', $entry->providerSync('money_forward')->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && collect($request->data()['journal']['branches'])->every(fn (array $branch): bool => $branch['debitor']['invoice_kind'] === 'INVOICE_KIND_NOT_TARGET'
                && $branch['creditor']['invoice_kind'] === 'INVOICE_KIND_NOT_TARGET'));
    }

    public function test_exempt_business_setting_omits_tax_ids_and_invoice_classification_even_when_tax_master_exists(): void
    {
        [$user, $entry] = $this->entryFixture(false);
        $connection = $user->accountingConnections()->where('provider', 'money_forward')->firstOrFail();
        $settings = $connection->settings;
        $settings['business_tax_periods'][0]['invoice_kind'] = FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_EXEMPT;
        $settings['invoice_kinds'] = $this->invoiceKindsFor('INVOICE_KIND_OMIT');
        $connection->update(['settings' => $settings]);
        Http::fake([
            'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                fn (int $index): array => ['id' => 'tax-'.$index], range(11, 16),
            )]),
            'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                ['id' => 'settlement-id', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
            ]]),
            'https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-exempt-journal']], 201),
        ]);

        $id = app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry);

        $this->assertSame('mf-exempt-journal', $id);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api-accounting.test/api/v3/journals'
            && collect($request->data()['journal']['branches'])->every(fn (array $branch): bool => ! array_key_exists('invoice_kind', $branch['debitor'])
                && ! array_key_exists('invoice_kind', $branch['creditor'])
                && ! array_key_exists('tax_id', $branch['debitor'])
                && ! array_key_exists('tax_id', $branch['creditor'])
                && is_int($branch['debitor']['value'])
                && is_int($branch['creditor']['value'])
                && filled($branch['debitor']['account_id'])
                && filled($branch['creditor']['account_id'])));
    }

    public function test_batch_queues_each_confirmed_unsent_entry_and_marks_it_queued(): void
    {
        [$user, $entry] = $this->entryFixture();
        Queue::fake();

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('CSV取込状況')
            ->assertSee('会計ソフトでCSVを取り込んだ後、仕訳帳で登録結果を確認し、この画面に記録します。')
            ->assertSee('確認済み')
            ->assertSee('Money Forwardへ一括送信（1件）');

        $response = $this->actingAs($user)->post(route('furimadeck-accounting.send.money-forward.batch'), ['year' => 2026]);
        $batch = AccountingSyncBatch::query()->firstOrFail();

        $response->assertRedirect(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']));
        $response->assertSessionHas('success');
        $this->assertSame('queued', $batch->status);
        $this->assertSame(1, $batch->total_count);
        $this->assertSame('queued', $entry->fresh()->sync_status);
        $this->assertDatabaseHas('accounting_sync_batch_items', [
            'batch_id' => $batch->id,
            'accounting_entry_id' => $entry->id,
            'status' => 'queued',
        ], 'furimadeck');
        Queue::assertPushed(
            SendMoneyForwardAccountingEntryJob::class,
            fn (SendMoneyForwardAccountingEntryJob $job): bool => $job->connection === 'database' && $job->queue === 'default',
        );
    }

    public function test_batch_sends_only_reconciled_sales_and_keeps_unmatched_sale_untouched(): void
    {
        [$user, $sendableEntry] = $this->entryFixture();
        $product = $user->products()->create([
            'internal_sku' => 'MF-UNMATCHED-'.uniqid(),
            'product_name' => '仕入未照合商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = $user->sales()->firstOrFail()->marketplace;
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => '2026-01-11',
            'status' => 'completed',
            'cost_basis' => 1000,
            'sales_fee' => 0,
            'shipping_fee' => 0,
            'net_profit' => 2000,
        ]);
        $blockedEntry = app(FurimaDeckAccountingEntryService::class)
            ->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))
            ->firstWhere('sale_id', $sale->id);
        $this->assertNotNull($blockedEntry);
        $blockedEntry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        Queue::fake();

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026, 'section' => 'send']))
            ->assertOk()
            ->assertSee('Money Forwardへ一括送信（1件）')
            ->assertSee('仕入・期首在庫の確認待ちで保留中です');

        $this->actingAs($user)
            ->post(route('furimadeck-accounting.send.money-forward.batch'), ['year' => 2026])
            ->assertRedirect()
            ->assertSessionHas('success');

        $batch = AccountingSyncBatch::query()->firstOrFail();
        $this->assertSame(1, $batch->total_count);
        $this->assertDatabaseHas('accounting_sync_batch_items', [
            'batch_id' => $batch->id,
            'accounting_entry_id' => $sendableEntry->id,
            'status' => 'queued',
        ], 'furimadeck');
        $this->assertDatabaseMissing('accounting_sync_batch_items', [
            'batch_id' => $batch->id,
            'accounting_entry_id' => $blockedEntry->id,
        ], 'furimadeck');
        $this->assertSame('not_synced', $blockedEntry->fresh()->providerSyncStatus('money_forward'));
        Queue::assertPushed(SendMoneyForwardAccountingEntryJob::class, 1);
    }

    public function test_batch_job_sends_entry_and_completes_with_recorded_result(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'queued',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'queued',
        ]);
        $entry->update(['sync_status' => 'queued']);
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response(['journal' => ['id' => 'mf-batch-journal-1']], 201)]);

        (new SendMoneyForwardAccountingEntryJob($batch->id, $item->id))
            ->handle(app(FurimaDeckMoneyForwardAccountingSyncService::class));

        $this->assertSame('synced', $entry->fresh()->sync_status);
        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->succeeded_count);
        $this->assertSame(1, $batch->fresh()->processed_count);
    }

    public function test_batch_failure_callback_preserves_a_recorded_external_success(): void
    {
        [, $entry] = $this->entryFixture();
        $batch = $entry->user->accountingSyncBatches()->create([
            'provider' => 'money_forward', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'running', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'running',
        ]);
        $entry->providerSyncs()->create([
            'provider' => 'money_forward', 'status' => 'synced', 'external_transaction_id' => 'mf-known-id',
        ]);
        $entry->update(['sync_status' => 'synced']);

        (new SendMoneyForwardAccountingEntryJob($batch->id, $item->id))->failed(new RuntimeException('worker ended'));

        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_batch_failure_before_send_is_recorded_as_not_sent_and_resendable(): void
    {
        [, $entry] = $this->entryFixture();
        $batch = $entry->user->accountingSyncBatches()->create([
            'provider' => 'money_forward', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'running', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'running',
        ]);
        $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'queued']);
        $entry->update(['sync_status' => 'queued']);
        Http::fake();

        $job = new SendMoneyForwardAccountingEntryJob($batch->id, $item->id);
        $job->failed(new RuntimeException('worker ended before send'));
        $job->failed(new RuntimeException('duplicate failure callback'));

        $this->assertSame('failed', $entry->fresh()->providerSyncStatus('money_forward'));
        $this->assertSame('queue_interrupted_before_send', $entry->fresh()->providerSync('money_forward')->last_error);
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertStringContainsString('登録は行われていません', $item->fresh()->diagnostic);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->processed_count);
        Http::assertNothingSent();
    }

    public function test_batch_failure_after_send_started_requires_external_reconciliation(): void
    {
        [, $entry] = $this->entryFixture();
        $batch = $entry->user->accountingSyncBatches()->create([
            'provider' => 'money_forward', 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
            'status' => 'running', 'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id, 'management_id' => $entry->management_id,
            'title' => $entry->title, 'status' => 'running',
        ]);
        $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'sending', 'attempted_at' => now()]);
        $entry->update(['sync_status' => 'sending']);
        Http::fake();

        (new SendMoneyForwardAccountingEntryJob($batch->id, $item->id))->failed(new RuntimeException('worker ended after request start'));

        $this->assertSame('sync_unknown', $entry->fresh()->providerSyncStatus('money_forward'));
        $this->assertSame('uncertain', $item->fresh()->status);
        $this->assertStringContainsString('結果を確認できない', $item->fresh()->diagnostic);
        $this->assertSame(1, $batch->fresh()->processed_count);
        Http::assertNothingSent();
    }

    public function test_batch_job_marks_network_result_uncertain_and_does_not_make_it_resendable(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'queued',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'queued',
        ]);
        $entry->update(['sync_status' => 'queued']);
        Http::fake(['https://api-accounting.test/api/v3/journals' => fn () => throw new ConnectionException('connection dropped')]);

        (new SendMoneyForwardAccountingEntryJob($batch->id, $item->id))
            ->handle(app(FurimaDeckMoneyForwardAccountingSyncService::class));

        $this->assertSame('sync_unknown', $entry->fresh()->sync_status);
        $this->assertDatabaseHas('accounting_entry_syncs', ['accounting_entry_id' => $entry->id, 'provider' => 'money_forward', 'status' => 'sync_unknown'], 'furimadeck');
        $this->assertSame('uncertain', $item->fresh()->status);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_SALE)->where('sync_status', 'not_synced')->count());
    }

    public function test_batch_job_treats_server_error_as_uncertain_to_prevent_duplicate_journals(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'queued',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'queued',
        ]);
        $entry->update(['sync_status' => 'queued']);
        Http::fake(['https://api-accounting.test/api/v3/journals' => Http::response(['errors' => []], 500)]);

        (new SendMoneyForwardAccountingEntryJob($batch->id, $item->id))
            ->handle(app(FurimaDeckMoneyForwardAccountingSyncService::class));

        $this->assertSame('sync_unknown', $entry->fresh()->sync_status);
        $this->assertSame('uncertain', $item->fresh()->status);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_SALE)->where('sync_status', 'not_synced')->count());
    }

    public function test_stale_batch_with_confirmed_external_success_is_counted_as_succeeded(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'running',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'running',
        ]);
        $sync = $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'synced',
            'external_transaction_id' => 'mf-confirmed-journal-123',
            'attempted_at' => now()->subSeconds(600),
        ]);
        $entry->update(['sync_status' => 'queued']);
        DB::connection('furimadeck')->table('accounting_sync_batch_items')->where('id', $item->id)->update(['updated_at' => now()->subSeconds(600)]);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);
        config()->set('queue.connections.database.retry_after', 90);
        Http::fake();

        $this->artisan('furimadeck:accounting-recover-stale-sends')
            ->expectsOutput('登録済みを確認: 1件 / 送信結果不明として停止: 0件 / 未送信として停止: 0件。外部への再送はしていません。')
            ->assertSuccessful();

        $this->assertSame('synced', $sync->fresh()->status);
        $this->assertSame('mf-confirmed-journal-123', $sync->fresh()->external_transaction_id);
        $this->assertSame('synced', $entry->fresh()->sync_status);
        $this->assertSame('succeeded', $item->fresh()->status);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->succeeded_count);
        $this->assertSame(1, $batch->fresh()->processed_count);
        Http::assertNothingSent();
    }

    public function test_stale_queued_batch_is_marked_not_sent_and_is_idempotent(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'queued',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'queued',
        ]);
        $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'queued']);
        $entry->update(['sync_status' => 'queued']);
        $staleAt = now()->subSeconds(600);
        DB::connection('furimadeck')->table('accounting_sync_batch_items')->where('id', $item->id)->update(['updated_at' => $staleAt]);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);
        config()->set('queue.connections.database.retry_after', 90);
        Http::fake();

        $this->artisan('furimadeck:accounting-recover-stale-sends')->assertSuccessful();

        $this->assertSame('failed', $entry->providerSync('money_forward')->fresh()->status);
        $this->assertStringContainsString('登録は行われていません', $entry->providerSync('money_forward')->fresh()->last_error_label);
        $this->assertSame('not_synced', $entry->fresh()->sync_status);
        $this->assertSame('failed', $item->fresh()->status);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->processed_count);
        $this->assertSame(1, $batch->fresh()->failed_count);

        $this->artisan('furimadeck:accounting-recover-stale-sends')->assertSuccessful();
        $this->assertSame(1, $batch->fresh()->processed_count);
        (new SendMoneyForwardAccountingEntryJob($batch->id, $item->id))
            ->handle(app(FurimaDeckMoneyForwardAccountingSyncService::class));
        Http::assertNothingSent();
    }

    public function test_stale_queued_batch_item_with_a_database_queue_job_is_not_failed_early(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'queued',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'queued',
        ]);
        $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'queued']);
        $entry->update(['sync_status' => 'queued']);
        DB::connection('furimadeck')->table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => SendMoneyForwardAccountingEntryJob::class,
                'data' => ['command' => serialize(new SendMoneyForwardAccountingEntryJob($batch->id, $item->id))],
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subSeconds(600)->timestamp,
            'created_at' => now()->subSeconds(600)->timestamp,
        ]);
        DB::connection('furimadeck')->table('accounting_sync_batch_items')->where('id', $item->id)
            ->update(['updated_at' => now()->subSeconds(600)]);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);
        config()->set('queue.connections.database.retry_after', 90);
        Http::fake();

        $this->artisan('furimadeck:accounting-recover-stale-sends')->assertSuccessful();

        $this->assertSame('queued', $entry->providerSync('money_forward')->fresh()->status);
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame('queued', $batch->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_queued_batch_is_reported_as_deferred_when_queue_state_cannot_be_inspected(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'queued',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'queued',
        ]);
        $entry->providerSyncs()->create(['provider' => 'money_forward', 'status' => 'queued']);
        $entry->update(['sync_status' => 'queued']);
        DB::connection('furimadeck')->table('accounting_sync_batch_items')->where('id', $item->id)
            ->update(['updated_at' => now()->subSeconds(600)]);
        DB::connection('furimadeck')->getSchemaBuilder()->drop('jobs');
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);
        config()->set('queue.connections.database.retry_after', 90);
        Http::preventStrayRequests();

        $this->artisan('furimadeck:accounting-recover-stale-sends')
            ->expectsOutput('登録済みを確認: 0件 / 送信結果不明として停止: 0件 / 未送信として停止: 0件。外部への再送はしていません。')
            ->expectsOutput('復旧保留: 1件。キュー状態を確認できないため変更していません。キューDBを復旧してから再実行してください。')
            ->assertExitCode(1);

        $this->assertSame('queued', $entry->providerSync('money_forward')->fresh()->status);
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame('queued', $batch->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_batch_with_missing_send_record_is_uncertain_not_claimed_unsent(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'running',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'running',
        ]);
        $entry->update(['sync_status' => 'queued']);
        DB::connection('furimadeck')->table('accounting_sync_batch_items')->where('id', $item->id)->update(['updated_at' => now()->subSeconds(600)]);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);
        config()->set('queue.connections.database.retry_after', 90);

        $this->artisan('furimadeck:accounting-recover-stale-sends')->assertSuccessful();

        $this->assertSame('uncertain', $item->fresh()->status);
        $this->assertSame(1, $batch->fresh()->failed_count);
        $this->assertStringContainsString('記録を確認できません', $item->fresh()->diagnostic);
    }

    public function test_stale_money_forward_batch_send_becomes_unknown_without_retry(): void
    {
        [$user, $entry] = $this->entryFixture();
        $batch = $user->accountingSyncBatches()->create([
            'provider' => 'money_forward',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'running',
            'total_count' => 1,
        ]);
        $item = $batch->items()->create([
            'accounting_entry_id' => $entry->id,
            'management_id' => $entry->management_id,
            'title' => $entry->title,
            'status' => 'running',
        ]);
        $entry->providerSyncs()->create([
            'provider' => 'money_forward',
            'status' => 'sending',
            'attempted_at' => now()->subSeconds(600),
        ]);
        $entry->update(['sync_status' => 'queued']);
        DB::connection('furimadeck')->table('accounting_sync_batch_items')->where('id', $item->id)->update(['updated_at' => now()->subSeconds(600)]);
        config()->set('furimadeck.accounting.stale_send_recovery_seconds', 300);
        config()->set('queue.connections.database.retry_after', 90);
        Http::fake();

        $this->artisan('furimadeck:accounting-recover-stale-sends')->assertSuccessful();

        $this->assertSame('sync_unknown', $entry->providerSync('money_forward')->fresh()->status);
        $this->assertSame('sync_unknown', $entry->fresh()->sync_status);
        $this->assertSame('uncertain', $item->fresh()->status);
        $this->assertSame(1, $batch->fresh()->processed_count);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        try {
            app(FurimaDeckMoneyForwardAccountingSyncService::class)->send($user, $entry->fresh(), $item->id);
            $this->fail('送信結果不明の明細を再送できてしまいました。');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('送信結果を確認', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    /** @return array{0: User, 1: AccountingEntry} */
    private function entryFixture(bool $fakeMaster = true, int $packingCost = 0, bool $purchaseReconciled = true): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $product = $user->products()->create([
            'internal_sku' => 'MF-'.uniqid(),
            'product_name' => 'MF商品',
            'condition' => 'used',
            'purchase_date' => '2026-01-01',
            'purchase_accounting_enabled' => true,
            'purchase_unit_cost' => 1000,
            'purchase_evidence' => [
                'purchase_unit_cost' => [
                    'document_type' => 'receipt',
                    'tax_category' => 'standard_10',
                    'invoice_issuer' => 'qualified',
                    'date_matches_entry_confirmed' => true,
                    'reviewed_at' => now()->toIso8601String(),
                ],
            ],
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create(['code' => 'mf-'.uniqid(), 'name' => 'MF販売先', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $sale = $user->sales()->create(['product_id' => $product->id, 'marketplace_id' => $marketplace->id, 'internal_sku_snapshot' => $product->internal_sku, 'product_name_snapshot' => $product->product_name, 'quantity' => 1, 'sold_price' => 10000, 'sold_at' => '2026-01-10', 'status' => 'completed', 'cost_basis' => 1000, 'sales_fee' => 500, 'shipping_fee' => 500, 'packing_cost' => $packingCost, 'net_profit' => 8000 - $packingCost]);
        $entry = app(FurimaDeckAccountingEntryService::class)->syncPeriod($user, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'))->firstOrFail();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
        $moneyForwardConnection = AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'money_forward',
            'access_token' => 'mf-access',
            'refresh_token' => 'mf-refresh',
            'expires_at' => now()->addHour(),
            'external_account_id' => 'tenant-mf-fixture',
            'status' => 'connected',
            'settings' => [
                'account_ids' => ['settlement' => 'settlement-id', 'sales' => 'sales-id', 'fees' => 'fees-id', 'shipping' => 'shipping-id', 'cost' => 'cost-id', 'inventory' => 'inventory-id'],
                'sub_account_ids' => ['settlement' => null, 'sales' => null, 'fees' => null, 'shipping' => null, 'cost' => null, 'inventory' => null],
                'tax_ids' => ['settlement' => 'tax-11', 'sales' => 'tax-12', 'fees' => 'tax-13', 'shipping' => 'tax-16', 'cost' => 'tax-14', 'inventory' => 'tax-15'],
                'account_statement_types_checked' => true,
                'invoice_kind' => 'INVOICE_KIND_NOT_TARGET',
                'business_tax_periods' => [['from' => '2026-01-01', 'to' => '2026-12-31', 'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE]],
                'invoice_kinds' => $this->invoiceKindsFor(),
            ],
        ]);

        if ($purchaseReconciled) {
            $purchase = app(FurimaDeckAccountingEntryService::class)->createNewPurchaseEntry($user, $product);
            $purchase->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
            foreach (['money_forward', 'freee'] as $provider) {
                $purchase->providerSyncs()->create([
                    'provider' => $provider,
                    'status' => 'synced',
                    'external_transaction_id' => $provider.'-purchase-'.$product->id,
                    'request_payload' => ['test_fixture' => true],
                    'provider_account_scope_hash' => $provider === 'money_forward'
                        ? app(FurimaDeckAccountingProviderScopeService::class)->fingerprintForConnection($moneyForwardConnection, 'money_forward')
                        : app(FurimaDeckAccountingProviderScopeService::class)->fingerprint('freee', '123'),
                    'verified_at' => now(),
                ]);
            }
        }

        if ($fakeMaster) {
            Http::fake([
                'https://api-accounting.test/api/v3/taxes*' => Http::response(['taxes' => array_map(
                    fn (int $index): array => ['id' => 'tax-'.$index],
                    range(11, 16),
                )]),
                'https://api-accounting.test/api/v3/accounts*' => Http::response(['accounts' => [
                    ['id' => 'settlement-id', 'name' => '売掛金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                    ['id' => 'sales-id', 'tax_id' => 'tax-12', 'account_group' => 'REVENUE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'fees-id', 'tax_id' => 'tax-13', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'shipping-id', 'tax_id' => 'tax-16', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'cost-id', 'tax_id' => 'tax-14', 'account_group' => 'EXPENSE', 'financial_statement_type' => 'PROFIT_LOSS'],
                    ['id' => 'inventory-id', 'tax_id' => 'tax-15', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                    ['id' => 'bank-source', 'name' => '普通預金', 'tax_id' => 'tax-11', 'account_group' => 'ASSET', 'financial_statement_type' => 'BALANCE_SHEET'],
                ]]),
            ]);
        }

        return [$user, $entry];
    }

    /** @return array<string, string> */
    private function invoiceKindsFor(string $kind = 'INVOICE_KIND_NOT_TARGET'): array
    {
        return array_fill_keys(['settlement', 'sales', 'fees', 'shipping', 'other', 'cost', 'inventory'], $kind);
    }
}
