<?php

$releaseDate = '2026-09-10';

return [
    'site_name' => env('SEO_SITE_NAME', 'FurimaDeck'),
    'title' => env('SEO_TITLE', 'FurimaDeck | フリマ販売の収益管理SaaS'),
    'description' => env('SEO_DESCRIPTION', 'FurimaDeckは、フリマ販売の商品登録、画像管理、出品先管理、販売と利益の記録、CSV登録をまとめて行える収益管理SaaSです。'),
    'keywords' => env('SEO_KEYWORDS', 'フリマ販売,フリマ収益,利益管理,在庫管理,売上管理,CSV登録,ヤフオクCSV,メルカリ管理,ラクマ管理,Yahooフリマ管理'),
    'image' => env('SEO_IMAGE', '/images/furimadeck-hero.png'),
    'image_width' => 1200,
    'image_height' => 630,
    'locale' => 'ja_JP',
    'twitter_card' => 'summary_large_image',
    'updated_at' => $releaseDate,
    'organization' => [
        'name' => env('SEO_ORGANIZATION_NAME', 'FurimaDeck'),
        'url' => env('APP_URL', 'http://127.0.0.1:8000'),
        'logo' => '/images/logo.png',
    ],
    'software' => [
        'name' => 'FurimaDeck',
        'category' => 'BusinessApplication',
        'operating_system' => 'Web, iOS, Android',
        'default_price' => 0,
        'currency' => 'JPY',
    ],
    'pages' => [
        'home' => ['title' => 'FurimaDeck | フリマ販売の収益管理SaaS', 'description' => 'フリマ販売の商品登録、画像管理、出品先管理、販売と利益の記録、CSV登録をひとつにまとめる収益管理SaaSです。Premiumは7日間無料お試しできます。', 'changefreq' => 'weekly', 'priority' => '1.0', 'lastmod' => $releaseDate],
        'marketing.features' => ['title' => '機能一覧 | フリマ販売の商品管理・CSV登録・売上分析', 'description' => '画像付き商品管理、SOLD管理、CSV登録、ヤフオクCSV変換、売上と利益の分析、重複チェック、PWA対応をまとめて確認できます。', 'changefreq' => 'monthly', 'priority' => '0.9', 'lastmod' => $releaseDate],
        'marketing.use-cases' => ['title' => '活用例 | メルカリ・ヤフオク・ラクマ・Yahooフリマの収益管理', 'description' => '複数販路でフリマ販売を行う方向けに、在庫管理、売上管理、CSV登録、利益確認の活用例を紹介します。', 'changefreq' => 'monthly', 'priority' => '0.9', 'lastmod' => $releaseDate],
        'marketing.pricing' => ['title' => '料金 | FurimaDeck', 'description' => 'FurimaDeckの料金体系です。Freeは商品登録50件まで、Premiumは無料お試し後に月額制で登録制限なし、CSV登録・出力、販売と利益の管理を利用できます。', 'changefreq' => 'monthly', 'priority' => '0.8', 'lastmod' => $releaseDate],
        'register' => ['title' => 'アカウント作成 | FurimaDeck', 'description' => 'FurimaDeckのアカウントを作成して、フリマ販売の商品管理、画像管理、SOLD管理、売上管理を始められます。', 'changefreq' => 'monthly', 'priority' => '0.7', 'lastmod' => $releaseDate],
        'legal.faq' => ['title' => 'よくある質問 | FurimaDeckのFAQ', 'description' => 'FurimaDeckの使い方、CSV登録、画像管理、売上分析、アカウント管理、セキュリティに関するよくある質問です。', 'changefreq' => 'monthly', 'priority' => '0.8', 'lastmod' => $releaseDate],
        'legal.terms' => ['title' => '利用規約 | FurimaDeck', 'description' => 'FurimaDeckの利用条件、アカウント管理、禁止事項、データ管理、免責事項について定めた利用規約です。', 'changefreq' => 'yearly', 'priority' => '0.5', 'lastmod' => $releaseDate],
        'legal.privacy' => ['title' => 'プライバシーポリシー | FurimaDeck', 'description' => 'FurimaDeckにおける個人情報、登録データ、アクセス情報の取り扱い方針です。', 'changefreq' => 'yearly', 'priority' => '0.5', 'lastmod' => $releaseDate],
        'legal.commercial' => ['title' => '特定商取引法に基づく表記 | FurimaDeck', 'description' => 'FurimaDeckのFreeプラン、Premiumの無料お試しと月額制、支払い方法、解約、返金、お問い合わせ先についての表記です。', 'changefreq' => 'yearly', 'priority' => '0.5', 'lastmod' => $releaseDate],
        'legal.contact' => ['title' => 'お問い合わせ | FurimaDeck', 'description' => 'FurimaDeckへのお問い合わせページです。使い方、不具合、アカウント、個人情報の取り扱いに関するご連絡を受け付けます。', 'changefreq' => 'yearly', 'priority' => '0.5', 'lastmod' => $releaseDate],
    ],
];
