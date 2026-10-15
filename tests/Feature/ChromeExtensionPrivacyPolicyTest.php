<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChromeExtensionPrivacyPolicyTest extends TestCase
{
    public function test_public_privacy_policy_discloses_chrome_extension_data_handling(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Chrome拡張機能「FurimaDeck 出品アシスト」')
            ->assertSee('アカウント表示名')
            ->assertSee('売上CSV')
            ->assertSee('フリマ画面のURL・ホスト名')
            ->assertSee('選択された出品フォームの表示ラベル、入力欄の属性、選択肢、現在値を端末内で一時的に確認します')
            ->assertSee('これらのフォーム情報やフリマ画面のURL・ホスト名はFurimaDeckへ送信・保存しません')
            ->assertSee('サイト側の仕様により、入力時に下書き保存や画像アップロード等の処理が行われる可能性があります')
            ->assertSee('拡張機能自体はフォーム送信・出品確定を行いませんが、サイト側の処理は制御しません')
            ->assertSee('商品IDと出品先をChromeのセッションストレージに最長10分保存')
            ->assertSee('出品先ごとの任意アクセス権')
            ->assertSee('カテゴリ別属性（例：ブランド、サイズ）・JAN/EAN・ISBN・型番・製造番号・商品画像')
            ->assertSee('ラクマについては公式サポートからツールによる入力・操作を控えるよう案内されています')
            ->assertSee('拡張機能自体はフォーム送信・出品確定を行いません')
            ->assertSee('Limited Use');
    }
}
