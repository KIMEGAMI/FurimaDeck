<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FurimaDeckManualTest extends TestCase
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

    public function test_verified_user_can_read_the_manual_and_open_its_main_workflows(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('furimadeck-manual'))
            ->assertOk()
            ->assertSee('FurimaDeckの使い方')
            ->assertDontSee('Chrome拡張')
            ->assertDontSee('/chrome-extension/devices', false)
            ->assertSee('出品は行われません')
            ->assertSee('売上を記録')
            ->assertSee('仕入情報・原価・販売時の費用は会計データで管理')
            ->assertSee('販売の基本情報だけを登録します')
            ->assertSee('それぞれの証憑・税区分・発行者・支払元')
            ->assertSee('CSVを出力・取り込む')
            ->assertSee('その他（UTF-8）」を選びます')
            ->assertSee('CSVの取引日が制限日付当日またはそれ以前だと取り込めません')
            ->assertSee('この表示だけで原因を制限日付と決めつけないでください。')
            ->assertSee('FurimaDeck 1.0では会計APIによる仕訳送信・仕訳読み戻しは行いません')
            ->assertSee('会計ソフトへの登録はCSVで行います')
            ->assertSee('会計CSVで出力できる取引')
            ->assertSee('販売手数料を含む明細は実取込の検証が未完了のため出力しません')
            ->assertSee('仕入CSVと、販売送料・別途支払った販売費用を含む販売CSVもFurimaDeck 1.0では出力対象外です')
            ->assertSee('免税状態または課税状態が未確認の期間')
            ->assertSee('FurimaDeck 1.0では仕入CSVに対応していません')
            ->assertSee('別口座などから別途支払った梱包費・修理費など')
            ->assertSee('販売送料が記録された明細')
            ->assertSee('確認できない税区分・インボイス区分を推測して出力しません')
            ->assertSee('Money Forwardサポート回答（2026/10/26）')
            ->assertSee('CSVの該当列を空欄または省略しても仕訳として取り込めます')
            ->assertSee('空欄は税区分として登録されず、仕訳メモにも転記されません。')
            ->assertSee('インボイス欄を利用できる仕訳帳CSVの空欄は「適格」として扱われると公式ガイドで案内されています。')
            ->assertSee('免税設定で欄自体がない場合とは別の仕様です。')
            ->assertSee('href="https://biz.moneyforward.com/support/tax-return/guide/import-books/ib01.html"', false)
            ->assertSee('税務署の相談窓口へ確認してください。会計ソフトの画面や取込方法は、各会計サービスのサポートへ確認してください。')
            ->assertSee('列の割当、値の対応付け、登録可能な仕訳プレビューの順に確認します。')
            ->assertSee('CSVを出力した後、ファイルを受け取れたか・登録できたか分からない場合は、すぐ再出力しないでください。')
            ->assertSee('未登録と確認できた場合だけ「仕訳なしを記録」を押すと再出力できます。')
            ->assertSee('過去の仕入仕訳を商品ごとに手動照合する')
            ->assertSee('「会計データ」の「明細の確認・確定」から各商品の「会計データを確認・照合」を開き')
            ->assertDontSee('各商品の編集画面で同じ会計サービス')
            ->assertSee('同じ会計サービス・仕訳ID・取引日・仕訳全体の借方／貸方合計')
            ->assertSee('その差額を無理に商品へ割り当てず、登録を止めて仕訳を確認してください。')
            ->assertSee('取消・返品済みの売上は再送しないでください')
            ->assertSee('照合用CSV')
            ->assertDontSee('Confirm済みデータをfreeeまたはMoney Forwardへ一括送信')
            ->assertDontSee('Money Forwardのインボイス区分を手動確認する')
            ->assertSee(route('products.create'), false)
            ->assertSee(route('furimadeck-accounting.index'), false)
            ->assertSee(route('listings.index'), false)
            ->assertSee('使い方');
    }

    public function test_guest_is_redirected_to_login_before_opening_the_manual(): void
    {
        $this->get(route('furimadeck-manual'))
            ->assertRedirect(route('login'));
    }
}
