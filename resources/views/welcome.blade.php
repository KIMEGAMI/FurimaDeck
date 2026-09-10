@php
    $siteName = config('seo.site_name', 'FurimaDeck');
    $premiumPrice = (int) config('furimadeck.billing.monthly_price_jpy');
    $trialDays = (int) config('furimadeck.billing.trial_period_days');
    $pageSeo = config('seo.pages.home', []);
    $title = $pageSeo['title'] ?? config('seo.title');
    $description = $pageSeo['description'] ?? config('seo.description');
    $keywords = config('seo.keywords');
    $canonical = route('home');
    $versionedAsset = function (string $path): string {
        $normalizedPath = ltrim($path, '/');
        $absolutePath = public_path($normalizedPath);
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : (string) config('seo.updated_at');

        return asset($normalizedPath).'?v='.$version;
    };
    $heroImage = $versionedAsset('images/furimadeck-hero.png');
    $valueImage = $versionedAsset('images/furimadeck-value.png');
    $organizationId = $canonical.'#organization';
    $websiteId = $canonical.'#website';
    $organizationSchema = [
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
    $websiteSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => $websiteId,
        'name' => $siteName,
        'url' => $canonical,
        'publisher' => ['@id' => $organizationId],
        'inLanguage' => 'ja',
    ];
    $webPageSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        '@id' => $canonical.'#webpage',
        'name' => $title,
        'description' => $description,
        'url' => $canonical,
        'isPartOf' => ['@id' => $websiteId],
        'publisher' => ['@id' => $organizationId],
        'primaryImageOfPage' => [
            '@type' => 'ImageObject',
            'url' => $heroImage,
        ],
        'inLanguage' => 'ja',
        'dateModified' => config('seo.updated_at'),
    ];
    $softwareSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        '@id' => $canonical.'#software',
        'name' => $siteName,
        'applicationCategory' => config('seo.software.category'),
        'operatingSystem' => config('seo.software.operating_system'),
        'description' => $description,
        'url' => $canonical,
        'image' => [$heroImage, $valueImage],
        'inLanguage' => 'ja',
        'publisher' => ['@id' => $organizationId],
        'offers' => [
            [
                '@type' => 'Offer',
                'name' => 'Free',
                'price' => '0',
                'priceCurrency' => 'JPY',
                'availability' => 'https://schema.org/InStock',
            ],
            [
                '@type' => 'Offer',
                'name' => 'Premium',
                'price' => (string) $premiumPrice,
                'priceCurrency' => 'JPY',
                'availability' => 'https://schema.org/InStock',
                'description' => "{$trialDays}日間無料お試し後、月額".number_format($premiumPrice).'円（税込）で利用できます。',
            ],
        ],
        'featureList' => ['画像付き商品登録', '在庫管理', 'SOLD管理', '売上と利益の分析', 'CSV管理', '外部CSV変換', '重複チェック', 'PWA対応'],
    ];
    $schemas = [$organizationSchema, $websiteSchema, $webPageSchema, $softwareSchema];
