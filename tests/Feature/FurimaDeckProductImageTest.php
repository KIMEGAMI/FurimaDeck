<?php

namespace Tests\Feature;

use App\Http\Controllers\ProductController;
use App\Models\AccountingConnection;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductInventoryEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\ListingProfitEstimator;
use App\Services\ProductAcquisitionInfoUpdateService;
use App\Services\ProductImageDeletionOutbox;
use App\Services\ProductImageProcessor;
use App\Services\ProductInventoryEventService;
use App\Services\SaleLifecycleService;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FurimaDeckProductImageTest extends TestCase
{
    private const IMAGE_DELETION_OUTBOX_BATCH_SIZE = 100;

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
        $payload = $this->productPayload();

        $this->actingAs($user)
            ->post(route('products.store'), [
                ...$payload,
                'description_base' => '商品の状態と注意点の説明です。',
            ])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'description_base' => '商品の状態と注意点の説明です。',
            'purchase_accounting_enabled' => false,
        ], 'furimadeck');
        $this->assertDatabaseHas('product_inventory_events', [
            'product_id' => $user->products()->where('internal_sku', $payload['internal_sku'])->value('id'),
            'event_type' => ProductInventoryEvent::TYPE_INITIAL_PURCHASE,
            'quantity_delta' => 1,
        ], 'furimadeck');
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_product_form_updates_purchase_master_data_without_creating_accounting_entries(): void
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
            ->assertSee('仕入情報（任意）')
            ->assertDontSee('purchase_accounting_enabled')
            ->assertDontSee('purchase_evidence')
            ->assertDontSee('purchase_payment_sources')
            ->assertSee('name="purchase_unit_cost"', false)
            ->assertSee('name="purchase_date"', false)
            ->assertSee('name="supplier_id"', false)
            ->assertDontSee('証憑の税区分')
            ->assertDontSee('古物商等の特例');

        $this->actingAs($user)->post(route('products.store'), $payload)
            ->assertRedirect(route('products.index'));

        $product = $user->products()->firstOrFail();
        $this->assertFalse($product->purchase_accounting_enabled);
        $this->assertNull($product->purchase_evidence);
        $this->assertNull($product->purchase_payment_sources);
        $this->assertNull($product->purchase_special_case_data);
        $this->assertSame('2026-10-12', $product->purchase_date?->toDateString());
        $this->assertSame(100, (int) $product->purchase_unit_cost);
        $this->assertTrue($product->purchase_info_confirmed);
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());

        $supplier = $user->suppliers()->create(['name' => '商品編集用仕入先', 'type' => 'store']);
        $product->update([
            'purchase_date' => '2026-10-12',
            'purchase_unit_cost' => 1200,
            'purchase_shipping_cost' => 100,
            'other_purchase_expense' => 50,
            'supplier_id' => $supplier->id,
        ]);
        $editResponse = $this->actingAs($user)->get(route('products.edit', $product))
            ->assertOk()
            ->assertDontSee('証憑の税区分')
            ->assertDontSee('purchase_evidence')
            ->assertDontSee('仕入会計データ')
            ->assertSee('name="purchase_unit_cost"', false)
            ->assertSee('name="purchase_date"', false)
            ->assertSee('name="supplier_id"', false)
            ->assertSee('仕入情報')
            ->assertSee('仕入数量は1点です。')
            ->assertDontSee('name="quantity_available"', false)
            ->assertDontSee('会計データで仕入情報を入力・確認')
            ->assertDontSee('既存仕入の手動照合')
            ->assertDontSee('古物商等の特例');
        $html = $editResponse->getContent();
        $productFormStart = strpos($html, 'action="'.route('products.update', $product).'"');
        $purchaseField = strpos($html, 'name="purchase_unit_cost"');
        $productFormEnd = $productFormStart === false ? false : strpos($html, '</form>', $productFormStart);
        $this->assertNotFalse($productFormStart);
        $this->assertNotFalse($purchaseField);
        $this->assertNotFalse($productFormEnd);
        $this->assertLessThan($productFormEnd, $purchaseField, '仕入情報は商品更新フォーム内に配置します。');
        $this->assertFalse(Route::has('furimadeck-accounting.products.purchase-info.update'));

        $this->actingAs($user)->put(route('products.update', $product), [
            ...$this->productPayload(),
            'internal_sku' => $product->internal_sku,
            'purchase_date' => '2025-01-01',
            'purchase_unit_cost' => 9999,
            'purchase_shipping_cost' => 888,
            'other_purchase_expense' => 777,
            'supplier_id' => $supplier->id,
        ])->assertRedirect(route('products.index'));

        $this->assertSame('2025-01-01', $product->fresh()->purchase_date?->toDateString());
        $this->assertSame(9999, (int) $product->fresh()->purchase_unit_cost);
        $this->assertSame(888, (int) $product->fresh()->purchase_shipping_cost);
        $this->assertSame(777, (int) $product->fresh()->other_purchase_expense);
        $this->assertSame($supplier->id, $product->fresh()->supplier_id);
        $this->assertTrue($product->fresh()->purchase_info_confirmed);
        $this->assertDatabaseHas('product_inventory_events', [
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED,
            'quantity_delta' => 0,
        ], 'furimadeck');
        $this->assertSame(1, (int) $product->inventoryEvents()->sum('quantity_delta'));
        $this->assertSame(0, $user->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->count());
    }

    public function test_product_update_rolls_back_when_purchase_info_is_locked_by_a_completed_sale(): void
    {
        $user = $this->verifiedUser();
        $product = $this->accountingProductFor($user);
        $marketplace = Marketplace::query()->create([
            'code' => 'purchase-info-locked-test',
            'name' => '仕入情報ロックテスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $product->sales()->create([
            'user_id' => $user->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 1500,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 1200,
            'net_profit' => 300,
        ]);

        $this->actingAs($user)->from(route('products.edit', $product))->put(route('products.update', $product), [
            ...$this->productPayload(),
            'internal_sku' => $product->internal_sku,
            'product_name' => '変更されてはいけない商品名',
            'purchase_date' => '2025-01-01',
            'purchase_unit_cost' => 9999,
            'purchase_shipping_cost' => 0,
            'other_purchase_expense' => 0,
        ])->assertRedirect(route('products.edit', $product))
            ->assertSessionHasErrors('purchase_info');

        $this->assertSame('会計用仕入テスト商品', $product->fresh()->product_name);
        $this->assertSame('2026-10-12', $product->fresh()->purchase_date?->toDateString());
        $this->assertSame(1200, (int) $product->fresh()->purchase_unit_cost);
        $this->assertDatabaseMissing('product_inventory_events', [
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED,
        ], 'furimadeck');
    }

    public function test_product_update_preserves_inventory_balance_when_route_model_is_stale(): void
    {
        $user = $this->verifiedUser();
        $product = $this->accountingProductFor($user);
        $staleRouteModel = $product->fresh();

        app(ProductInventoryEventService::class)->adjustToQuantity(
            $product,
            0,
            (string) Str::uuid(),
            $user,
            now(),
            '同時更新の回帰テスト',
        );

        $request = Request::create(route('products.update', $product), 'PUT', [
            'internal_sku' => $product->internal_sku,
            'product_name' => '在庫残高を保持した商品',
            'condition' => $product->condition,
        ]);
        $request->setUserResolver(fn () => $user);

        $response = app(ProductController::class)->update(
            $request,
            $staleRouteModel,
            app(AuditLogger::class),
            app(ProductImageProcessor::class),
            app(ProductImageDeletionOutbox::class),
            app(ListingProfitEstimator::class),
            app(ProductAcquisitionInfoUpdateService::class),
        );

        $this->assertSame(route('products.index'), $response->getTargetUrl());
        $this->assertSame(0, (int) $product->fresh()->quantity_available);
        $this->assertSame('out_of_stock', $product->fresh()->inventory_status);
        $this->assertSame(0, (int) $product->inventoryEvents()->sum('quantity_delta'));
    }

    public function test_accounting_purchase_info_is_read_only_and_product_management_updates_master(): void
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
            ->assertSee('仕入情報の登録・編集は商品管理画面に集約しています。')
            ->assertSee(route('products.edit', $product).'#purchase-info', false)
            ->assertDontSee('name="purchase_unit_cost"', false)
            ->assertDontSee('name="purchase_date"', false)
            ->assertDontSee('name="supplier_id"', false);

        $this->actingAs($user)
            ->put(route('products.update', $product), $this->productUpdatePayload($product, [
                'purchase_date' => '2026-10-12',
                'purchase_unit_cost' => 2500,
                'purchase_shipping_cost' => 180,
                'other_purchase_expense' => 40,
                'supplier_id' => $supplier->id,
            ]))
            ->assertRedirect(route('products.index'));

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
        $this->assertDatabaseHas('product_inventory_events', [
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_PURCHASE_INFO_CHANGED,
            'quantity_delta' => 0,
        ], 'furimadeck');
        $this->assertSame(1, (int) $product->inventoryEvents()->sum('quantity_delta'));
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

    public function test_product_management_rejects_purchase_values_above_database_unsigned_integer_limit(): void
    {
        $user = $this->premiumUser();
        $product = $this->accountingProductFor($user);

        $this->actingAs($user)
            ->from(route('products.edit', $product))
            ->put(route('products.update', $product), $this->productUpdatePayload($product, [
                'purchase_unit_cost' => SaleProfitCalculator::MAX_UNSIGNED_AMOUNT + 1,
                'purchase_shipping_cost' => 0,
                'other_purchase_expense' => 0,
                'supplier_id' => null,
            ]))
            ->assertRedirect(route('products.edit', $product))
            ->assertSessionHasErrors('purchase_unit_cost');

        $this->assertSame(1200, (int) $product->fresh()->purchase_unit_cost);
    }

    public function test_sale_recording_is_independent_of_accounting_purchase_confirmation(): void
    {
        $user = $this->verifiedUser();
        $product = $this->productFor($user);
        $product->update(['purchase_info_confirmed' => false]);
        $marketplace = Marketplace::query()->create([
            'code' => 'unconfirmed-purchase-sale',
            'name' => '会計未確認でも販売可能',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $sale = app(SaleLifecycleService::class)->record($user, [
            'product_id' => $product->id,
            'quantity' => 1,
            'marketplace_id' => $marketplace->id,
            'sold_price' => 3000,
            'sold_at' => now(),
            'sales_fee' => 0,
            'shipping_fee' => 0,
        ]);

        $this->assertSame(0, (int) $product->fresh()->quantity_available);
        $this->assertSame('out_of_stock', $product->fresh()->inventory_status);
        $this->assertSame($sale->id, $product->sales()->firstOrFail()->id);
        $this->assertSame(0, $user->accountingEntries()->where('sale_id', $sale->id)->count());
    }

    public function test_sale_form_prefills_sale_price_and_server_calculates_fee_and_profit(): void
    {
        $user = $this->verifiedUser();
        $product = $this->accountingProductFor($user);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-profit-server-test',
            'name' => '利益自動計算テスト',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);
        $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => $product->product_name,
            'listing_price' => 5000,
            'listing_quantity' => 1,
            'expected_fee_rate' => 10,
            'expected_profit' => 3300,
            'shipping_fee' => 200,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.create', ['product_id' => $product->id]))
            ->assertOk()
            ->assertSee('value="5000"', false)
            ->assertSee('value="10"', false);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.store'), [
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'sold_price' => 5000,
                'sales_fee' => 0,
                'sales_fee_rate' => 99,
                'shipping_fee' => 250,
                'sold_at' => now()->toDateString(),
            ])
            ->assertRedirect(route('products.index'));

        $sale = $user->sales()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(1200, (int) $sale->cost_basis);
        $this->assertSame(10.0, (float) $sale->sales_fee_rate);
        $this->assertSame(500, (int) $sale->sales_fee);
        $this->assertSame(250, (int) $sale->shipping_fee);
        $this->assertSame(3050, (int) $sale->net_profit);
        $this->assertSame('sold', $product->listings()->firstOrFail()->status);
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
            ->assertSee('仕入会計データ')
            ->assertDontSee('出品先・出品価格')
            ->assertDontSee('更新する')
            ->assertSee('商品管理に登録した仕入情報を参照し')
            ->assertSee('商品管理の仕入情報をもとに会計明細と証憑を確認・確定してください。')
            ->assertSee('name="purchase_special_case[enabled]"', false)
            ->assertSee('name="purchase_special_case[counterparty_address]"', false)
            ->assertSee('ログや通常の商品CSVには平文で含めません。')
            ->assertSee('対応する会計CSVに含める場合')
            ->assertSee('Money Forwardは特例区分を正確に表現できない明細のCSV出力を停止します。');
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
            ->assertSessionHas('errors', fn ($errors) => $errors->first('purchase_date') === '会計データを作成する前に、商品管理画面で仕入日を登録してください。');

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
        $this->expectException(RuntimeException::class);
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

        $this->actingAs($user)->from(route('products.edit', $product))
            ->put(route('products.update', $product), $this->productUpdatePayload($product, [
                'purchase_date' => '2026-10-13',
                'purchase_unit_cost' => 1250,
                'purchase_shipping_cost' => 180,
                'other_purchase_expense' => 20,
            ]))->assertRedirect(route('products.index'));

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

        $this->actingAs($user)->from(route('products.edit', $product))
            ->put(route('products.update', $product), $this->productUpdatePayload($product, [
                'purchase_date' => '2026-10-14',
                'purchase_unit_cost' => 1500,
            ]))->assertRedirect(route('products.edit', $product))
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
            ->assertSee('id="product-image-camera"', false)
            ->assertSee('capture="environment"', false)
            ->assertSee('id="product-image-camera-choose"', false)
            ->assertSee('写真を撮る')
            ->assertSee('name="supplier_id"', false)
            ->assertSee('テスト仕入先')
            ->assertSee('name="purchase_unit_cost"', false);

        $this->actingAs($user)
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'purchase_unit_cost' => 900,
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('products.index'));

        $product = $user->products()->firstOrFail();
        $this->assertSame($supplier->id, $product->supplier_id);
        $this->assertSame(900, (int) $product->purchase_unit_cost);
        $this->assertTrue($product->purchase_info_confirmed);

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('name="supplier_id"', false)
            ->assertSee('テスト仕入先')
            ->assertSee('仕入情報');

        $this->actingAs($user)
            ->put(route('products.update', $product), $this->productUpdatePayload($product, [
                'purchase_unit_cost' => 900,
                'supplier_id' => $supplier->id,
            ]))
            ->assertRedirect(route('products.index'));

        $this->assertSame($supplier->id, $product->fresh()->supplier_id);
    }

    public function test_product_registration_and_edit_form_save_acquisition_date_and_expenses(): void
    {
        $user = $this->verifiedUser();
        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('仕入情報（任意）')
            ->assertSee('name="purchase_date"', false)
            ->assertSee('name="purchase_unit_cost"', false)
            ->assertSee('name="purchase_shipping_cost"', false)
            ->assertSee('name="other_purchase_expense"', false)
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
        $this->assertSame('2026-10-03', $product->purchase_date?->toDateString());
        $this->assertSame(500, (int) $product->purchase_unit_cost);
        $this->assertSame(650, (int) $product->purchase_shipping_cost);
        $this->assertSame(120, (int) $product->other_purchase_expense);
        $this->assertTrue($product->purchase_info_confirmed);
        $this->assertDatabaseHas('product_inventory_events', [
            'product_id' => $product->id,
            'event_type' => ProductInventoryEvent::TYPE_INITIAL_PURCHASE,
            'quantity_delta' => 1,
        ], 'furimadeck');

        $this->actingAs($user)
            ->put(route('products.update', $product), $this->productUpdatePayload($product, [
                'purchase_date' => '2026-10-03',
                'purchase_unit_cost' => 500,
                'purchase_shipping_cost' => 650,
                'other_purchase_expense' => 120,
            ]))
            ->assertRedirect(route('products.index'));

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('name="purchase_date"', false)
            ->assertSee('name="purchase_unit_cost"', false)
            ->assertSee('仕入情報')
            ->assertDontSee('証憑の税区分');

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.products.purchase-info.edit', $product))
            ->assertOk()
            ->assertSee('2026/10/03')
            ->assertSee('500円')
            ->assertSee('650円')
            ->assertSee('120円')
            ->assertDontSee('name="purchase_date"', false);
    }

    public function test_product_registration_rejects_another_users_supplier(): void
    {
        $user = $this->verifiedUser();
        $otherUser = $this->verifiedUser();
        $supplier = $otherUser->suppliers()->create(['name' => '他ユーザーの仕入先', 'type' => 'store']);

        $this->actingAs($user)
            ->from(route('products.create'))
            ->post(route('products.store'), [
                ...$this->productPayload(),
                'purchase_unit_cost' => 900,
                'supplier_id' => $supplier->id,
            ])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('supplier_id');
        $this->assertSame(0, $user->products()->count());

        $this->actingAs($user)
            ->post(route('products.store'), $this->productPayload())
            ->assertRedirect(route('products.index'));
        $product = $user->products()->firstOrFail();

        $this->actingAs($user)
            ->from(route('products.edit', $product))
            ->put(route('products.update', $product), $this->productUpdatePayload($product, [
                'purchase_unit_cost' => 900,
                'supplier_id' => $supplier->id,
            ]))
            ->assertRedirect(route('products.edit', $product))
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
            throw new RuntimeException('simulated database delete failure');
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->delete(route('products.images.destroy', [$product, $image]));
            $this->fail('The simulated database failure should be propagated.');
        } catch (RuntimeException $exception) {
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

    /**
     * @param  array<string, mixed>  $purchaseValues
     * @return array<string, mixed>
     */
    private function productUpdatePayload(Product $product, array $purchaseValues = []): array
    {
        return array_merge([
            'internal_sku' => $product->internal_sku,
            'product_name' => $product->product_name,
            'condition' => $product->condition,
        ], $purchaseValues);
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

    public function test_failed_object_deletion_is_retained_for_retry_and_removed_after_success(): void
    {
        $user = $this->verifiedUser();
        $outbox = app(ProductImageDeletionOutbox::class);
        $objectKey = 'products/'.$user->id.'/retry.webp';
        $ids = $outbox->enqueuePathsByDisk(['local' => [$objectKey]], (int) $user->id);
        $adapter = Mockery::mock();
        $adapter->shouldReceive('delete')->twice()->andReturn(false, true);
        Storage::shouldReceive('disk')->with('local')->twice()->andReturn($adapter);

        $firstAttempt = $outbox->process($ids);
        $this->assertSame(1, $firstAttempt['pending']);
        $this->assertDatabaseHas('product_image_deletion_outbox', [
            'id' => $ids[0],
            'object_key' => $objectKey,
            'attempts' => 1,
        ], 'furimadeck');

        $secondAttempt = $outbox->process($ids);
        $this->assertSame(0, $secondAttempt['pending']);
        $this->assertDatabaseMissing('product_image_deletion_outbox', ['id' => $ids[0]], 'furimadeck');
    }

    public function test_object_deletion_exception_is_retained_for_retry_without_logging_object_key(): void
    {
        $user = $this->verifiedUser();
        $outbox = app(ProductImageDeletionOutbox::class);
        $objectKey = 'products/'.$user->id.'/exception-retry.webp';
        $ids = $outbox->enqueuePathsByDisk(['local' => [$objectKey]], (int) $user->id);
        $adapter = Mockery::mock();
        $attempt = 0;
        $adapter->shouldReceive('delete')->twice()->andReturnUsing(function () use (&$attempt): bool {
            if ($attempt++ === 0) {
                throw new RuntimeException('Synthetic storage failure');
            }

            return true;
        });
        Storage::shouldReceive('disk')->with('local')->twice()->andReturn($adapter);
        Log::shouldReceive('warning')
            ->once()
            ->with('Product image deletion outbox has pending objects.', ['pending_count' => 1]);

        $firstAttempt = $outbox->process($ids);
        $this->assertSame(1, $firstAttempt['pending']);
        $this->assertDatabaseHas('product_image_deletion_outbox', [
            'id' => $ids[0],
            'object_key' => $objectKey,
            'attempts' => 1,
            'last_error_class' => RuntimeException::class,
        ], 'furimadeck');

        $secondAttempt = $outbox->process($ids);
        $this->assertSame(0, $secondAttempt['pending']);
        $this->assertDatabaseMissing('product_image_deletion_outbox', ['id' => $ids[0]], 'furimadeck');
    }

    public function test_new_image_deletion_requests_are_not_starved_by_older_failures(): void
    {
        $user = $this->verifiedUser();
        $outbox = app(ProductImageDeletionOutbox::class);
        $initialPaths = array_map(
            fn (int $number): string => 'products/'.$user->id.'/queued-'.$number.'.webp',
            range(1, self::IMAGE_DELETION_OUTBOX_BATCH_SIZE + 2),
        );
        $outbox->enqueuePathsByDisk(['local' => $initialPaths], (int) $user->id);
        $adapter = Mockery::mock();
        $attempt = 0;
        $adapter->shouldReceive('delete')
            ->times(self::IMAGE_DELETION_OUTBOX_BATCH_SIZE * 2)
            ->andReturnUsing(function () use (&$attempt): bool {
                return ++$attempt > self::IMAGE_DELETION_OUTBOX_BATCH_SIZE;
            });
        Storage::shouldReceive('disk')
            ->with('local')
            ->times(self::IMAGE_DELETION_OUTBOX_BATCH_SIZE * 2)
            ->andReturn($adapter);

        $firstAttempt = $outbox->process();
        $this->assertSame(self::IMAGE_DELETION_OUTBOX_BATCH_SIZE, $firstAttempt['attempted']);
        $this->assertSame(self::IMAGE_DELETION_OUTBOX_BATCH_SIZE + 2, $firstAttempt['pending']);

        $newIds = $outbox->enqueuePathsByDisk(['local' => [
            'products/'.$user->id.'/new-1.webp',
            'products/'.$user->id.'/new-2.webp',
        ]], (int) $user->id);
        $secondAttempt = $outbox->process();

        $this->assertSame(self::IMAGE_DELETION_OUTBOX_BATCH_SIZE, $secondAttempt['attempted']);
        foreach ($newIds as $id) {
            $this->assertDatabaseMissing('product_image_deletion_outbox', ['id' => $id], 'furimadeck');
        }
    }
}
