<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_public_home_page_is_available(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('フリマ販売の収益管理', false);
        $response->assertSee('images/furimadeck-hero.png?v=', false);
        $response->assertSee('images/furimadeck-value.png?v=', false);
    }

    public function test_sitemap_is_available(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('<urlset', false);
        $response->assertSee('/features', false);
        $response->assertSee('/use-cases', false);
        $response->assertSee('/pricing', false);
        $response->assertDontSee('/login', false);
    }

    public function test_robots_txt_blocks_private_areas(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('Disallow: /dashboard', false);
        $response->assertSee('Disallow: /billing', false);
        $response->assertSee('Disallow: /maintenance-login', false);
        $response->assertSee('Sitemap:', false);
    }

    public function test_commercial_transactions_page_contains_required_disclosure_items(): void
    {
        $response = $this->get('/commercial-transactions');

        $response->assertOk();
        foreach ([
            '事業者名',
            '代表者名または運営責任者',
            '所在地',
            '電話番号',
            '連絡先メールアドレス',
            '販売価格',
            '商品代金以外の必要料金',
            '代金の支払時期・方法',
            'サービス提供時期',
            '解約・契約管理',
            '返品・キャンセル・返金',
        ] as $text) {
            $response->assertSee($text, false);
        }
    }

    public function test_llms_txt_is_available(): void
    {
        $response = $this->get('/llms.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->assertSee('# FurimaDeck', false);
        $response->assertSee('/features', false);
        $response->assertSee('Pricing: Free is 0 JPY. Premium includes a 7-day free trial, then 480 JPY per month including tax.', false);
        $response->assertDontSee('/dashboard', false);
    }

    public function test_pwa_manifest_is_available(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/manifest+json; charset=UTF-8');
        $response->assertJsonPath('short_name', 'FurimaDeck');
        $response->assertJsonPath('display', 'standalone');
    }

    public function test_service_worker_is_available(): void
    {
        $response = $this->get('/service-worker.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $response->assertSee('self.addEventListener', false);
        $response->assertDontSee("'/login'", false);
    }

    public function test_login_screen_has_pwa_install_button(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('rel="manifest"', false);
        $response->assertSee('data-pwa-install', false);
        $response->assertSee('FurimaDeckをアプリとして追加', false);
    }

    public function test_marketing_pages_are_available(): void
    {
        foreach (['/features', '/use-cases', '/pricing'] as $path) {
            $this->get($path)->assertOk();
        }
    }
}
