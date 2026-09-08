@props([
    'title',
    'description',
    'canonical' => url()->current(),
    'schema' => [],
])

@php
    $siteName = config('seo.site_name', 'FurimaDeck');
    $image = asset(ltrim(config('seo.image', '/images/furimadeck-hero.png'), '/'));
    $homeUrl = route('home');
    $organizationId = $homeUrl.'#organization';
    $websiteId = $homeUrl.'#website';
    $webpageId = $canonical.'#webpage';
    $organization = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => $organizationId,
        'name' => config('seo.organization.name'),
        'url' => config('seo.organization.url'),
        'logo' => [
            '@type' => 'ImageObject',
            'url' => asset(ltrim(config('seo.organization.logo'), '/')),
        ],
    ];
    $website = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => $websiteId,
        'name' => $siteName,
        'url' => $homeUrl,
        'publisher' => ['@id' => $organizationId],
        'inLanguage' => 'ja',
    ];
    $webPage = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        '@id' => $webpageId,
        'name' => $title,
        'description' => $description,
        'url' => $canonical,
        'isPartOf' => ['@id' => $websiteId],
        'publisher' => ['@id' => $organizationId],
        'primaryImageOfPage' => [
            '@type' => 'ImageObject',
            'url' => $image,
        ],
        'inLanguage' => 'ja',
        'dateModified' => config('seo.updated_at'),
    ];
    $breadcrumb = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            [
                '@type' => 'ListItem',
                'position' => 1,
                'name' => $siteName,
                'item' => $homeUrl,
            ],
            [
                '@type' => 'ListItem',
                'position' => 2,
                'name' => $title,
                'item' => $canonical,
            ],
        ],
    ];
    $schemas = array_filter(array_merge([$organization, $website, $webPage, $breadcrumb], is_array($schema) ? $schema : []));
@endphp

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1">
    <meta name="theme-color" content="#0f172a">
    <link rel="canonical" href="{{ $canonical }}">

    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ config('seo.locale', 'ja_JP') }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:image:alt" content="{{ $siteName }}">
    <meta property="og:image:width" content="{{ config('seo.image_width', 1200) }}">
    <meta property="og:image:height" content="{{ config('seo.image_height', 630) }}">

    <meta name="twitter:card" content="{{ config('seo.twitter_card', 'summary_large_image') }}">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">
    <meta name="twitter:image:alt" content="{{ $siteName }}">

    <x-pwa-head />
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @foreach ($schemas as $schemaItem)
        <script type="application/ld+json">@json($schemaItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endforeach
</head>
<body class="bg-white text-slate-950 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3 font-black" aria-label="FurimaDeck トップページ">
                <span>FurimaDeck</span>
            </a>
            <nav class="flex flex-wrap justify-end gap-x-4 gap-y-2 text-sm font-bold text-slate-700" aria-label="公開ページ">
                <a href="{{ route('marketing.features') }}" class="hover:text-slate-950">機能</a>
                <a href="{{ route('marketing.use-cases') }}" class="hover:text-slate-950">活用例</a>
                <a href="{{ route('marketing.pricing') }}" class="hover:text-slate-950">料金</a>
                <a href="{{ route('legal.faq') }}" class="hover:text-slate-950">FAQ</a>
                <a href="{{ route('login') }}" class="hover:text-slate-950">ログイン</a>
            </nav>
        </div>
    </header>

    <main>{{ $slot }}</main>

    <footer class="border-t border-slate-200 bg-slate-50 py-8">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 text-sm font-semibold text-slate-700 sm:px-6 lg:px-8">
            <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="フッター">
                <a href="{{ route('marketing.features') }}" class="hover:text-slate-950">機能</a>
                <a href="{{ route('marketing.use-cases') }}" class="hover:text-slate-950">活用例</a>
                <a href="{{ route('marketing.pricing') }}" class="hover:text-slate-950">料金</a>
                <a href="{{ route('legal.faq') }}" class="hover:text-slate-950">よくある質問</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-slate-950">利用規約</a>
                <a href="{{ route('legal.privacy') }}" class="hover:text-slate-950">プライバシーポリシー</a>
                <a href="{{ route('legal.commercial') }}" class="hover:text-slate-950">特定商取引法に基づく表記</a>
                <a href="{{ route('legal.contact') }}" class="hover:text-slate-950">お問い合わせ</a>
            </nav>
            <p>&copy; 2026 FurimaDeck. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
