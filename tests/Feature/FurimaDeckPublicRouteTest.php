<?php

namespace Tests\Feature;

use Tests\TestCase;

class FurimaDeckPublicRouteTest extends TestCase
{
    private const PUBLIC_PATHS = [
        '/',
        '/features',
        '/pricing',
        '/use-cases',
        '/terms',
        '/privacy',
        '/commercial-transactions',
        '/faq',
        '/contact',
        '/login',
        '/register',
    ];

    public function test_public_pages_remain_available_after_cutover(): void
    {
        config()->set('furimadeck.cutover_enabled', true);

        foreach (self::PUBLIC_PATHS as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_public_pages_do_not_expose_the_legacy_brand(): void
    {
        foreach (self::PUBLIC_PATHS as $path) {
            $this->get($path)
                ->assertOk()
                ->assertDontSee('FURUPRO', false)
                ->assertDontSee('furugi', false);
        }
    }

    public function test_privacy_policy_describes_current_auth_storage_and_accounting_data_flows(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Googleログインを選択した場合')
            ->assertSee('https://policies.google.com/privacy?hl=ja')
            ->assertSee('直近のログインIPアドレス')
            ->assertSee('https://stripe.com/jp/privacy')
            ->assertSee('メール配信にはResendを利用します')
            ->assertSee('https://resend.com/legal/privacy-policy')
            ->assertSee('主な処理拠点が米国')
            ->assertSee('https://resend.com/legal/dpa')
            ->assertSee('https://resend.com/legal/subprocessors')
            ->assertSee('Cloudflare R2を保存先に設定した場合')
            ->assertSee('https://www.cloudflare.com/ja-jp/privacypolicy/')
            ->assertSee('連携トークンは暗号化して保存します')
            ->assertSee('FurimaDeck 1.0では会計仕訳のAPI送信を行いません')
            ->assertSee('https://www.freee.co.jp/privacy_policy/')
            ->assertSee('https://corp.moneyforward.com/privacy/')
            ->assertSee('code.jquery.com')
            ->assertSee('jsDelivr')
            ->assertDontSee('Bunny Fonts')
            ->assertDontSee('外部アクセス解析事業者に委託');
    }

    public function test_public_homepage_explains_accounting_and_tax_preparation_scope(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('会計データをCSVで出力')
            ->assertSee('確定申告書の作成・提出は各会計サービス側で行います')
            ->assertSee('FurimaDeckは税務判断や申告書の作成を行いません');
    }
}
