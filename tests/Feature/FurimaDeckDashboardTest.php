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
            'purchase_quantity' => 4,
            'quantity_available' => 4,
            'inventory_status' => 'in_stock',
        ]);
        $user->sales()->create([
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

        $this->actingAs($user)
            ->get(route('furimadeck-dashboard'))
            ->assertOk()
            ->assertSee('累計売上')
            ->assertSee('累計利益')
            ->assertSee('¥12,000')
            ->assertSee('¥4,200')
            ->assertSee('4点')
            ->assertSee('原価 ¥4,000')
            ->assertSee('FurimaDeckからのお知らせ')
            ->assertSee('経営インサイト')
            ->assertSee('利益サマリー')
            ->assertSee('データ保護とバックアップ')
            ->assertSee('の利益')
            ->assertDontSee('DAILY SALES')
            ->assertSee('furimadeckMonthlySalesChart', false)
            ->assertSee('aria-label="前の月を表示"', false)
            ->assertSee('aria-label="次の月を表示"', false);
    }

    public function test_desktop_navigation_dropdowns_open_by_click_and_close_when_pointer_leaves(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('furimadeck-dashboard'))
            ->assertOk()
            ->assertSee('商品管理')
            ->assertSee('商品一覧')
            ->assertSee('分析')
            ->assertSee('売上分析')
            ->assertSee('ジャンル別分析')
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
}
