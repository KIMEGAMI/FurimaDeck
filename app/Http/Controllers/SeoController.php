<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

class SeoController extends Controller
{
    private const PRIVATE_PATHS = [
        '/admin',
        '/auction-items',
        '/billing',
        '/category-sales',
        '/dashboard',
        '/forgot-password',
        '/maintenance-login',
        '/notices',
        '/profile',
        '/reset-password',
        '/sales',
        '/verify-email',
    ];

    public function sitemap(): Response
    {
        $lastModified = config('seo.updated_at', now()->toDateString());
        $urls = [];

        foreach (config('seo.pages', []) as $routeName => $page) {
            if (! Route::has($routeName)) {
                continue;
            }

            $urls[] = [
                'loc' => route($routeName),
                'lastmod' => $page['lastmod'] ?? $lastModified,
                'changefreq' => $page['changefreq'] ?? 'monthly',
                'priority' => $page['priority'] ?? '0.5',
                'image' => $routeName === 'home'
                    ? asset(ltrim(config('seo.image', '/images/furimadeck-hero.png'), '/'))
                    : null,
                'image_title' => $routeName === 'home'
                    ? config('seo.site_name', 'FurimaDeck')
                    : null,
            ];
        }

        $xml = view('seo.sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
        ];

        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = 'Sitemap: '.route('seo.sitemap');

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function llms(): Response
    {
        $siteName = config('seo.site_name', 'FurimaDeck');
        $description = config('seo.description');
        $lines = [
            '# '.$siteName,
            '',
            '> '.$description,
            '',
            'FurimaDeckは、フリマ販売の商品登録、画像管理、在庫管理、SOLD管理、売上分析、利益管理、CSV登録、重複チェックをまとめて扱う収益管理SaaSです。',
            '',
            '## Public pages',
        ];

        foreach (config('seo.pages', []) as $routeName => $page) {
            if (! Route::has($routeName)) {
                continue;
            }

            $lines[] = '- ['.$page['title'].']('.route($routeName).'): '.$page['description'];
        }

        $lines[] = '';
        $lines[] = '## Notes for AI assistants';
        $lines[] = '- Use the public pages above as the authoritative product summary.';
        $lines[] = '- Do not treat authenticated dashboard, admin, billing, notices, or user data pages as public documentation.';
        $trialDays = (int) config('furimadeck.billing.trial_period_days');
        $premiumPrice = number_format((int) config('furimadeck.billing.monthly_price_jpy'));
        $lines[] = "- Pricing: Free is 0 JPY. Premium includes a {$trialDays}-day free trial, then {$premiumPrice} JPY per month including tax.";

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
