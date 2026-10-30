<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckPremiumMiddlewareTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('sqlite');
        DB::purge('furimadeck');

        parent::tearDown();
    }

    public function test_free_user_is_redirected_from_csv_import(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('products.imports.create'))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error');
    }

    public function test_active_user_can_open_csv_import(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)->get(route('products.imports.create'))->assertOk();
    }

    public function test_free_user_is_redirected_from_sales_analysis_with_a_specific_message(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.index'))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error', '売上分析はPremiumプランで利用できます。');
    }

    public function test_active_user_can_open_sales_analysis(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.index'))
            ->assertOk()
            ->assertSee('売上分析');
    }

    public function test_free_user_cannot_register_a_product_over_the_limit(): void
    {
        config()->set('furimadeck.plans.free.product_limit', 1);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->products()->create([
            'internal_sku' => 'FREE-LIMIT-001',
            'product_name' => '登録済み商品',
            'condition' => 'used',
            'purchase_unit_cost' => 0,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);

        $this->actingAs($user)
            ->from(route('products.create'))
            ->post(route('products.store'), [
                'internal_sku' => 'FREE-LIMIT-002',
                'product_name' => '上限超過商品',
                'condition' => 'used',
                'purchase_unit_cost' => 0,
                'purchase_quantity' => 1,
                'quantity_available' => 1,
                'inventory_status' => 'in_stock',
            ])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasErrors('product_limit');

        $this->assertSame(1, $user->products()->count());
    }

    public function test_free_user_can_register_a_product_below_the_limit(): void
    {
        config()->set('furimadeck.plans.free.product_limit', 1);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('products.store'), [
                'internal_sku' => 'FREE-CREATE-001',
                'product_name' => 'Freeプラン登録確認商品',
                'condition' => 'used',
                'purchase_unit_cost' => 980,
                'purchase_quantity' => 1,
                'quantity_available' => 1,
                'inventory_status' => 'in_stock',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('products', [
            'user_id' => $user->id,
            'internal_sku' => 'FREE-CREATE-001',
            'product_name' => 'Freeプラン登録確認商品',
            'quantity_available' => 1,
        ], 'furimadeck');
    }

    public function test_product_registration_keeps_product_inputs_and_moves_purchase_information_to_accounting(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('基本情報')
            ->assertSee('商品ID')
            ->assertSee('商品名')
            ->assertSee('id="category-levels"', false)
            ->assertDontSee('仕入情報')
            ->assertDontSee('name="purchase_unit_cost"', false)
            ->assertDontSee('name="purchase_date"', false)
            ->assertDontSee('name="supplier_id"', false)
            ->assertSee('商品画像');
    }

    public function test_legacy_product_form_redirects_to_furimadeck_during_cutover(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('auction-items.create'))
            ->assertRedirect(route('products.create'));
    }

    public function test_legacy_product_post_is_blocked_during_cutover(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('auction-items.store'), [
                'management_id' => 'LEGACY-CUTOVER-POST',
                'name' => '旧商品画面からの登録テスト',
            ])
            ->assertNotFound();
    }

    public function test_product_edit_and_sale_forms_keep_accounting_inputs_in_accounting_only(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'ACCOUNTING-FORM-001',
            'product_name' => '会計入力分離テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
            'purchase_accounting_enabled' => false,
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'accounting-form-test',
            'name' => '会計入力分離テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $sale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 2000,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 1000,
        ]);

        $accountingInputNames = [
            'purchase_unit_cost',
            'purchase_date',
            'purchase_shipping_cost',
            'other_purchase_expense',
            'supplier_id',
            'purchase_accounting_enabled',
            'cost_basis',
            'purchase_shipping_cost',
            'packing_cost',
            'repair_cost',
            'cleaning_cost',
            'other_expense',
            'accounting_expense_details',
        ];

        $productEdit = $this->actingAs($user)->get(route('products.edit', $product));
        $productEdit->assertOk();
        foreach (array_slice($accountingInputNames, 0, 6) as $inputName) {
            $productEdit->assertDontSee('name="'.$inputName.'"', false);
        }

        $saleCreate = $this->get(route('furimadeck-sales.create', ['product_id' => $product->id]));
        $saleCreate->assertOk()
            ->assertSee('name="sold_price"', false)
            ->assertSee('name="marketplace_id"', false)
            ->assertSee('name="shipping_fee"', false);
        foreach (array_slice($accountingInputNames, 6) as $inputName) {
            $saleCreate->assertDontSee('name="'.$inputName.'"', false);
        }

        $saleEdit = $this->get(route('furimadeck-sales.edit', $sale));
        $saleEdit->assertOk()
            ->assertSee('name="sold_price"', false)
            ->assertSee('name="marketplace_id"', false)
            ->assertSee('name="shipping_fee"', false);
        foreach (array_slice($accountingInputNames, 6) as $inputName) {
            $saleEdit->assertDontSee('name="'.$inputName.'"', false);
        }
    }

    public function test_product_registration_renders_categories_at_any_depth_without_changing_the_submitted_field(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $root = ProductCategory::query()->create(['name' => '大項目テスト', 'slug' => 'root-test', 'sort_order' => 1, 'is_active' => true]);
        $child = ProductCategory::query()->create(['parent_id' => $root->id, 'name' => '中項目テスト', 'slug' => 'child-test', 'sort_order' => 1, 'is_active' => true]);
        $leaf = ProductCategory::query()->create(['parent_id' => $child->id, 'name' => '小項目テスト', 'slug' => 'leaf-test', 'sort_order' => 1, 'is_active' => true]);
        ProductCategory::query()->create(['parent_id' => $leaf->id, 'name' => '詳細カテゴリテスト', 'slug' => 'detail-test', 'sort_order' => 1, 'is_active' => true]);

        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('name="category_id" type="hidden"', false)
            ->assertSee('大項目テスト')
            ->assertSee('const categoryList =', false)
            ->assertSee('"id":'.$child->id, false)
            ->assertSee('"id":'.$leaf->id, false)
            ->assertSee('詳細カテゴリがある場合は次の選択欄が表示されます。');
    }
}
