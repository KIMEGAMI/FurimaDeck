<?php

namespace Tests\Feature;

use App\Models\Marketplace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckDashboardTest extends TestCase
{
    private const SALE_PRICE = 12000;

    private const SALE_PROFIT = 4200;

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

    public function test_verified_user_can_view_their_dashboard_summary(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $marketplace = Marketplace::query()->create([
            'code' => 'dashboard-marketplace',
            'name' => 'ダッシュボード販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $product = $user->products()->create([
            'internal_sku' => 'DASHBOARD-001',
            'product_name' => 'ダッシュボード商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        foreach (range(1, 4) as $number) {
            $user->products()->create([
                'internal_sku' => 'DASHBOARD-STOCK-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'product_name' => 'ダッシュボード在庫商品'.$number,
                'condition' => 'used',
                'purchase_unit_cost' => 1000,
                'purchase_quantity' => 1,
                'quantity_available' => 1,
                'inventory_status' => 'in_stock',
            ]);
        }
        $validSale = $user->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => self::SALE_PRICE,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => self::SALE_PROFIT,
        ]);
        $cancelledProduct = $user->products()->create([
            'internal_sku' => 'DASHBOARD-CANCELLED-001',
            'product_name' => '取消済み売上の商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 1,
            'inventory_status' => 'in_stock',
        ]);
        $user->sales()->create([
            'product_id' => $cancelledProduct->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $cancelledProduct->internal_sku,
            'product_name_snapshot' => $cancelledProduct->product_name,
            'quantity' => 1,
            'sold_price' => self::SALE_PRICE,
            'sold_at' => now(),
            'status' => 'cancelled',
            'cost_basis' => 1000,
            'net_profit' => 0,
        ]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $otherUserProduct = $otherUser->products()->create([
            'internal_sku' => 'DASHBOARD-OTHER-001',
            'product_name' => '別ユーザーの売上商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $otherUser->sales()->create([
            'product_id' => $otherUserProduct->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $otherUserProduct->internal_sku,
            'product_name_snapshot' => $otherUserProduct->product_name,
            'quantity' => 1,
            'sold_price' => self::SALE_PRICE,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 1000,
            'net_profit' => self::SALE_PROFIT,
        ]);

        $response = $this->actingAs($user)
            ->get(route('furimadeck-dashboard'))
            ->assertOk()
            ->assertSee('累計売上')
            ->assertSee('累計利益')
            ->assertSee('¥12,000')
            ->assertSee('¥4,200')
            ->assertSee('最近の売上')
            ->assertSee('ダッシュボード商品')
            ->assertSee('ダッシュボード販売先')
            ->assertSee('商品詳細')
            ->assertDontSee('別ユーザーの売上商品')
            ->assertSee('5点')
            ->assertSee('原価 ¥5,000')
            ->assertSee('FurimaDeckからのお知らせ')
            ->assertSee('経営インサイト')
            ->assertSeeInOrder(['今日やること', 'FurimaDeckからのお知らせ'])
            ->assertSee('id="furimadeck-dashboard"', false)
            ->assertSee('furimadeck-dashboard-chart')
            ->assertSee('利益サマリー')
            ->assertSee(route('furimadeck-analytics.index'), false)
            ->assertDontSee('売上詳細を見る')
            ->assertSee('データ保護とバックアップ')
            ->assertSee('の利益')
            ->assertSee('プライバシーポリシー')
            ->assertSee('特定商取引法に基づく表記')
            ->assertSee('お問い合わせ')
            ->assertDontSee('DAILY SALES')
            ->assertSee('furimadeckMonthlySalesChart', false)
            ->assertSee('aria-label="前の月を表示"', false)
            ->assertSee('aria-label="次の月を表示"', false);

        $response->assertViewHas('recentSales', fn ($recentSales): bool => $recentSales->count() === 1
            && $recentSales->first()->is($validSale));
    }

    public function test_premium_dashboard_improvement_counts_link_to_their_candidate_sections(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-dashboard'))
            ->assertOk()
            ->assertSee(route('furimadeck-improvement.index').'#stagnant-stock', false)
            ->assertSee(route('furimadeck-improvement.index').'#price-review', false)
            ->assertSee(route('furimadeck-improvement.index').'#relist', false)
            ->assertSee(route('furimadeck-improvement.index').'#marketplace-change', false)
            ->assertSee(route('furimadeck-improvement.index').'#rebuy', false);
    }

    public function test_premium_dashboard_shows_tax_readiness_summary_and_free_dashboard_does_not_expose_accounting_details(): void
    {
        $premiumUser = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
        $marketplace = Marketplace::query()->create([
            'code' => 'tax-readiness-dashboard-marketplace',
            'name' => '税務準備状況テスト販売先',
            'base_url' => 'https://example.test',
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
        $product = $premiumUser->products()->create([
            'internal_sku' => 'TAX-DASH-001',
            'product_name' => '税務準備状況テスト商品',
            'condition' => 'used',
            'purchase_unit_cost' => 1000,
            'purchase_quantity' => 1,
            'quantity_available' => 0,
            'inventory_status' => 'out_of_stock',
        ]);
        $premiumUser->sales()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => 12000,
            'sold_at' => now(),
            'status' => 'completed',
            'cost_basis' => 1000,
            'sales_fee' => 0,
            'shipping_fee' => 0,
            'other_expense' => 0,
            'net_profit' => 11000,
        ]);

        $this->actingAs($premiumUser)
            ->get(route('furimadeck-dashboard'))
            ->assertOk()
            ->assertSee('確定申告準備状況')
            ->assertSee('必須項目に値あり')
            ->assertSee('値の確認対象')
            ->assertSee('会計連携未設定')
            ->assertSee(route('furimadeck-accounting.index'), false);

        $freeUser = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($freeUser)
            ->get(route('furimadeck-dashboard'))
            ->assertOk()
            ->assertDontSee('確定申告準備状況')
            ->assertDontSee('必須項目に値あり');
    }

    public function test_desktop_navigation_dropdowns_open_by_click_and_close_when_pointer_leaves(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('furimadeck-dashboard'))
            ->assertOk()
            ->assertSee('商品管理')
            ->assertSee('商品一覧')
            ->assertSee(route('suppliers.index'), false)
            ->assertSee('仕入先')
            ->assertSee('分析')
            ->assertSee('売上分析')
            ->assertSee('高度分析 Premium')
            ->assertSee('販売改善 Premium')
            ->assertSee('ジャンル別分析')
            ->assertDontSee('出品先適性')
            ->assertSee('アカウント')
            ->assertSee('メニュー')
            ->assertSee('relative z-50 border-b', false)
            ->assertSee('text-cyan-200">商品管理</p>', false)
            ->assertSee('text-cyan-200">分析</p>', false)
            ->assertSee('text-cyan-200">アカウント</p>', false)
            ->assertSee('@mouseleave="$el.open = false"', false)
            ->assertSee('@keydown.escape="$el.open = false"', false)
            ->assertDontSee('top-full z-50 mt-2', false)
            ->assertDontSee('class="group relative" open', false);
    }

    public function test_premium_user_does_not_see_premium_badges_on_advanced_analysis_or_sales_improvement(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('furimadeck-dashboard'))
            ->assertOk()
            ->assertDontSee('高度分析 Premium')
            ->assertDontSee('販売改善 Premium');
    }
}
