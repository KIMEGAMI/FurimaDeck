<?php

namespace Tests\Feature;

use Tests\TestCase;

class FurimaDeckHomePageContentTest extends TestCase
{
    public function test_home_page_explains_current_product_features_and_pricing(): void
    {
        $freeProductLimit = (int) config('furimadeck.plans.free.product_limit');
        $premiumPrice = (int) config('furimadeck.billing.monthly_price_jpy');
        $trialDays = (int) config('furimadeck.billing.trial_period_days');

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('いくつもの出品先を、', false)
            ->assertSee('ジャンル × 出品先', false)
            ->assertSee('売上上位10件を縦棒グラフで比較', false)
            ->assertSee('会計データをCSVで出力', false)
            ->assertSee('freee・Money Forward用CSVとして出力', false)
            ->assertSee('仕入日・仕入単価・仕入先も商品管理で登録します', false)
            ->assertSee('商品管理の仕入情報や会計データに記録した費用を反映した利益', false)
            ->assertSee('FurimaDeckは税務判断や申告書の作成を行いません', false)
            ->assertSee('商品管理での仕入情報・仕入先管理', false)
            ->assertSee('仕入日・仕入単価・仕入先は商品管理で登録します', false)
            ->assertSee('確定申告書の作成・提出は各会計サービス側で行います', false)
            ->assertDontSee('freee・Money Forwardへ送信またはCSV出力', false)
            ->assertDontSee('確定申告に向けた会計整理', false)
            ->assertSee('30日以上', false)
            ->assertSee('商品登録 '.number_format($freeProductLimit).'件まで', false)
            ->assertSee('¥'.number_format($premiumPrice), false)
            ->assertSee($trialDays.'日間は無料', false)
            ->assertDontSee('販売管理', false)
            ->assertDontSee('出品先適性分析', false)
            ->assertDontSee('DAILY SALES', false);
    }

    public function test_public_feature_and_use_case_pages_reflect_product_management_as_purchase_information_source(): void
    {
        $this->get(route('marketing.features'))
            ->assertOk()
            ->assertSee('初回出品価格も設定できます', false)
            ->assertSee('仕入日・仕入単価・仕入先も商品管理で登録できます', false)
            ->assertSee('証憑・税区分・支払元は会計データで確認します', false)
            ->assertDontSee('仕入れ価格、販売価格、手数料、送料、コメント、商品画像をまとめて登録できます', false);

        $this->get(route('marketing.use-cases'))
            ->assertOk()
            ->assertSee('商品ごとの出品価格を一覧で確認できます', false)
            ->assertSee('仕入日・仕入単価・仕入先は商品管理で登録・確認します', false)
            ->assertDontSee('仕入れ価格、販売価格を一覧で確認できます', false);
    }

    public function test_public_plan_copy_matches_free_sale_recording_and_premium_gates(): void
    {
        $pricing = $this->get(route('marketing.pricing'));

        $pricing->assertOk()
            ->assertSee('商品画像・出品情報の登録、販売記録、在庫更新が可能', false)
            ->assertSee('CSV登録・出力、売上分析、会計CSVは利用不可', false)
            ->assertSee('売上・利益・ジャンル・クロス分析と販売改善', false)
            ->assertSee('会計データとfreee・Money Forward向けCSV', false)
            ->assertDontSee('販売管理');

        $this->get(route('legal.faq'))
            ->assertOk()
            ->assertSee('販売記録と在庫更新も行えます', false)
            ->assertSee('CSV登録・出力、売上分析、会計CSVを利用できます', false)
            ->assertSee('販売記録へ反映できます', false)
            ->assertSee((int) config('furimadeck.inventory.long_term_days').'日以上売れていない商品を確認できますか？', false)
            ->assertSee('商品一覧で未販売の日数を指定して検索できます。', false)
            ->assertSee('未出品の商品は対象外です。', false)
            ->assertDontSee('販売管理');
    }
}
