<?php

namespace Tests\Feature;

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

    public function test_product_registration_keeps_required_inputs_visible_and_groups_optional_inputs(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('products.create'))
            ->assertOk()
            ->assertSee('基本情報')
            ->assertSee('商品ID')
            ->assertSee('商品名')
            ->assertSee('id="category-levels"', false)
            ->assertSee('仕入・商品詳細（任意）')
            ->assertSee('商品画像');
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