@endphp

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="keywords" content="{{ $keywords }}">
    <meta name="robots" content="index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1">
    <meta name="theme-color" content="#0f172a">
    <link rel="canonical" href="{{ $canonical }}">
    <link rel="preload" as="image" href="{{ $heroImage }}" fetchpriority="high">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ config('seo.locale', 'ja_JP') }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $heroImage }}">
    <meta property="og:image:alt" content="FurimaDeck フリマ販売の収益管理SaaS">
    <meta property="og:image:width" content="{{ config('seo.image_width', 1200) }}">
    <meta property="og:image:height" content="{{ config('seo.image_height', 630) }}">
    <meta name="twitter:card" content="{{ config('seo.twitter_card', 'summary_large_image') }}">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $heroImage }}">
    <meta name="twitter:image:alt" content="FurimaDeck フリマ販売の収益管理SaaS">
    <x-pwa-head />
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @foreach ($schemas as $schema)
        <script type="application/ld+json">@json($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endforeach
</head>
<body class="bg-white text-slate-950 antialiased">
    <header class="fixed inset-x-0 top-0 z-30 border-b border-white/30 bg-white/85 backdrop-blur-md">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3" aria-label="FurimaDeck トップページ">
                <span class="text-lg font-black tracking-[0.08em] text-cyan-700">FurimaDeck</span>
            </a>
            <nav class="flex flex-wrap justify-end gap-x-3 gap-y-2 text-sm font-black" aria-label="メインナビゲーション">
                <a href="{{ route('marketing.features') }}" class="px-2 py-2 text-cyan-800 hover:text-cyan-600">機能</a>
                <a href="{{ route('marketing.use-cases') }}" class="px-2 py-2 text-cyan-800 hover:text-cyan-600">活用例</a>
                <a href="{{ route('marketing.pricing') }}" class="px-2 py-2 text-cyan-800 hover:text-cyan-600">料金</a>
                <a href="{{ route('legal.faq') }}" class="px-2 py-2 text-cyan-800 hover:text-cyan-600">FAQ</a>
                <a href="{{ route('login') }}" class="rounded-md border border-cyan-300 px-4 py-2 text-cyan-800 hover:bg-cyan-50">ログイン</a>
            </nav>
        </div>
    </header>

    <main>
        <section class="relative min-h-[88svh] overflow-hidden pt-20">
            <img src="{{ $heroImage }}" alt="FurimaDeck フリマ販売の収益管理SaaSの紹介画像" class="absolute inset-0 h-full w-full object-cover object-center">
            <div class="absolute inset-x-0 bottom-0 h-64 bg-gradient-to-t from-white via-white/80 to-transparent"></div>
            <div class="relative mx-auto flex min-h-[calc(88svh-80px)] max-w-7xl items-end px-4 pb-10 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <h1 class="sr-only">フリマ販売の収益管理ならFurimaDeck</h1>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('register') }}" class="rounded-md bg-cyan-700 px-7 py-4 text-center text-sm font-black text-white shadow-lg shadow-cyan-900/20 hover:bg-cyan-800">はじめる</a>
                        <form method="POST" action="{{ route('login.demo') }}">
                            @csrf
                            <button type="submit" class="rounded-md border border-cyan-300 bg-white/90 px-7 py-4 text-sm font-black text-black shadow-lg hover:bg-white">デモを見る</button>
                        </form>
                    </div>
                    <p class="mt-5 max-w-2xl text-sm font-black leading-7 text-cyan-950">
                        商品登録、画像管理、在庫状況、SOLD、売上、CSVをまとめて管理。フリマ販売の日々の収益管理を、もっと見やすく、もっと速くします。
                    </p>
                </div>
            </div>
        </section>

        <section id="features" class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <p class="text-sm font-black tracking-[0.24em] text-cyan-700">FEATURES</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight text-cyan-950 sm:text-4xl">フリマ販売の収益を、ひとつの画面で。</h2>
                    <p class="mt-4 text-base font-bold leading-8 text-slate-700">複数サービスへ出品していると、商品名、価格、画像、販売状況、利益の管理が散らかりやすくなります。FurimaDeckは、その運用を毎日使える形に整理します。</p>
                </div>
                <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    @foreach ([['商品管理', '画像付きで商品を登録し、出品中・SOLD・下書きを整理できます。'], ['CSV管理', 'FurimaDeck形式CSV、ヤフオクCSV変換、メルカリShops CSV変換、バックアップCSV、復元用CSVをまとめて管理できます。'], ['売上分析', '売上、手数料、送料、実利益を確認し、販売の状況を追えます。'], ['運用改善', '重複チェックやジャンル別分析で、毎日の管理ロスを減らします。']] as [$heading, $body])
                        <article class="rounded-lg border border-cyan-100 bg-cyan-50/70 p-6">
                            <h3 class="text-lg font-black text-cyan-950">{{ $heading }}</h3>
                            <p class="mt-3 text-sm font-bold leading-7 text-slate-700">{{ $body }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-white pb-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <img src="{{ $valueImage }}" alt="FurimaDeck案内" class="w-full rounded-lg border border-cyan-100 shadow-xl" loading="lazy">
            </div>
        </section>

        <section id="workflow" class="bg-cyan-950 py-16 text-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid gap-10 lg:grid-cols-[0.85fr_1.15fr] lg:items-center">
                    <div>
                        <p class="text-sm font-black tracking-[0.24em] text-cyan-200">WORKFLOW</p>
                        <h2 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">登録から振り返りまで、作業が途切れない。</h2>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach ([['1', '商品を登録', '画像、仕入れ価格、販売価格、ジャンルを入力します。'], ['2', 'SOLDに更新', '販売後にSOLD化し、手数料や送料を反映します。'], ['3', '利益を確認', '売上と実利益を見て、次の仕入れや値下げ判断につなげます。']] as [$step, $heading, $body])
                            <article class="rounded-lg border border-white/15 bg-white/10 p-5">
                                <p class="text-sm font-black text-cyan-200">STEP {{ $step }}</p>
                                <h3 class="mt-3 text-xl font-black">{{ $heading }}</h3>
                                <p class="mt-3 text-sm font-bold leading-7 text-cyan-50">{{ $body }}</p>
                            </article>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section id="pricing" class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-cyan-100 bg-cyan-50 p-6 sm:p-8">
                    <p class="text-sm font-black tracking-[0.24em] text-cyan-700">PRICING</p>
                    <h2 class="mt-3 text-3xl font-black text-cyan-950">FreeプランとPremiumプランがあります。</h2>
                    <p class="mt-4 max-w-3xl text-sm font-bold leading-7 text-slate-700">Freeは商品登録50件まで利用できます。Premiumは{{ $trialDays }}日間無料お試し後、月額{{ number_format($premiumPrice) }}円（税込）で商品登録数の制限がなくなり、CSV登録・出力、販売と利益の管理をまとめて使えます。フリマ販売の登録、確認、振り返りをひとつに集めて、毎日の作業時間を減らします。</p>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="{{ route('marketing.pricing') }}" class="inline-flex rounded-md bg-cyan-700 px-6 py-3 text-sm font-black text-white hover:bg-cyan-800">料金体系を見る</a>
                        <a href="{{ route('register') }}" class="inline-flex rounded-md border border-cyan-300 bg-white px-6 py-3 text-sm font-black text-cyan-900 hover:bg-cyan-50">Freeで始める</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-cyan-100 bg-white py-8">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 text-sm font-bold text-cyan-900 sm:px-6 lg:px-8">
            <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="フッター">
                <a href="{{ route('marketing.features') }}" class="hover:text-cyan-600">機能</a>
                <a href="{{ route('marketing.use-cases') }}" class="hover:text-cyan-600">活用例</a>
                <a href="{{ route('marketing.pricing') }}" class="hover:text-cyan-600">料金</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-cyan-600">利用規約</a>
                <a href="{{ route('legal.privacy') }}" class="hover:text-cyan-600">プライバシーポリシー</a>
                <a href="{{ route('legal.commercial') }}" class="hover:text-cyan-600">特定商取引法に基づく表記</a>
                <a href="{{ route('legal.faq') }}" class="hover:text-cyan-600">よくある質問</a>
                <a href="{{ route('legal.contact') }}" class="hover:text-cyan-600">お問い合わせ</a>
            </nav>
            <p>&copy; 2026 FurimaDeck. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
