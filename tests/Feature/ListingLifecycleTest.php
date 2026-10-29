<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\User;
use App\Services\ListingLifecycleService;
use App\Services\SaleProfitCalculator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ListingLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.connections.sqlite');
        $connection['database'] = ':memory:';
        config()->set('database.connections.furimadeck', $connection);
        config()->set('furimadeck.cutover_enabled', true);
        config()->set('furimadeck.product_management_enabled', true);
        DB::purge('furimadeck');
        DB::setDefaultConnection('furimadeck');
        $this->artisan('migrate', [
            '--database' => 'furimadeck',
            '--path' => 'database/migrations/furimadeck',
        ])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_listing_can_move_to_active_but_cannot_move_back_to_ready(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-001',
            'product_name' => '出品テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-marketplace',
            'name' => '出品テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品タイトル',
            'status' => 'ready',
        ]);

        $active = app(ListingLifecycleService::class)->update($user, $listing, [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品タイトル',
            'status' => 'active',
        ]);

        $this->assertSame('active', $active->status);
        $this->assertNotNull($active->listed_at);

        $this->expectException(RuntimeException::class);
        app(ListingLifecycleService::class)->update($user, $active, [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品タイトル',
            'status' => 'ready',
        ]);
    }

    public function test_partial_listing_price_update_preserves_concurrent_status_and_copy_changes(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-STALE-001',
            'product_name' => '並行更新テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
            'purchase_info_confirmed' => true,
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-stale-marketplace',
            'name' => '並行更新テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '古いタイトル',
            'listing_description' => '古い説明',
            'status' => 'draft',
        ]);
        $staleListing = clone $listing;
        $listing->update([
            'listing_title' => '新しいタイトル',
            'listing_description' => '新しい説明',
            'status' => 'ready',
        ]);

        $updated = app(ListingLifecycleService::class)->update($user, $staleListing, [
            'marketplace_id' => $marketplace->id,
            'listing_price' => 2500,
            'expected_fee_rate' => 0,
            'shipping_fee' => 100,
        ]);

        $this->assertSame('ready', $updated->status);
        $this->assertSame('新しいタイトル', $updated->listing_title);
        $this->assertSame('新しい説明', $updated->listing_description);
        $this->assertSame(2500, $updated->listing_price);
        $this->assertSame(100, $updated->shipping_fee);
    }

    public function test_listing_form_does_not_render_accounting_evidence_inputs(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-ACCOUNTING-BOUNDARY',
            'product_name' => '会計入力非表示テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        Marketplace::query()->create([
            'code' => 'listing-accounting-boundary',
            'name' => '会計入力非表示テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('listings.create', ['product_id' => $product->id]))
            ->assertOk()
            ->assertDontSee('purchase_evidence')
            ->assertDontSee('purchase_payment_sources')
            ->assertDontSee('accounting_expense_details')
            ->assertDontSee('name="purchase_unit_cost"', false)
            ->assertDontSee('name="purchase_shipping_cost"', false)
            ->assertDontSee('name="other_purchase_expense"', false)
            ->assertDontSee('name="purchase_date"', false)
            ->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('name="cost_basis"', false)
            ->assertDontSee('name="packing_cost"', false)
            ->assertDontSee('name="repair_cost"', false)
            ->assertDontSee('name="cleaning_cost"', false)
            ->assertDontSee('name="other_expense"', false)
            ->assertDontSee('証憑の税区分')
            ->assertDontSee('古物商等の特例');
    }

    public function test_product_create_and_edit_forms_keep_accounting_inputs_in_accounting_area(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'PRODUCT-ACCOUNTING-BOUNDARY',
            'product_name' => '商品フォーム境界テスト',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        foreach ([route('products.create'), route('products.edit', $product)] as $url) {
            $this->actingAs($user)
                ->get($url)
                ->assertOk()
                ->assertDontSee('name="purchase_date"', false)
                ->assertDontSee('name="purchase_unit_cost"', false)
                ->assertDontSee('name="purchase_shipping_cost"', false)
                ->assertDontSee('name="other_purchase_expense"', false)
                ->assertDontSee('name="supplier_id"', false)
                ->assertDontSee('name="purchase_evidence', false)
                ->assertDontSee('name="purchase_payment_sources', false)
                ->assertDontSee('name="purchase_special_case', false)
                ->assertDontSee('name="accounting_expense_details', false)
                ->assertDontSee('name="packing_cost"', false)
                ->assertDontSee('name="repair_cost"', false)
                ->assertDontSee('name="cleaning_cost"', false)
                ->assertDontSee('name="other_expense"', false);
        }
    }

    public function test_product_registration_can_create_a_priced_listing_draft(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $marketplace = Marketplace::query()->create([
            'code' => 'initial-listing-marketplace',
            'name' => '初回出品テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('初回出品価格（任意）')
            ->assertSee('initial_listing_price');

        $this->actingAs($user)
            ->post(route('products.store'), [
                'internal_sku' => 'PRODUCT-INITIAL-LISTING-001',
                'product_name' => '初回出品価格テスト',
                'condition' => 'used',
                'purchase_unit_cost' => 1000,
                'initial_marketplace_id' => $marketplace->id,
                'initial_listing_price' => 5000,
            ])
            ->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('listings', [
            'user_id' => $user->id,
            'marketplace_id' => $marketplace->id,
            'listing_price' => 5000,
            'status' => 'draft',
            'expected_profit' => null,
        ], 'furimadeck');

        $product = $user->products()->where('internal_sku', 'PRODUCT-INITIAL-LISTING-001')->firstOrFail();
        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('出品価格')
            ->assertSee('初回出品テスト販売先')
            ->assertSee('¥5,000');

        $this->assertFalse($product->purchase_info_confirmed);
        $this->assertSame(0, (int) $product->purchase_unit_cost);

        $this->actingAs($user)
            ->put(route('furimadeck-accounting.products.purchase-info.update', $product), [
                'purchase_date' => '2026-10-19',
                'purchase_unit_cost' => 1000,
                'purchase_shipping_cost' => 0,
                'other_purchase_expense' => 0,
            ])
            ->assertRedirect(route('furimadeck-accounting.products.purchase-info.edit', $product));

        $this->assertSame(3500, (int) $product->listings()->firstOrFail()->expected_profit);
    }

    public function test_listing_preserves_marketplace_specific_delivery_and_sale_details(): void
    {
        $user = User::factory()->create();
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-DETAIL-001',
            'product_name' => '出品詳細テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-detail-marketplace',
            'name' => '出品詳細テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $listing = app(ListingLifecycleService::class)->create($user, [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '出品詳細タイトル',
            'shipping_payer' => 'seller',
            'sender_region' => '東京都',
            'dispatch_days' => 2,
            'shipping_size' => '60サイズ',
            'shipping_weight_grams' => 500,
            'sale_format' => 'fixed_price',
            'listing_period_days' => 7,
            'return_policy' => '返品不可',
            'purchase_application' => 'disabled',
            'status' => 'draft',
        ]);

        $this->assertSame('seller', $listing->shipping_payer);
        $this->assertSame('東京都', $listing->sender_region);
        $this->assertSame(2, $listing->dispatch_days);
        $this->assertSame('60サイズ', $listing->shipping_size);
        $this->assertSame(500, $listing->shipping_weight_grams);
        $this->assertSame('fixed_price', $listing->sale_format);
        $this->assertSame(7, $listing->listing_period_days);
        $this->assertSame('返品不可', $listing->return_policy);
        $this->assertSame('disabled', $listing->purchase_application);
    }

    public function test_listing_create_page_renders_the_marketplace_readiness_preview(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->products()->create([
            'internal_sku' => 'LISTING-PREVIEW-001',
            'product_name' => '出品確認テスト商品',
            'condition' => 'used',
            'description_base' => 'コピーに使う商品説明',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        Marketplace::query()->create([
            'code' => 'yahoo_auctions',
            'name' => 'Yahoo!オークション',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('listings.create'))
            ->assertOk()
            ->assertSee('出品する商品')
            ->assertSee('商品情報')
            ->assertSee('価格・販売条件')
            ->assertSee('配送情報')
            ->assertSee('公開後の記録')
            ->assertSee('商品ID: LISTING-PREVIEW-001')
            ->assertSee('id="listing-product-preview"', false)
            ->assertSee('商品情報を反映')
            ->assertSee('data-copy-listing-field', false)
            ->assertSee('aria-label="出品タイトルをコピー"', false)
            ->assertSee('aria-label="商品説明をコピー"', false)
            ->assertSee('aria-label="出品価格をコピー"', false)
            ->assertSee('aria-label="配送方法をコピー"', false)
            ->assertDontSee('出品文をコピー')
            ->assertSee('data-product-description="コピーに使う商品説明"', false)
            ->assertSee('出品前確認')
            ->assertSee('Yahoo!オークション');
    }

    public function test_listing_form_exposes_standard_fee_rates_for_supported_marketplaces(): void
    {
        foreach ([
            ['mercari', 'メルカリ', '10'],
            ['yahoo_flea_market', 'Yahoo!フリマ', '5'],
            ['yahoo_auctions', 'Yahoo!オークション', '10'],
            ['rakuma', 'ラクマ', '10'],
        ] as [$code, $name, $rate]) {
            Marketplace::query()->create([
                'code' => $code,
                'name' => $name,
                'base_url' => 'https://example.test',
                'fee_type' => 'percentage',
                'default_fee_rate' => 0,
                'is_active' => true,
            ]);
        }

        $user = User::factory()->create(['email_verified_at' => now()]);
        $response = $this->actingAs($user)->get(route('listings.create'));

        foreach (['10', '5', '10', '10'] as $rate) {
            $response->assertSee('data-fee-rate="'.$rate.'"', false);
        }
    }

    public function test_sale_create_page_offers_marketplace_even_when_listing_is_optional(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-SALE-SYNC-001',
            'product_name' => '販売連携テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-sync-marketplace',
            'name' => '販売連携テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '販売連携テスト出品',
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.create', ['product_id' => $product->id]))
            ->assertOk()
            ->assertSee('商品一覧で選んだ商品の販売日・販売先・売価・販売送料を記録します。販売手数料は自動計算します。')
            ->assertSee('LISTING-SALE-SYNC-001 | 販売連携テスト商品')
            ->assertSee('name="product_id"', false)
            ->assertDontSee('<select required name="product_id"', false)
            ->assertDontSee('name="cost_basis"', false)
            ->assertDontSee('name="purchase_shipping_cost"', false)
            ->assertDontSee('name="packing_cost"', false)
            ->assertDontSee('name="repair_cost"', false)
            ->assertDontSee('name="cleaning_cost"', false)
            ->assertDontSee('name="other_expense"', false)
            ->assertDontSee('出品情報との紐付け')
            ->assertDontSee('name="listing_id"', false);
    }

    public function test_sale_edit_page_keeps_accounting_inputs_in_the_accounting_area(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-EDIT-ACCOUNTING-BOUNDARY',
            'product_name' => '販売編集の境界テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-edit-accounting-boundary',
            'name' => '販売編集の境界テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 1000,
            'sales_fee' => 300,
            'sales_fee_rate' => 10,
            'shipping_fee' => 200,
            'net_profit' => 1500,
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.edit', $sale))
            ->assertOk()
            ->assertSee('販売日')
            ->assertSee('販売先')
            ->assertSee('売上（税込）')
            ->assertSee('販売送料')
            ->assertDontSee('name="cost_basis"', false)
            ->assertDontSee('name="purchase_shipping_cost"', false)
            ->assertDontSee('name="packing_cost"', false)
            ->assertDontSee('name="repair_cost"', false)
            ->assertDontSee('name="cleaning_cost"', false)
            ->assertDontSee('name="other_expense"', false)
            ->assertDontSee('name="accounting_expense_details"', false)
            ->assertDontSee('証憑の税区分')
            ->assertDontSee('仕入原価');
    }

    public function test_sale_create_requires_a_product_selected_from_the_product_list(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.create'))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('error', '販売を記録する商品を商品一覧から選んでください。');
    }

    public function test_sale_create_page_keeps_marketplace_selectable_when_the_product_has_no_listing(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-NO-LISTING-OPTIONS-001',
            'product_name' => '出品情報なし商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        Marketplace::query()->create([
            'code' => 'sale-no-listing-options-marketplace',
            'name' => '直接販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.create', ['product_id' => $product->id]))
            ->assertOk()
            ->assertSee('name="marketplace_id"', false)
            ->assertSee('最新の出品情報を初期選択しています。販売先を変えると標準手数料率が反映されます。')
            ->assertSee('直接販売先')
            ->assertDontSee('<select required name="product_id"', false)
            ->assertDontSee('出品情報との紐付け')
            ->assertDontSee('name="listing_id"', false);
    }

    public function test_sale_automatically_uses_the_latest_listing_marketplace_and_configured_costs(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-AUTO-LISTING-001',
            'product_name' => '出品情報自動判定テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-auto-listing-marketplace',
            'name' => '自動判定販売先',
            'base_url' => 'https://auto.example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 5,
            'is_active' => true,
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '自動判定出品',
            'listing_price' => 5000,
            'expected_fee_rate' => 0,
            'shipping_fee' => 300,
            'status' => 'active',
            'listed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.create', ['product_id' => $product->id]))
            ->assertOk()
            ->assertSee('自動判定販売先')
            ->assertSee('value="'.$marketplace->id.'"', false)
            ->assertSee('name="marketplace_id"', false)
            ->assertSee('id="sales-fee-rate" name="sales_fee_rate" min="0" max="100" step="0.01" type="number" value="5"', false)
            ->assertSee('販売手数料率（自動入力）')
            ->assertDontSee('利益（自動計算）')
            ->assertDontSee('id="sale-profit-preview"', false)
            ->assertDontSee('name="cost_basis"', false)
            ->assertDontSee('name="quantity"', false);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.store'), [
                'product_id' => $product->id,
                'sold_price' => 5000,
                'sold_at' => '2026-10-09',
                'cost_basis' => 999999,
                'purchase_shipping_cost' => 901,
                'packing_cost' => 902,
                'repair_cost' => 903,
                'cleaning_cost' => 904,
                'other_expense' => 905,
                'accounting_expense_details' => ['packing_cost' => ['payment_source' => 'forged']],
                'sales_fee_rate' => 99,
                'sales_fee' => 999999,
                'shipping_fee' => 300,
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'listing_id' => $listing->id,
            'marketplace_id' => $marketplace->id,
            'quantity' => 1,
            'sold_price' => 5000,
            'cost_basis' => 1200,
            'sales_fee' => 250,
            'sales_fee_rate' => '5.00',
            'shipping_fee' => 300,
            'purchase_shipping_cost' => 0,
            'packing_cost' => 0,
            'repair_cost' => 0,
            'cleaning_cost' => 0,
            'other_expense' => 0,
            'net_profit' => 3250,
        ], 'furimadeck');
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'sold'], 'furimadeck');
    }

    public function test_sale_can_use_a_different_marketplace_than_the_product_listing(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-MARKETPLACE-OVERRIDE-001',
            'product_name' => '販売先変更テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $listedMarketplace = Marketplace::query()->create([
            'code' => 'sale-listed-marketplace',
            'name' => '出品中の販売先',
            'base_url' => 'https://listed.example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 5,
            'is_active' => true,
        ]);
        $chosenMarketplace = Marketplace::query()->create([
            'code' => 'sale-chosen-marketplace',
            'name' => '実際に売れた販売先',
            'base_url' => 'https://chosen.example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 8,
            'is_active' => true,
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $listedMarketplace->id,
            'listing_title' => '別販売先にも出品中',
            'expected_fee_rate' => 5,
            'status' => 'active',
            'listed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.store'), [
                'product_id' => $product->id,
                'marketplace_id' => $chosenMarketplace->id,
                'sold_price' => 5000,
                'sold_at' => '2026-10-11',
                'cost_basis' => 1000,
                'sales_fee_rate' => 8,
                'shipping_fee' => 300,
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', [
            'product_id' => $product->id,
            'marketplace_id' => $chosenMarketplace->id,
            'listing_id' => null,
            'sales_fee_rate' => '8.00',
            'sales_fee' => 400,
            'net_profit' => 3300,
        ], 'furimadeck');
        $this->assertDatabaseHas('listings', ['id' => $listing->id, 'status' => 'active'], 'furimadeck');
    }

    public function test_sale_uses_the_marketplace_from_the_most_recently_updated_product_edit_listing_even_when_it_is_a_draft(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-EDITED-MARKETPLACE-001',
            'product_name' => '商品編集販売先参照テスト',
            'condition' => 'used',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $olderMarketplace = Marketplace::query()->create([
            'code' => 'sale-edited-old-marketplace',
            'name' => '古い出品先',
            'base_url' => 'https://old.example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);
        $selectedMarketplace = Marketplace::query()->create([
            'code' => 'sale-edited-new-marketplace',
            'name' => '商品編集で選んだ出品先',
            'base_url' => 'https://new.example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 5,
            'is_active' => true,
        ]);
        $olderListing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $olderMarketplace->id,
            'listing_title' => '以前の出品先',
            'expected_fee_rate' => 10,
            'status' => 'active',
            'listed_at' => now(),
        ]);
        $olderListing->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $editedDraft = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $selectedMarketplace->id,
            'listing_title' => '商品編集で最後に更新した出品先',
            'expected_fee_rate' => 5,
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.create', ['product_id' => $product->id]))
            ->assertOk()
            ->assertSee('<option value="'.$selectedMarketplace->id.'" data-fee-rate="5" selected>商品編集で選んだ出品先（出品情報から自動設定）</option>', false)
            ->assertSee('name="sales_fee_rate" min="0" max="100" step="0.01" type="number" value="5"', false);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.store'), [
                'product_id' => $product->id,
                'sold_price' => 5000,
                'sold_at' => '2026-10-11',
                'cost_basis' => 1200,
                'shipping_fee' => 250,
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', [
            'product_id' => $product->id,
            'marketplace_id' => $selectedMarketplace->id,
            'listing_id' => null,
            'quantity' => 1,
            'sales_fee_rate' => '5.00',
            'sales_fee' => 250,
        ], 'furimadeck');
        $this->assertDatabaseHas('listings', ['id' => $editedDraft->id, 'status' => 'draft'], 'furimadeck');
        $this->assertDatabaseHas('listings', ['id' => $olderListing->id, 'status' => 'active'], 'furimadeck');
    }

    public function test_sale_can_be_recorded_without_a_listing_and_uses_the_selected_marketplace(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-WITHOUT-LISTING-001',
            'product_name' => '出品登録前に売れた商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-without-listing-marketplace',
            'name' => '直接選択販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('furimadeck-sales.store'), [
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_id' => '',
                'sold_price' => 5000,
                'sold_at' => '2026-10-09',
                'cost_basis' => 1000,
                'shipping_fee' => 300,
            ])
            ->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'listing_id' => null,
            'marketplace_id' => $marketplace->id,
            'quantity' => 1,
            'sold_price' => 5000,
            'sales_fee' => 500,
            'shipping_fee' => 300,
            'net_profit' => 3200,
        ], 'furimadeck');
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ], 'furimadeck');
    }

    public function test_sale_registration_rejects_unstorable_profit_and_always_records_one_item(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-BOUNDARY-001',
            'product_name' => '金額境界テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-boundary-marketplace',
            'name' => '金額境界テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $values = [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'sold_price' => SaleProfitCalculator::MAX_SIGNED_PROFIT + 1,
            'sold_at' => '2026-10-09',
            'cost_basis' => 0,
        ];

        $this->actingAs($user)->post(route('furimadeck-sales.store'), $values)
            ->assertSessionHasErrors('sold_price');
        $this->assertSame(0, $user->sales()->count());
        $this->assertSame(1, $product->fresh()->quantity_available);

        $this->actingAs($user)->post(route('furimadeck-sales.store'), [
            ...$values,
            'sold_price' => SaleProfitCalculator::MAX_UNSIGNED_AMOUNT + 1,
        ])->assertSessionHasErrors('sold_price');
        $this->assertSame(0, $user->sales()->count());
        $this->assertSame(1, $product->fresh()->quantity_available);

        $this->actingAs($user)->post(route('furimadeck-sales.store'), [
            ...$values,
            'quantity' => 2,
            'sold_price' => 1000,
        ])->assertRedirect(route('products.index'))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'sold_price' => 1000,
        ], 'furimadeck');
        $this->assertSame(0, $product->fresh()->quantity_available);
    }

    public function test_product_edit_does_not_offer_a_second_sale_recording_form(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'EDIT-NO-SALE-FORM-001',
            'product_name' => '商品編集画面テスト',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertDontSee('data-marketplace-shortcut')
            ->assertDontSee('出品画面を開く')
            ->assertDontSee('拡張機能と連携')
            ->assertDontSee('メルカリ以外は現在、出品画面を開く機能のみです。')
            ->assertSee(route('listings.create', ['product_id' => $product->id]), false)
            ->assertSee('出品情報を登録すると、各項目の横から個別にコピーできます。')
            ->assertDontSee('出品内容をまとめてコピー')
            ->assertDontSee('売れた価格')
            ->assertDontSee('販売手数料率（%）');
    }

    public function test_product_update_ignores_accounting_fields_submitted_outside_the_accounting_area(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'PRODUCT-ACCOUNTING-BOUNDARY-UPDATE',
            'product_name' => '会計情報の境界テスト商品',
            'condition' => 'used',
            'purchase_date' => '2026-02-03',
            'purchase_unit_cost' => 1200,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'purchase_shipping_cost' => 150,
            'other_purchase_expense' => 50,
            'purchase_info_confirmed' => true,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)
            ->put(route('products.update', $product), [
                'internal_sku' => $product->internal_sku,
                'product_name' => $product->product_name,
                'condition' => $product->condition,
                'purchase_date' => '2026-11-01',
                'purchase_unit_cost' => 99999,
                'purchase_quantity' => 9,
                'quantity_available' => 0,
                'purchase_shipping_cost' => 999,
                'other_purchase_expense' => 999,
                'supplier_id' => 999999,
                'purchase_evidence' => ['purchase_unit_cost' => ['tax_category' => 'out_of_scope']],
                'purchase_payment_sources' => ['freee' => '999999'],
            ])
            ->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertSame('2026-02-03', $product->purchase_date?->toDateString());
        $this->assertSame(1200, (int) $product->purchase_unit_cost);
        $this->assertSame(1, (int) $product->purchase_quantity);
        $this->assertSame(1, (int) $product->quantity_available);
        $this->assertSame(150, (int) $product->purchase_shipping_cost);
        $this->assertSame(50, (int) $product->other_purchase_expense);
        $this->assertTrue((bool) $product->purchase_info_confirmed);
        $this->assertNull($product->supplier_id);
        $this->assertNull($product->purchase_evidence);
        $this->assertNull($product->purchase_payment_sources);
    }

    public function test_product_edit_can_update_an_existing_listings_marketplace_and_price_without_overwriting_other_details(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'EDIT-LISTING-001',
            'product_name' => '出品先価格編集テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $originalMarketplace = Marketplace::query()->create([
            'code' => 'edit-original-marketplace',
            'name' => '変更前販売先',
            'base_url' => 'https://original.example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 5,
            'is_active' => true,
        ]);
        $newMarketplace = Marketplace::query()->create([
            'code' => 'edit-new-marketplace',
            'name' => '変更後販売先',
            'base_url' => 'https://new.example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 10,
            'is_active' => true,
        ]);
        $listing = $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $originalMarketplace->id,
            'listing_title' => '元の出品タイトル',
            'listing_description' => '維持する出品説明',
            'listing_price' => 3000,
            'listing_quantity' => 1,
            'expected_fee_rate' => 5,
            'shipping_fee' => 100,
            'shipping_method' => '匿名配送',
            'status' => 'active',
            'listed_at' => now()->subDays(5),
        ]);

        $this->actingAs($user)
            ->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('出品先・出品価格')
            ->assertSee('外部フリマサイトの掲載内容は各サイトでも変更が必要です。')
            ->assertSee('expected_fee_rate')
            ->assertSee('shipping_fee')
            ->assertDontSee('見込み利益')
            ->assertDontSee('仕入原価')
            ->assertDontSee('name="purchase_date"', false)
            ->assertDontSee('name="purchase_unit_cost"', false)
            ->assertDontSee('name="purchase_shipping_cost"', false)
            ->assertDontSee('name="other_purchase_expense"', false)
            ->assertDontSee('name="supplier_id"', false)
            ->assertDontSee('name="purchase_evidence', false)
            ->assertDontSee('name="purchase_payment_sources', false)
            ->assertDontSee('name="accounting_expense_details', false)
            ->assertDontSee('証憑の税区分');

        $this->actingAs($user)
            ->patch(route('listings.marketplace-price', $listing), [
                'marketplace_id' => $newMarketplace->id,
                'listing_price' => 4000,
                'expected_fee_rate' => 10,
                'shipping_fee' => 100,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $listing->refresh();
        $this->assertSame($newMarketplace->id, $listing->marketplace_id);
        $this->assertSame(4000, $listing->listing_price);
        $this->assertSame('元の出品タイトル', $listing->listing_title);
        $this->assertSame('維持する出品説明', $listing->listing_description);
        $this->assertSame('匿名配送', $listing->shipping_method);
        $this->assertSame(10.0, (float) $listing->expected_fee_rate);
        $this->assertSame(2500, $listing->expected_profit);
        $this->assertSame('active', $listing->status);
    }

    public function test_product_edit_cannot_change_another_users_listing_marketplace_or_price(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $product = $owner->products()->create([
            'internal_sku' => 'EDIT-LISTING-OWNER-001',
            'product_name' => '所有者確認商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'edit-owner-marketplace',
            'name' => '所有者確認販売先',
            'base_url' => 'https://owner.example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $listing = $owner->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => '所有者確認出品',
            'listing_price' => 1000,
            'status' => 'active',
        ]);

        $this->actingAs($otherUser)
            ->patch(route('listings.marketplace-price', $listing), [
                'marketplace_id' => $marketplace->id,
                'listing_price' => 1,
            ])
            ->assertNotFound();

        $this->assertSame(1000, $listing->fresh()->listing_price);
    }

    public function test_sale_index_renders_returned_sales_without_a_blade_syntax_error(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $product = $user->products()->create([
            'internal_sku' => 'SALE-INDEX-001',
            'product_name' => '販売一覧テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'sale-index-marketplace',
            'name' => '販売一覧テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => now(),
            'status' => 'returned',
            'cost_basis' => 1000,
            'net_profit' => 2000,
            'refund_amount' => 3000,
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-sales.index'))
            ->assertOk()
            ->assertSee('販売管理')
            ->assertDontSee('取引管理')
            ->assertSee('販売一覧テスト商品')
            ->assertSee('返品・返金済み')
            ->assertSee('返金 ¥3,000');
    }

    public function test_listing_store_keeps_server_profit_calculation_out_of_listing_screens(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-PROFIT-001',
            'product_name' => '販売データテスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 3000,
            'purchase_quantity' => 2,
            'quantity_available' => 2,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-profit-marketplace',
            'name' => '販売データテスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('listings.store'), [
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => '販売データテスト出品',
                'listing_price' => 10000,
                'expected_fee_rate' => '10.00',
                'shipping_fee' => 750,
                'status' => 'draft',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('listings', [
            'product_id' => $product->id,
            'expected_profit' => 5250,
            'expected_margin' => 52.5,
        ]);

        $this->actingAs($user)
            ->get(route('listings.index'))
            ->assertOk()
            ->assertDontSee('見込み利益')
            ->assertDontSee('¥5,250');
    }

    public function test_listing_store_rejects_an_estimated_profit_outside_the_database_range(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-PROFIT-OVERFLOW-001',
            'product_name' => '見込み利益上限テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'listing-profit-overflow-marketplace',
            'name' => '見込み利益上限テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->from(route('listings.create'))
            ->post(route('listings.store'), [
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => '上限超過見込み利益',
                'listing_price' => SaleProfitCalculator::MAX_UNSIGNED_AMOUNT,
                'expected_fee_rate' => '0.00',
                'shipping_fee' => 0,
                'status' => 'draft',
            ])
            ->assertRedirect(route('listings.create'))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, $user->listings()->count());
    }

    public function test_other_marketplace_requires_and_stores_a_user_defined_name(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $product = $user->products()->create([
            'internal_sku' => 'LISTING-OTHER-001',
            'product_name' => 'その他販売先テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'listing_ready',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'other',
            'name' => 'その他',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $payload = [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => 'その他販売先テスト出品',
            'listing_quantity' => 1,
            'expected_fee_rate' => '0.00',
            'shipping_fee' => 0,
            'status' => 'draft',
        ];

        $this->actingAs($user)
            ->from(route('listings.create'))
            ->post(route('listings.store'), $payload)
            ->assertRedirect(route('listings.create'))
            ->assertSessionHasErrors('marketplace_other_name');

        $this->actingAs($user)
            ->post(route('listings.store'), [...$payload, 'marketplace_other_name' => '自社ECサイト'])
            ->assertRedirect();

        $this->assertDatabaseHas('listings', [
            'marketplace_id' => $marketplace->id,
            'marketplace_other_name' => '自社ECサイト',
        ]);
    }
}
