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
            ->assertSee('仕入情報や会計データは会計データメニューで管理します', false)
            ->assertSee('FurimaDeckは税務判断や申告書の作成を行いません', false)
            ->assertSee('会計データ内の仕入記録管理', false)
            ->assertSee('仕入情報や仕入先は会計データメニューで管理します', false)
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

    public function test_public_feature_and_use_case_pages_reflect_separate_accounting_data_entry(): void
    {
        $this->get(route('marketing.features'))
            ->assertOk()
            ->assertSee('初回出品価格も設定できます', false)
            ->assertSee('仕入情報や会計用の費用は会計データメニューで管理します', false)
            ->assertDontSee('仕入れ価格、販売価格、手数料、送料、コメント、商品画像をまとめて登録できます', false);

        $this->get(route('marketing.use-cases'))
            ->assertOk()
            ->assertSee('商品ごとの出品価格を一覧で確認できます', false)
            ->assertSee('仕入れ値は会計データメニューで管理・確認します', false)
            ->assertDontSee('仕入れ価格、販売価格を一覧で確認できます', false);
    }
}
