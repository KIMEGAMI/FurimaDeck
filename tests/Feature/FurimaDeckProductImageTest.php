<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\ProductImageProcessor;
use App\Services\SaleLifecycleService;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FurimaDeckProductImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        Storage::fake(ProductImage::PRIVATE_STORAGE_DISK);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_product_registration_rejects_more_than_ten_images(): void
    {
        $user = $this->verifiedUser();
        $images = collect(range(1, 11))
            ->map(fn (int $number) => UploadedFile::fake()->image("image-{$number}.jpg"))
            ->all();

        $this->actingAs($user)
            ->from(route('products.create'))
            ->post(route('products.store'), [...$this->productPayload(), 'images' => $images])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('images');

        $this->assertSame(0, $user->products()->count());
    }

    public function test_product_registration_accepts_ten_images_and_stores_main_and_thumbnail_files(): void
    {
        $user = $this->premiumUser();
        $images = collect(range(1, 10))
            ->map(fn (int $number) => UploadedFile::fake()->image("image-{$number}.jpg", 32, 24))
            ->all();

        $this->actingAs($user)
            ->post(route('products.store'), [...$this->productPayload(), 'images' => $images])
            ->assertRedirect(route('products.index'));

        $product = $user->products()->firstOrFail();
        $productImages = $product->images()->get();

        $this->assertCount(10, $productImages);
        $this->assertSame(
            array_fill(0, 10, ProductImage::privateStorageDisk()),
            $productImages->pluck('storage_disk')->all(),
        );
        $this->assertCount(20, Storage::disk(ProductImage::privateStorageDisk())->allFiles('products/'.$user->id));
    }

    public function test_product_registration_saves_description_and_redirects_to_product_list(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'description_base' => '商品の状態と注意点の説明です。',
            ])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'description_base' => '商品の状態と注意点の説明です。',
            'purchase_accounting_enabled' => false,
        ], 'furimadeck');
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_product_form_and_endpoint_do_not_create_or_edit_accounting_data(): void
    {
        $user = $this->premiumUser();
        $payload = [
            ...$this->productPayload(),
            'purchase_date' => '2026-10-12',
            'purchase_accounting_enabled' => '1',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence()],
            'purchase_payment_sources' => ['freee' => 'untrusted-account-id'],
            'purchase_special_case' => ['enabled' => '1', 'counterparty_name' => 'not-accepted'],
        ];

        $this->actingAs($user)->get(route('products.create'))
            ->assertOk()
            ->assertDontSee('仕入情報')
            ->assertDontSee('purchase_accounting_enabled')
            ->assertDontSee('purchase_evidence')
            ->assertDontSee('purchase_payment_sources')
            ->assertDontSee('name="purchase_unit_cost"', false)
            ->assertDontSee('name="purchase_date"', false)
            ->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('証憑の税区分')
            ->assertDontSee('古物商等の特例');

        $this->actingAs($user)->post(route('products.store'), $payload)
            ->assertRedirect(route('products.index'));

        $product = $user->products()->firstOrFail();
        $this->assertFalse($product->purchase_accounting_enabled);
        $this->assertNull($product->purchase_evidence);
        $this->assertNull($product->purchase_payment_sources);
        $this->assertNull($product->purchase_special_case_data);
        $this->assertSame(0, (int) $product->purchase_unit_cost);
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());

        $product->update([
            'purchase_date' => '2026-10-12',
            'purchase_unit_cost' => 1200,
            'purchase_shipping_cost' => 100,
            'other_purchase_expense' => 50,
        ]);
        $this->actingAs($user)->get(route('products.edit', $product))
            ->assertOk()
            ->assertDontSee('証憑の税区分')
            ->assertDontSee('purchase_evidence')
            ->assertDontSee('仕入会計データ')
            ->assertDontSee('name="purchase_unit_cost"', false)
            ->assertDontSee('name="purchase_date"', false)
            ->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('仕入情報')
            ->assertDontSee('会計データで仕入情報を入力・確認')
            ->assertDontSee('既存仕入の手動照合')
            ->assertDontSee('古物商等の特例');

        $this->actingAs($user)->put(route('products.update', $product), [
            ...$this->productPayload(),
            'purchase_date' => '2025-01-01',
            'purchase_unit_cost' => 9999,
            'purchase_shipping_cost' => 888,
            'other_purchase_expense' => 777,
            'supplier_id' => 999999,
        ])->assertRedirect(route('products.index'));

        $this->assertSame('2026-10-12', $product->fresh()->purchase_date?->toDateString());
        $this->assertSame(1200, (int) $product->fresh()->purchase_unit_cost);
        $this->assertSame(100, (int) $product->fresh()->purchase_shipping_cost);
        $this->assertSame(50, (int) $product->fresh()->other_purchase_expense);
    }

    public function test_accounting_purchase_info_form_saves_product_costs_and_supplier(): void
    {
        $user = $this->verifiedUser();
        $product = $this->accountingProductFor($user);
        $supplier = $user->suppliers()->create(['name' => '仕入先テスト', 'type' => 'store']);
        $marketplace = Marketplace::query()->create([
            'code' => 'purchase-info-profit-test',
            'name' => '仕入情報利益テスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => $product->product_name,
            'listing_price' => 5000,
            'listing_quantity' => 1,
            'expected_fee_rate' => 10,
            'expected_profit' => 0,
            'expected_margin' => 0,
            'shipping_fee' => 200,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->assertOk()
            ->assertSee('この画面では仕入日・仕入金額・仕入先を管理します。')
            ->assertSee('name="purchase_unit_cost"', false)
            ->assertSee('name="purchase_date"', false)
            ->assertSee('name="supplier_id"', false);

        $this->actingAs($user)
            ->put(route('furimadeck-accounting.products.purchase-info.update', $product), [
                'purchase_date' => '2026-10-12',
                'purchase_unit_cost' => 2500,
                'purchase_shipping_cost' => 180,
                'other_purchase_expense' => 40,
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('furimadeck-accounting.products.purchase-info.edit', $product));

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'purchase_unit_cost' => 2500,
            'purchase_shipping_cost' => 180,
            'other_purchase_expense' => 40,
            'supplier_id' => $supplier->id,
        ], 'furimadeck');
        $this->assertSame('2026-10-12', $product->fresh()->purchase_date?->toDateString());
        $this->assertTrue($product->fresh()->purchase_info_confirmed);
        $this->assertSame(1800, (int) $listing->fresh()->expected_profit);
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());

        $this->actingAs($user)->put(route('products.update', $product), [
            'internal_sku' => $product->internal_sku,
            'product_name' => '商品情報だけを更新',
            'condition' => 'used',
        ])->assertRedirect(route('products.index'));

        $this->assertTrue($product->fresh()->purchase_info_confirmed);
        $this->assertSame(2500, (int) $product->fresh()->purchase_unit_cost);

        $this->actingAs($user)->get(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertRedirect(route('furimadeck-billing.index'));
    }

    public function test_accounting_purchase_info_rejects_values_above_database_unsigned_integer_limit(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user);

        $this->actingAs($user)
            ->from(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->put(route('furimadeck-accounting.products.purchase-info.update', $product), [
                'purchase_unit_cost' => SaleProfitCalculator::MAX_UNSIGNED_AMOUNT + 1,
                'purchase_shipping_cost' => 0,
                'other_purchase_expense' => 0,
                'supplier_id' => null,
            ])
            ->assertRedirect(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->assertSessionHasErrors('purchase_unit_cost');

        $this->assertSame(1200, (int) $product->fresh()->purchase_unit_cost);
    }

    public function test_sale_recording_is_blocked_until_new_product_purchase_info_is_confirmed(): void
    {
        $user = $this->verifiedUser();
        $product = $this->productFor($user);
        $product->update(['purchase_info_confirmed' => false]);

        try {
            app(SaleLifecycleService::class)->record($user, ['product_id' => $product->id, 'quantity' => 1]);
            $this->fail('A sale must not be recorded with unconfirmed purchase information.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('売上を記録する前に、会計データ画面で仕入情報を入力・確認してください。', $exception->getMessage());
        }

        $this->assertSame(1, (int) $product->fresh()->quantity_available);
        $this->assertSame(0, $product->sales()->count());
    }

    public function test_antique_dealer_form_explains_supplier_details_in_accounting_memos_and_exports(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user);

        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertDontSee('purchase_evidence')
            ->assertDontSee('purchase_payment_sources')
            ->assertDontSee('古物商等の特例');

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertOk()
            ->assertViewIs('furimadeck_accounting.purchase-product')
            ->assertDontSee('出品先・出品価格')
            ->assertDontSee('更新する')
            ->assertSee('name="purchase_special_case[enabled]"', false)
            ->assertSee('name="purchase_special_case[counterparty_address]"', false)
            ->assertSee('ログや通常の商品CSVには平文で含めません。')
            ->assertSee('対応する会計CSVに含める場合')
            ->assertSee('Money Forwardの仕入CSVは必要な区分を正確に表現できないため出力対象外です。');
    }

    public function test_explicit_new_purchase_registration_creates_unconfirmed_entry_with_document_snapshot(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user, [
            'purchase_shipping_cost' => 180,
            'other_purchase_expense' => 20,
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => [
                'purchase_unit_cost' => $this->evidence('reduced_8', 'unqualified'),
                'purchase_shipping_cost' => $this->evidence('standard_10', 'qualified'),
                'other_purchase_expense' => $this->evidence('out_of_scope', 'not_applicable', 'other'),
            ],
        ])->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product));

        $product->refresh();
        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        $this->assertSame($product->id, $entry->product_id);
        $this->assertSame('2026-10-12', $entry->transaction_date->toDateString());
        $this->assertSame(1400, $entry->purchase_cost + $entry->other_cost);
        $this->assertSame('reduced_8', $entry->expense_breakdown['evidence']['purchase_unit_cost']['tax_category']);
        $this->assertSame('unqualified', $entry->expense_breakdown['evidence']['purchase_unit_cost']['invoice_issuer']);
        $this->assertSame('standard_10', $entry->expense_breakdown['evidence']['purchase_shipping_cost']['tax_category']);
        $this->assertSame('out_of_scope', $entry->expense_breakdown['evidence']['other_purchase_expense']['tax_category']);
        $this->assertNotEmpty($entry->expense_breakdown['evidence']['purchase_unit_cost']['reviewed_at']);
        $this->assertSame('ready', $entry->source_status);
        $this->assertSame('not_synced', $entry->sync_status);
        $this->assertNull($entry->confirmed_at);

        $user->update(['subscription_plan' => User::SUBSCRIPTION_ACTIVE, 'subscription_status' => 'active']);
        $this->actingAs($user)->post(route('furimadeck-accounting.confirm'), [
            'year' => 2026,
            'accounting_entry_id' => $entry->id,
            'reviewed_source' => '1',
        ])->assertSessionHas('error', fn (string $message): bool => str_contains($message, '確認対象ではありません'));
        $this->assertSame('ready', $entry->fresh()->source_status);
        $this->assertNull($entry->fresh()->confirmed_at);
    }

    public function test_antique_dealer_special_case_is_encrypted_on_product_and_accounting_entry(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user);
        $privateData = [
            'license_number' => '東京都公安委員会 第123456789012号',
            'counterparty_name' => '特例取引先テスト名',
            'counterparty_address' => '東京都千代田区テスト町1-2-3',
        ];

        $this->actingAs($user)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence('standard_10', 'unqualified')],
            'purchase_special_case' => [
                'enabled' => '1',
                ...$privateData,
                'confirmations' => [
                    'dealer_license' => '1',
                    'qualifying_used_goods' => '1',
                    'resale_inventory' => '1',
                    'supplier_unqualified' => '1',
                    'not_specific_metal_scrap' => '1',
                ],
            ],
        ])->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product));

        $product->refresh();
        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        foreach ([$product, $entry] as $record) {
            $encryptedValue = $record->getRawOriginal('purchase_special_case_data');
            $this->assertIsString($encryptedValue);
            foreach ($privateData as $value) {
                $this->assertStringNotContainsString($value, $encryptedValue);
            }
            $this->assertArrayNotHasKey('purchase_special_case_data', $record->toArray());
            $this->assertSame($privateData['counterparty_name'], $record->purchase_special_case_data['counterparty_name']);
        }

        $this->assertSame('antique_dealer', $entry->purchase_special_case_data['scheme']);
        $this->assertSame([
            'dealer_license' => true,
            'qualifying_used_goods' => true,
            'resale_inventory' => true,
            'supplier_unqualified' => true,
            'not_specific_metal_scrap' => true,
        ], $entry->purchase_special_case_data['confirmations']);

        $sensitivePayload = [
            'partner_name' => $privateData['counterparty_name'],
            'partner_address' => $privateData['counterparty_address'],
        ];
        $sync = AccountingEntrySync::create([
            'accounting_entry_id' => $entry->id,
            'provider' => 'freee',
            'status' => 'sending',
            'request_payload' => ['company_id' => 123, 'details' => []],
            'sensitive_request_payload' => $sensitivePayload,
        ]);
        $encryptedPayload = $sync->getRawOriginal('sensitive_request_payload');
        $this->assertIsString($encryptedPayload);
        foreach ($privateData as $value) {
            $this->assertStringNotContainsString($value, $encryptedPayload);
        }
        $this->assertSame($sensitivePayload, $sync->sensitive_request_payload);
        $this->assertArrayNotHasKey('sensitive_request_payload', $sync->toArray());
        $this->assertSame(['company_id' => 123, 'details' => []], $sync->request_payload);

        $auditAfter = (string) DB::table('audit_logs')
            ->where('entity_type', Product::class)
            ->where('entity_id', $product->id)
            ->value('after_json');
        foreach ($privateData as $value) {
            $this->assertStringNotContainsString($value, $auditAfter);
        }
    }

    public function test_antique_dealer_special_case_requires_private_details_and_all_eligibility_confirmations(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user);
        $accountingRoute = route('furimadeck-accounting.products.purchase.edit', $product);
        $this->actingAs($user)->from($accountingRoute)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence('standard_10', 'unqualified')],
            'purchase_special_case' => ['enabled' => '1'],
        ])->assertRedirect($accountingRoute)
            ->assertSessionHasErrors([
                'purchase_special_case.license_number',
                'purchase_special_case.counterparty_name',
                'purchase_special_case.counterparty_address',
                'purchase_special_case.confirmations.dealer_license',
                'purchase_special_case.confirmations.qualifying_used_goods',
                'purchase_special_case.confirmations.resale_inventory',
                'purchase_special_case.confirmations.supplier_unqualified',
                'purchase_special_case.confirmations.not_specific_metal_scrap',
            ])
            ->assertSessionHas('_old_input', fn (array $input): bool => ! array_key_exists('purchase_special_case', $input));

        $this->assertFalse($product->fresh()->purchase_accounting_enabled);
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_antique_dealer_special_case_cannot_be_selected_for_a_qualified_invoice_issuer(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user);
        $accountingRoute = route('furimadeck-accounting.products.purchase.edit', $product);
        $this->actingAs($user)->from($accountingRoute)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence('standard_10', 'qualified')],
            'purchase_special_case' => [
                'enabled' => '1',
                'license_number' => 'TEST-LICENSE',
                'counterparty_name' => '取引先',
                'counterparty_address' => '住所',
                'confirmations' => [
                    'dealer_license' => '1',
                    'qualifying_used_goods' => '1',
                    'resale_inventory' => '1',
                    'supplier_unqualified' => '1',
                    'not_specific_metal_scrap' => '1',
                ],
            ],
        ])->assertRedirect($accountingRoute)
            ->assertSessionHasErrors('purchase_special_case.enabled');

        $this->assertFalse($product->fresh()->purchase_accounting_enabled);
    }

    public function test_purchase_payment_sources_are_selected_per_connected_provider_and_snapshotted(): void
    {
        $user = $this->premiumUser();
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'freee',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => ['purchase_payment_accounts' => ['freee-bank' => ['name' => 'freee普通預金']]],
        ]);
        AccountingConnection::query()->create([
            'user_id' => $user->id,
            'provider' => 'money_forward',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'status' => 'connected',
            'settings' => ['purchase_payment_accounts' => ['mf-bank' => ['name' => 'MF事業用預金']]],
        ]);
        $product = $this->accountingProductFor($user);

        $this->actingAs($user)->get(route('furimadeck-accounting.products.purchase.edit', $product))
            ->assertOk()
            ->assertSee('freeeで実際に使った支払元')
            ->assertSee('freee普通預金')
            ->assertSee('Money Forwardで実際に使った支払元')
            ->assertSee('MF事業用預金');

        $payload = [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence()],
            'purchase_payment_sources' => ['freee' => 'freee-bank'],
        ];

        $accountingRoute = route('furimadeck-accounting.products.purchase.edit', $product);
        $updateRoute = route('furimadeck-accounting.products.purchase.update', $product);
        $this->actingAs($user)->from($accountingRoute)->post($updateRoute, $payload)
            ->assertRedirect($accountingRoute)
            ->assertSessionHasErrors('purchase_payment_sources.money_forward');
        $this->assertFalse($product->fresh()->purchase_accounting_enabled);

        $payload['purchase_payment_sources']['money_forward'] = 'not-configured';
        $this->actingAs($user)->from($accountingRoute)->post($updateRoute, $payload)
            ->assertRedirect($accountingRoute)
            ->assertSessionHasErrors('purchase_payment_sources.money_forward');
        $this->assertFalse($product->fresh()->purchase_accounting_enabled);

        $payload['purchase_payment_sources']['money_forward'] = 'mf-bank';
        $this->actingAs($user)->post($updateRoute, $payload)->assertRedirect($accountingRoute);

        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        $expectedSources = ['freee' => 'freee-bank', 'money_forward' => 'mf-bank'];
        $this->assertSame($expectedSources, $product->fresh()->purchase_payment_sources);
        $this->assertSame($expectedSources, $entry->expense_breakdown['payment_sources']);
    }

    public function test_new_purchase_accounting_requires_date_and_receipt_classification(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user, ['purchase_date' => null]);
        $accountingRoute = route('furimadeck-accounting.products.purchase.edit', $product);

        $this->actingAs($user)->from($accountingRoute)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
        ])->assertRedirect($accountingRoute)
            ->assertSessionHasErrors('purchase_date')
            ->assertSessionHas('errors', fn ($errors) => $errors->first('purchase_date') === '会計データを作成する前に、会計データの仕入情報画面で仕入日を登録してください。');

        $product->update(['purchase_date' => '2026-10-12']);
        $this->actingAs($user)->from($accountingRoute)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
        ])->assertRedirect($accountingRoute)
            ->assertSessionHasErrors([
                'purchase_evidence.purchase_unit_cost.document_type',
                'purchase_evidence.purchase_unit_cost.tax_category',
                'purchase_evidence.purchase_unit_cost.invoice_issuer',
                'purchase_evidence.purchase_unit_cost.reviewed',
            ]);

        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_each_nonzero_purchase_expense_requires_its_own_evidence_classification(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user, ['purchase_shipping_cost' => 180]);
        $accountingRoute = route('furimadeck-accounting.products.purchase.edit', $product);
        $this->actingAs($user)->from($accountingRoute)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence()],
        ])->assertRedirect($accountingRoute)
            ->assertSessionHasErrors([
                'purchase_evidence.purchase_shipping_cost.document_type',
                'purchase_evidence.purchase_shipping_cost.tax_category',
                'purchase_evidence.purchase_shipping_cost.invoice_issuer',
                'purchase_evidence.purchase_shipping_cost.reviewed',
            ]);

        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_unclassified_purchase_evidence_is_current_but_not_sendable(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user, ['other_purchase_expense' => 25]);
        $this->actingAs($user)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => [
                'purchase_unit_cost' => $this->evidence(),
                'other_purchase_expense' => $this->evidence('unknown'),
            ],
        ])->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product));

        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        $service = app(FurimaDeckAccountingEntryService::class);

        $this->assertTrue($service->isCurrent($entry));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('仕入証憑の税区分・発行者区分または仕訳日との一致が未確認');
        $service->assertCurrent($entry);
    }

    public function test_editing_unexported_new_purchase_refreshes_snapshot_and_requires_a_new_review(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user);
        $this->actingAs($user)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence()],
        ])->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product));

        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);

        $this->assertFalse($product->manualPurchaseReconciliations()->exists());
        $this->assertFalse($product->sales()->exists());
        $this->assertSame('not_synced', $entry->sync_status);
        $this->assertNull($entry->external_transaction_id);
        $this->assertSame(0, $entry->providerSyncs()->whereIn('status', ['queued', 'sending', 'synced', 'sync_unknown', 'legacy_unknown', 'csv_pending', AccountingEntrySync::STATUS_CSV_IMPORTED, AccountingEntrySync::STATUS_MANUALLY_RECONCILED])->count());

        $this->actingAs($user)->from(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->put(route('furimadeck-accounting.products.purchase-info.update', $product), [
                'purchase_date' => '2026-10-13',
                'purchase_unit_cost' => 1250,
                'purchase_shipping_cost' => 180,
                'other_purchase_expense' => 20,
            ])->assertRedirect(route('furimadeck-accounting.products.purchase-info.edit', $product));

        $this->assertSame('2026-10-12', $entry->fresh()->transaction_date->toDateString());
        $this->assertSame(1200, $entry->fresh()->purchase_cost);

        $this->actingAs($user)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'purchase_evidence' => [
                'purchase_unit_cost' => $this->evidence('reduced_8', 'unqualified', 'invoice'),
                'purchase_shipping_cost' => $this->evidence('standard_10', 'qualified'),
                'other_purchase_expense' => $this->evidence('out_of_scope', 'not_applicable', 'other'),
            ],
        ])->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product));

        $this->assertSame('2026-10-13', $entry->fresh()->transaction_date->toDateString());
        $this->assertSame(1250, $entry->fresh()->purchase_cost);
        $this->assertSame(200, $entry->fresh()->other_cost);
        $this->assertSame('review', $entry->fresh()->source_status);
        $this->assertNull($entry->fresh()->confirmed_at);
        $this->assertSame('reduced_8', $entry->fresh()->expense_breakdown['evidence']['purchase_unit_cost']['tax_category']);
        $this->assertSame('unqualified', $entry->fresh()->expense_breakdown['evidence']['purchase_unit_cost']['invoice_issuer']);
    }

    public function test_editing_a_csv_imported_purchase_does_not_change_its_accounting_source(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user);
        $this->actingAs($user)->post(route('furimadeck-accounting.products.purchase.update', $product), [
            'confirm_new_purchase' => '1',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence()],
        ])->assertRedirect(route('furimadeck-accounting.products.purchase.edit', $product));
        $entry = $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->firstOrFail();
        $entry->providerSyncs()->create([
            'provider' => 'freee',
            'status' => AccountingEntrySync::STATUS_CSV_IMPORTED,
            'external_import_id' => 'verified-purchase-import',
        ]);

        $this->actingAs($user)->from(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->put(route('furimadeck-accounting.products.purchase-info.update', $product), [
                'purchase_date' => '2026-10-14',
                'purchase_unit_cost' => 1500,
            ])->assertRedirect(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->assertSessionHasErrors('purchase_info');

        $this->assertSame(1200, $product->fresh()->purchase_unit_cost);
        $this->assertSame('2026-10-12', $product->fresh()->purchase_date?->toDateString());
        $this->assertSame('2026-10-12', $entry->fresh()->transaction_date->toDateString());
        $this->assertSame('ready', $entry->fresh()->source_status);
        $this->assertTrue(app(FurimaDeckAccountingEntryService::class)->isCurrent($entry->fresh()));
    }

    public function test_editing_an_existing_product_cannot_promote_it_to_a_new_purchase_entry(): void
    {
        $user = $this->verifiedUser();
        $product = $this->productFor($user);

        $this->actingAs($user)->put(route('products.update', $product), [
            ...$this->productPayload(),
            'purchase_accounting_enabled' => '1',
            'purchase_date' => '2026-10-12',
            'purchase_evidence' => ['purchase_unit_cost' => $this->evidence()],
        ])->assertRedirect(route('products.index'));

        $this->assertFalse($product->fresh()->purchase_accounting_enabled);
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_product_registration_and_edit_form_support_an_optional_owned_supplier(): void
    {
        $user = $this->verifiedUser();
        $supplier = $user->suppliers()->create(['name' => 'テスト仕入先', 'type' => 'online_shop']);

        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('テスト仕入先');

        $this->actingAs($user)
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('products.index'));

        $product = $user->products()->firstOrFail();
        $this->assertNull($product->supplier_id);

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('会計データで仕入情報を入力・確認')
            ->assertDontSee('仕入情報');

        $this->actingAs($user)
            ->put(route('furimadeck-accounting.products.purchase-info.update', $product), [
                'purchase_unit_cost' => 900,
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('furimadeck-accounting.products.purchase-info.edit', $product));

        $this->assertSame($supplier->id, $product->fresh()->supplier_id);
    }

    public function test_product_registration_and_edit_form_save_acquisition_date_and_expenses(): void
    {
        $user = $this->verifiedUser();
        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertDontSee('仕入情報')
            ->assertDontSee('name="purchase_date"', false)
            ->assertDontSee('name="purchase_shipping_cost"', false)
            ->assertDontSee('name="other_purchase_expense"', false)
            ->assertDontSee('外部サービスへはまだ送信されません')
            ->assertDontSee('証憑の税区分');

        $this->actingAs($user)
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'purchase_date' => '2026-10-03',
                'purchase_unit_cost' => 500,
                'purchase_shipping_cost' => 650,
                'other_purchase_expense' => 120,
            ])
            ->assertRedirect(route('products.index'));

        $product = $user->products()->firstOrFail();
        $this->assertNull($product->purchase_date);
        $this->assertSame(0, $product->purchase_unit_cost);
        $this->assertSame(0, $product->purchase_shipping_cost);
        $this->assertSame(0, $product->other_purchase_expense);
        $this->assertFalse($product->purchase_info_confirmed);

        $this->actingAs($user)
            ->put(route('furimadeck-accounting.products.purchase-info.update', $product), [
                'purchase_date' => '2026-10-03',
                'purchase_unit_cost' => 500,
                'purchase_shipping_cost' => 650,
                'other_purchase_expense' => 120,
            ])
            ->assertRedirect(route('furimadeck-accounting.products.purchase-info.edit', $product));

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertDontSee('name="purchase_date"', false)
            ->assertDontSee('name="purchase_unit_cost"', false)
            ->assertDontSee('会計データで仕入情報を入力・確認')
            ->assertDontSee('仕入情報')
            ->assertDontSee('証憑の税区分');

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->assertOk()
            ->assertSee('value="2026-10-03"', false)
            ->assertSee('value="500"', false)
            ->assertSee('value="650"', false)
            ->assertSee('value="120"', false);
    }

    public function test_product_registration_rejects_another_users_supplier(): void
    {
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();
        $supplier = $otherUser->suppliers()->create(['name' => '他ユーザーの仕入先', 'type' => 'store']);

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload())
            ->assertRedirect(route('products.index'));
        $product = $user->products()->firstOrFail();

        $this->actingAs($user)
            ->from(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->put(route('furimadeck-accounting.products.purchase-info.update', $product), [
                'purchase_unit_cost' => 900,
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->assertSessionHasErrors('supplier_id');

        $this->assertNull($product->fresh()->supplier_id);
    }

    public function test_product_registration_rejects_an_image_over_two_megabytes(): void
    {
        $user = $this->verifiedUser();

        $this->actingAs($user)
            ->from(route('products.create'))
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'images' => [UploadedFile::fake()->create('too-large.jpg', 2049, 'image/jpeg')],
            ])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, $user->products()->count());
    }

    public function test_product_image_processor_stores_webp_main_and_thumbnail_with_private_paths(): void
    {
        $user = $this->verifiedUser();
        $result = app(ProductImageProcessor::class)->process(
            UploadedFile::fake()->image('large.jpg', 2400, 1200),
            (int) $user->id,
        );

        $disk = Storage::disk(ProductImage::PRIVATE_STORAGE_DISK);
        $this->assertSame('image/webp', $result['mime_type']);
        $this->assertStringEndsWith('.webp', $result['original_path']);
        $this->assertStringEndsWith('.thumb.webp', $result['derived_path']);
        $this->assertTrue($disk->exists($result['original_path']));
        $this->assertTrue($disk->exists($result['derived_path']));
        $this->assertSame((int) config('furimadeck.product_images.max_dimension'), getimagesize($disk->path($result['original_path']))[0]);
        $this->assertSame(400, getimagesize($disk->path($result['derived_path']))[0]);
        $this->assertStringStartsWith('products/'.$user->id.'/', $result['original_path']);
    }

    public function test_product_image_reordering_rejects_duplicate_ids(): void
    {
        $user = $this->verifiedUser();
        $product = $this->productFor($user);
        $images = collect(range(1, 2))->map(fn (int $position) => $product->images()->create([
            'original_path' => "products/{$user->id}/{$position}.webp",
            'derived_path' => "products/{$user->id}/{$position}.thumb.webp",
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 1,
            'position' => $position,
        ]));

        $this->actingAs($user)
            ->patchJson(route('products.images.order', $product), [
                'image_ids' => [$images[0]->id, $images[0]->id],
            ])
            ->assertStatus(422);
    }

    public function test_other_user_cannot_read_reorder_or_delete_product_images(): void
    {
        $owner = $this->verifiedUser();
        $other = $this->verifiedUser();
        $product = $this->productFor($owner);
        $image = $product->images()->create([
            'original_path' => "products/{$owner->id}/private.webp",
            'derived_path' => "products/{$owner->id}/private.thumb.webp",
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 1,
            'position' => 0,
        ]);

        $this->actingAs($other)
            ->get(route('products.images.show', [$product, $image, 'original']))
            ->assertNotFound();
        $this->actingAs($other)
            ->patchJson(route('products.images.order', $product), ['image_ids' => [$image->id]])
            ->assertNotFound();
        $this->actingAs($other)
            ->delete(route('products.images.destroy', [$product, $image]))
            ->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    public function test_product_image_files_remain_when_database_delete_fails(): void
    {
        $user = $this->verifiedUser();
        $product = $this->productFor($user);
        $image = $product->images()->create([
            'original_path' => "products/{$user->id}/delete-failure.webp",
            'derived_path' => "products/{$user->id}/delete-failure.thumb.webp",
            'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
            'mime_type' => 'image/webp',
            'file_size' => 1,
            'position' => 0,
        ]);
        $disk = Storage::disk(ProductImage::PRIVATE_STORAGE_DISK);
        $disk->put($image->original_path, 'original');
        $disk->put($image->derived_path, 'thumbnail');
        ProductImage::deleting(static function (): void {
            throw new \RuntimeException('simulated database delete failure');
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->delete(route('products.images.destroy', [$product, $image]));
            $this->fail('The simulated database failure should be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated database delete failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        $this->assertTrue($disk->exists($image->original_path));
        $this->assertTrue($disk->exists($image->derived_path));
    }

    private function verifiedUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function accountingProductFor(User $user, array $overrides = []): Product
    {
        return $user->products()->create(array_merge([
            'internal_sku' => 'ACCOUNTING-'.uniqid(),
            'product_name' => '会計用仕入テスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-10-12',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'purchase_shipping_cost' => 0,
            'other_purchase_expense' => 0,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ], $overrides));
    }

    /** @return array{document_type: string, tax_category: string, invoice_issuer: string, reviewed: string} */
    private function evidence(string $taxCategory = 'standard_10', string $issuer = 'qualified', string $documentType = 'receipt'): array
    {
        return [
            'document_type' => $documentType,
            'tax_category' => $taxCategory,
            'invoice_issuer' => $issuer,
            'reviewed' => '1',
        ];
    }

    /** @return array<string, mixed> */
    private function productPayload(): array
    {
        return [
            'internal_sku' => 'IMAGE-'.uniqid(),
            'product_name' => '画像上限テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 100,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ];
    }

    private function productFor(User $user): Product
    {
        return $user->products()->create([
            'internal_sku' => 'ORDER-'.uniqid(),
            'product_name' => '並び順テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
    }
}
