<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckSalesAnalysisTest extends TestCase
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

    public function test_premium_user_can_view_monthly_and_category_sales_analysis(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $root = ProductCategory::query()->create(['name' => 'ファッション', 'slug' => 'fashion', 'sort_order' => 1, 'is_active' => true]);
        $middle = ProductCategory::query()->create(['parent_id' => $root->id, 'name' => 'バッグ', 'slug' => 'fashion-bags', 'sort_order' => 1, 'is_active' => true]);
        $category = ProductCategory::query()->create(['parent_id' => $middle->id, 'name' => 'トートバッグ', 'slug' => 'fashion-bags-tote', 'sort_order' => 1, 'is_active' => true]);
        $marketplace = Marketplace::query()->create(['code' => 'analysis-marketplace', 'name' => '分析販売先', 'base_url' => 'https://example.test', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $secondMarketplace = Marketplace::query()->create(['code' => 'analysis-second-marketplace', 'name' => '第二販売先', 'base_url' => 'https://example.test/second', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        Marketplace::query()->create(['code' => 'other', 'name' => 'その他', 'base_url' => 'https://example.invalid', 'fee_type' => 'percentage', 'default_fee_rate' => 0, 'is_active' => true]);
        $product = $user->products()->create([
            'internal_sku' => 'ANALYTICS-001',
            'product_name' => '分析対象商品',
            'category_id' => $category->id,
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 2,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 5000,
            'sold_at' => '2026-09-10 12:00:00',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 3000,
        ]);
        $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $secondMarketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 3000,
            'sold_at' => '2026-09-12 12:00:00',
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => 500,
        ]);
        $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 9999,
            'sold_at' => '2026-09-10 12:00:00',
            'status' => 'cancelled',
            'cost_basis' => 1000,
            'net_profit' => 8999,
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('売上分析')
            ->assertSee('在庫分析')
            ->assertSee('在庫商品数')
            ->assertSee('¥1,000')
            ->assertSee('¥5,000')
            ->assertSee('分析販売先')
            ->assertSee('月別売上・実利益')
            ->assertDontSee('DAILY SALES')
            ->assertSee('furimadeckAnalyticsTrendChart', false)
            ->assertSee('furimadeckMarketplaceChart', false);

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.categories', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('ジャンル別分析')
            ->assertSee('ファッション')
            ->assertSee('ファッション / バッグ')
            ->assertSee('ファッション / バッグ / トートバッグ')
            ->assertSee('furimadeckRootCategoryChart', false)
            ->assertSee('furimadeckRootCategoryQuantityChart', false)
            ->assertSee('furimadeckMiddleCategoryChart', false)
            ->assertSee('furimadeckMiddleCategoryQuantityChart', false)
            ->assertSee('furimadeckSmallCategoryChart', false)
            ->assertSee('furimadeckSmallCategoryQuantityChart', false)
            ->assertSee('大ジャンル別 販売数')
            ->assertSee('売上上位10ジャンル')
            ->assertSee('小分類別 明細（売上上位10件）')
            ->assertDontSee('YEARLY CONTEXT')
            ->assertDontSee('2026年 月別売上・実利益')
            ->assertSee('中分類別 明細')
            ->assertSee('小分類別 明細');

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.cross', [
                'month' => '2026-09',
                'category_level' => 'root',
                'metric' => 'profit',
            ]))
            ->assertOk()
            ->assertSee('クロス分析')
            ->assertSee('ファッション')
            ->assertSee('分析販売先')
            ->assertSee('第二販売先')
            ->assertSee('¥3,000')
            ->assertSee('¥500')

            ->assertDontSee('¥8,999');
    }

    public function test_product_inventory_is_shown_without_inventing_sales_data(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $user->products()->createMany([
            [
                'internal_sku' => 'CSV-IN-STOCK-001',
                'product_name' => '在庫商品1',
                'condition' => 'used',
                'purchase_unit_cost' => 1900,
                'purchase_quantity' => 1,
                'quantity_available' => 2,
                'inventory_status' => 'in_stock',
            ],
            [
                'internal_sku' => 'CSV-OUT-OF-STOCK-001',
                'product_name' => '在庫なし商品',
                'condition' => 'used',
                'purchase_unit_cost' => 2500,
                'purchase_quantity' => 1,
                'quantity_available' => 0,
                'inventory_status' => 'out_of_stock',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('売上分析に使える販売データがありません')
            ->assertSee('在庫商品数')
            ->assertSee('¥3,800')
            ->assertSee('販売データが登録されていないため');
    }

    public function test_premium_user_can_view_advanced_analysis_with_date_boundaries(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.advanced', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertSee('高度分析')
            ->assertSee('販売日数')
            ->assertSee('利益速度')
            ->assertSee('価格帯分析')
            ->assertSee('データ不足')
            ->assertSee('販売データ不足');
    }

    public function test_premium_user_can_view_tax_readiness(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.index', ['year' => 2026]))
            ->assertOk()
            ->assertSee('確定申告・会計連携')
            ->assertSee('2026年分 準備状況')
            ->assertSee('会計連携は現在未設定です');
    }

    public function test_premium_user_can_sync_and_download_common_accounting_csv(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)->post(route('furimadeck-accounting.sync'), ['year' => 2026])->assertRedirect(route('furimadeck-accounting.index', ['year' => 2026]));
        $this->actingAs($user)->get(route('furimadeck-accounting.csv', ['year' => 2026]))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertDownload('furimadeck-accounting-2026.csv');
    }

    public function test_vendor_csv_redirects_with_error_when_account_mapping_is_missing(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        config()->set('furimadeck.accounting.export_accounts', []);

        $this->actingAs($user)
            ->get(route('furimadeck-accounting.freee', ['year' => 2026]))
            ->assertRedirect(route('furimadeck-accounting.index', ['year' => 2026]))
            ->assertSessionHas('error', '会計CSVの勘定科目設定が不足しています: settlement, sales, fees, cost, inventory');
    }

    public function test_premium_user_can_view_marketplace_suitability_analysis(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.suitability', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('出品先適性分析')
            ->assertSee('あなたの販売実績に基づく比較');
    }

    public function test_premium_user_can_view_sales_improvement_details(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-improvement.index'))
            ->assertOk()
            ->assertSee('今日の販売改善')
            ->assertSee('滞留在庫')
            ->assertSee('価格見直しの参考')
            ->assertSee('再出品を検討')
            ->assertSee('出品先変更を検討')
            ->assertSee('再仕入れ候補');
    }

    public function test_free_user_is_redirected_from_sales_analysis(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('furimadeck-analytics.index'))
            ->assertRedirect(route('furimadeck-billing.index'))
            ->assertSessionHas('error', '売上分析はPremiumプランで利用できます。');
    }
}
