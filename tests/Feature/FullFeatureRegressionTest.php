<?php

namespace Tests\Feature;

use App\Models\AuctionItem;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FullFeatureRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_form_keeps_profit_simulator_without_description_generator(): void
    {
        $user = $this->premiumUser();

        $response = $this
            ->actingAs($user)
            ->get(route('auction-items.create'));

        $response->assertOk();
        $response->assertSee('値付け支援・利益シミュレーター', false);
        $response->assertSee('おすすめ価格を売値に入れる', false);
        $response->assertDontSee('商品説明文を作成する', false);
        $response->assertDontSee('data-generate-description', false);
    }

    public function test_product_registration_without_image_and_sold_calculation_are_exact(): void
    {
        $user = $this->premiumUser();

        $this
            ->actingAs($user)
            ->post(route('auction-items.store'), [
                'management_id' => 'DBG-CALC-001',
                'title' => '計算確認 商品',
                'comment' => '画像なし登録',
                'platform' => AuctionItem::PLATFORM_YAHOO_FLEAMARKET,
                'purchase_price' => 333,
                'sold_price' => 999,
                'shipping_fee' => 185,
                'sales_fee_rate' => 5,
            ])
            ->assertRedirect(route('auction-items.index'))
            ->assertSessionHas('success');

        $item = AuctionItem::query()
            ->where('user_id', $user->id)
            ->where('management_id', 'DBG-CALC-001')
            ->firstOrFail();

        $this->assertSame(AuctionItem::STATUS_SELLING, $item->status);
        $this->assertNull($item->image_path);
        $this->assertNull($item->sold_image_path);
        $this->assertSame(50, $item->sales_fee);
        $this->assertSame(0, $item->profit);

        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        $this
            ->actingAs($user)
            ->patch(route('auction-items.sold', $item))
            ->assertRedirect(route('auction-items.index'))
            ->assertSessionHas('success');

        $item->refresh();

        $this->assertSame(AuctionItem::STATUS_SOLD, $item->status);
        $this->assertSame(50, $item->sales_fee);
        $this->assertSame(431, $item->profit);
        $this->assertSame('2026-08-24', $item->sold_at?->format('Y-m-d'));
        $this->assertNull($item->sold_image_path);
    }

    public function test_product_list_search_filters_status_platform_keyword_category_and_unsold_date(): void
    {
        $user = $this->premiumUser();
        $otherUser = $this->premiumUser();
        [$parent, $child] = $this->categoryPair('トップス', 'シャツ');
        [, $otherChild] = $this->categoryPair('ボトムス', 'デニム');

        $target = $this->createItem($user, [
            'management_id' => 'DBG-SRCH-001',
            'title' => '青いシャツ',
            'comment' => 'alpha needle',
            'platform' => AuctionItem::PLATFORM_MERCARI,
            'category_id' => $child->id,
        ]);
        $this->setCreatedAt($target, '2026-07-23 23:59:59');

        $afterDate = $this->createItem($user, [
            'management_id' => 'DBG-SRCH-002',
            'title' => '青いシャツ 後日',
            'comment' => 'alpha needle',
            'platform' => AuctionItem::PLATFORM_MERCARI,
            'category_id' => $child->id,
        ]);
        $this->setCreatedAt($afterDate, '2026-07-24 00:00:00');

        $sold = $this->createItem($user, [
            'management_id' => 'DBG-SRCH-003',
            'title' => '売却済み alpha',
            'comment' => 'alpha needle',
            'platform' => AuctionItem::PLATFORM_MERCARI,
            'category_id' => $child->id,
            'status' => AuctionItem::STATUS_SOLD,
            'sold_at' => '2026-07-22',
        ]);
        $this->setCreatedAt($sold, '2026-07-22 00:00:00');

        $wrongCategory = $this->createItem($user, [
            'management_id' => 'DBG-SRCH-004',
            'title' => '別カテゴリ alpha',
            'comment' => 'alpha needle',
            'platform' => AuctionItem::PLATFORM_MERCARI,
            'category_id' => $otherChild->id,
        ]);
        $this->setCreatedAt($wrongCategory, '2026-07-22 00:00:00');

        $otherUserItem = $this->createItem($otherUser, [
            'management_id' => 'DBG-SRCH-005',
            'title' => '他ユーザー alpha',
            'comment' => 'alpha needle',
            'platform' => AuctionItem::PLATFORM_MERCARI,
            'category_id' => $child->id,
        ]);
        $this->setCreatedAt($otherUserItem, '2026-07-22 00:00:00');

        $response = $this
            ->actingAs($user)
            ->get(route('auction-items.index', [
                'keyword' => 'alpha',
                'platform' => AuctionItem::PLATFORM_MERCARI,
                'parent_category_id' => $parent->id,
                'unsold_before' => '20260723',
            ]));

        $response->assertOk();
        $response->assertSee('2026/07/23以前の未売却', false);
        $response->assertSee('DBG-SRCH-001');
        $response->assertDontSee('DBG-SRCH-002');
        $response->assertDontSee('DBG-SRCH-003');
        $response->assertDontSee('DBG-SRCH-004');
        $response->assertDontSee('DBG-SRCH-005');
    }

    public function test_legacy_paypay_platform_is_included_in_yahoo_fleamarket_filter(): void
    {
        $user = $this->premiumUser();

        $this->createItem($user, [
            'management_id' => 'DBG-PAY-001',
            'title' => '現行Yahooフリマ',
            'platform' => AuctionItem::PLATFORM_YAHOO_FLEAMARKET,
        ]);
        $this->createItem($user, [
            'management_id' => 'DBG-PAY-002',
            'title' => '旧PayPayフリマ',
            'platform' => AuctionItem::PLATFORM_PAYPAY_LEGACY,
        ]);
        $this->createItem($user, [
            'management_id' => 'DBG-PAY-003',
            'title' => 'メルカリ商品',
            'platform' => AuctionItem::PLATFORM_MERCARI,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('auction-items.index', [
                'platform' => AuctionItem::PLATFORM_YAHOO_FLEAMARKET,
            ]));

        $response->assertOk();
        $response->assertSee('DBG-PAY-001');
        $response->assertSee('DBG-PAY-002');
        $response->assertDontSee('DBG-PAY-003');
    }

    public function test_unsold_alert_thresholds_include_exact_14_and_30_day_boundaries(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 10:00:00'));

        $user = $this->premiumUser();
        $day13 = $this->createItem($user, [
            'management_id' => 'DBG-AGE-013',
            'title' => '13日前の商品',
        ]);
        $day14 = $this->createItem($user, [
            'management_id' => 'DBG-AGE-014',
            'title' => '14日前の商品',
        ]);
        $day30 = $this->createItem($user, [
            'management_id' => 'DBG-AGE-030',
            'title' => '30日前の商品',
        ]);
        $soldOld = $this->createItem($user, [
            'management_id' => 'DBG-AGE-SOLD',
            'title' => '古い売却済み商品',
            'status' => AuctionItem::STATUS_SOLD,
            'sold_at' => '2026-08-01',
        ]);

        $this->setCreatedAt($day13, '2026-08-11 00:00:00');
        $this->setCreatedAt($day14, '2026-08-10 23:59:59');
        $this->setCreatedAt($day30, '2026-07-25 23:59:59');
        $this->setCreatedAt($soldOld, '2026-07-20 00:00:00');

        $threshold14 = $this
            ->actingAs($user)
            ->get(route('auction-items.unsold-alerts', ['threshold' => 14]));

        $threshold14->assertOk();
        $threshold14->assertSee('DBG-AGE-014');
        $threshold14->assertSee('DBG-AGE-030');
        $threshold14->assertDontSee('DBG-AGE-013');
        $threshold14->assertDontSee('DBG-AGE-SOLD');

        $threshold30 = $this
            ->actingAs($user)
            ->get(route('auction-items.unsold-alerts', ['threshold' => 30]));

        $threshold30->assertOk();
        $threshold30->assertSee('DBG-AGE-030');
        $threshold30->assertDontSee('DBG-AGE-014');
        $threshold30->assertDontSee('DBG-AGE-013');
        $threshold30->assertDontSee('DBG-AGE-SOLD');
    }

    public function test_sales_and_category_analysis_totals_are_exact(): void
    {
        $user = $this->premiumUser();
        [$tops, $shirt] = $this->categoryPair('トップス', 'シャツ');
        [, $pants] = $this->categoryPair('ボトムス', 'デニム');

        $this->createItem($user, [
            'management_id' => 'DBG-SALE-001',
            'title' => '7月メルカリ売上',
            'platform' => AuctionItem::PLATFORM_MERCARI,
            'category_id' => $shirt->id,
            'status' => AuctionItem::STATUS_SOLD,
            'sold_price' => 1000,
            'purchase_price' => 333,
            'sales_fee_rate' => 10,
            'sales_fee' => 0,
            'shipping_fee' => 185,
            'profit' => 382,
            'sold_at' => '2026-07-10',
        ]);
        $this->createItem($user, [
            'management_id' => 'DBG-SALE-002',
            'title' => '7月Yahooフリマ売上',
            'platform' => AuctionItem::PLATFORM_YAHOO_FLEAMARKET,
            'category_id' => $shirt->id,
            'status' => AuctionItem::STATUS_SOLD,
            'sold_price' => 999,
            'purchase_price' => 200,
            'sales_fee_rate' => 5,
            'sales_fee' => 0,
            'shipping_fee' => 120,
            'profit' => 629,
            'sold_at' => '2026-07-11',
        ]);
        $this->createItem($user, [
            'management_id' => 'DBG-SALE-003',
            'title' => '6月売上',
            'platform' => AuctionItem::PLATFORM_YAHOO,
            'category_id' => $pants->id,
            'status' => AuctionItem::STATUS_SOLD,
            'sold_price' => 2000,
            'purchase_price' => 700,
            'sales_fee_rate' => 10,
            'sales_fee' => 111,
            'shipping_fee' => 300,
            'profit' => 889,
            'sold_at' => '2026-06-20',
        ]);
        $this->createItem($user, [
            'management_id' => 'DBG-SALE-004',
            'title' => '出品中は売上集計対象外',
            'platform' => AuctionItem::PLATFORM_MERCARI,
            'category_id' => $tops->id,
            'status' => AuctionItem::STATUS_SELLING,
            'sold_price' => 5000,
            'purchase_price' => 1,
            'sales_fee_rate' => 10,
            'sales_fee' => 1,
            'shipping_fee' => 1,
        ]);

        $sales = $this
            ->actingAs($user)
            ->get(route('sales.index', ['month' => '2026-07']));

        $sales->assertOk();
        $this->assertSame(3999, $sales->viewData('totalSales'));
        $this->assertSame(1233, $sales->viewData('totalPurchase'));
        $this->assertSame(261, $sales->viewData('totalSalesFee'));
        $this->assertSame(605, $sales->viewData('totalShippingFee'));
        $this->assertSame(1900, $sales->viewData('totalProfit'));
        $this->assertSame(3, $sales->viewData('soldCount'));
        $this->assertSame(1, $sales->viewData('sellingCount'));

        $selectedMonth = $sales->viewData('selectedMonthRow');
        $this->assertSame('2026-07', $selectedMonth['month']);
        $this->assertSame(2, $selectedMonth['count']);
        $this->assertSame(1999, $selectedMonth['sales']);
        $this->assertSame(533, $selectedMonth['purchase']);
        $this->assertSame(150, $selectedMonth['sales_fee']);
        $this->assertSame(305, $selectedMonth['shipping_fee']);
        $this->assertSame(1011, $selectedMonth['profit']);
        $this->assertSame(50.6, $selectedMonth['profit_rate']);
        $this->assertSame(506, $selectedMonth['average_profit']);

        $categorySales = $this
            ->actingAs($user)
            ->get(route('category-sales.index', ['month' => '2026-07']));

        $categorySales->assertOk();
        $this->assertSame([
            'sales' => 1999,
            'profit' => 1011,
            'count' => 2,
            'profit_rate' => 50.6,
        ], $categorySales->viewData('summary'));

        $topCategory = $categorySales->viewData('parentCategoryRanking')->first();
        $this->assertSame('トップス', $topCategory['label']);
        $this->assertSame(1999, $topCategory['sales']);
        $this->assertSame(1011, $topCategory['profit']);
        $this->assertSame(100.0, $topCategory['share']);
    }

    public function test_sales_csv_outputs_exact_fee_profit_and_escapes_formula_like_title(): void
    {
        $user = $this->premiumUser();

        $this->createItem($user, [
            'management_id' => 'DBG-CSV-001',
            'title' => '=HYPERLINK("https://example.test")',
            'platform' => AuctionItem::PLATFORM_YAHOO_FLEAMARKET,
            'status' => AuctionItem::STATUS_SOLD,
            'sold_price' => 999,
            'purchase_price' => 200,
            'sales_fee_rate' => 5,
            'sales_fee' => 0,
            'shipping_fee' => 120,
            'sold_at' => '2026-07-11',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('sales.csv'));

        $response->assertOk();
        $csv = $response->getContent();

        $this->assertStringContainsString('"DBG-CSV-001"', $csv);
        $this->assertStringContainsString('"\'=HYPERLINK(""https://example.test"")"', $csv);
        $this->assertStringContainsString('"999"', $csv);
        $this->assertStringContainsString('"5%"', $csv);
        $this->assertStringContainsString('"50"', $csv);
        $this->assertStringContainsString('"120"', $csv);
        $this->assertStringContainsString('"629"', $csv);
        $this->assertStringContainsString('"SOLD"', $csv);
        $this->assertStringContainsString('"2026-07-11"', $csv);
    }

    private function premiumUser(): User
    {
        return User::factory()->create([
            'subscription_plan' => User::SUBSCRIPTION_ACTIVE,
            'subscription_status' => 'active',
        ]);
    }

    /**
     * @return array{0: Category, 1: Category}
     */
    private function categoryPair(string $parentName, string $childName): array
    {
        $parent = Category::create([
            'name' => $parentName,
            'sort_order' => Category::query()->count() + 1,
        ]);

        $child = Category::create([
            'parent_id' => $parent->id,
            'name' => $childName,
            'sort_order' => 1,
        ]);

        return [$parent, $child];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createItem(User $user, array $overrides = []): AuctionItem
    {
        return AuctionItem::create([
            'user_id' => $user->id,
            'management_id' => $overrides['management_id'] ?? 'DBG-ITEM-'.uniqid(),
            'title' => $overrides['title'] ?? 'デバッグ商品',
            'comment' => $overrides['comment'] ?? null,
            'platform' => $overrides['platform'] ?? AuctionItem::PLATFORM_OTHER,
            'category_id' => $overrides['category_id'] ?? null,
            'image_path' => null,
            'sold_image_path' => null,
            'status' => $overrides['status'] ?? AuctionItem::STATUS_SELLING,
            'purchase_price' => $overrides['purchase_price'] ?? 100,
            'sold_price' => $overrides['sold_price'] ?? 200,
            'sales_fee_rate' => $overrides['sales_fee_rate'] ?? 0,
            'sales_fee' => $overrides['sales_fee'] ?? 0,
            'shipping_fee' => $overrides['shipping_fee'] ?? 0,
            'profit' => $overrides['profit'] ?? 0,
            'sold_at' => $overrides['sold_at'] ?? null,
        ]);
    }

    private function setCreatedAt(AuctionItem $item, string $dateTime): void
    {
        $item->forceFill([
            'created_at' => Carbon::parse($dateTime),
            'updated_at' => Carbon::parse($dateTime),
        ])->save();
    }
}
