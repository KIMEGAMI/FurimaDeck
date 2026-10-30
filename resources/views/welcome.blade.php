@php
    $siteName = config('seo.site_name', 'FurimaDeck');
    $premiumPrice = (int) config('furimadeck.billing.monthly_price_jpy');
    $trialDays = (int) config('furimadeck.billing.trial_period_days');
    $pageSeo = config('seo.pages.home', []);
    $title = $pageSeo['title'] ?? config('seo.title');
    $description = $pageSeo['description'] ?? config('seo.description');
    $keywords = config('seo.keywords');
    $freeProductLimit = (int) config('furimadeck.plans.free.product_limit', 50);
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
        'featureList' => ['画像付き商品登録', '出品情報管理', '会計データ内の仕入記録管理', '販売記録', '売上・実利益分析', 'ジャンル別分析', 'ジャンルと出品先のクロス分析', '長期在庫の確認', 'freee・Money Forward向け会計CSV作成', 'CSV管理', 'PWA対応'],
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
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=3">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @foreach ($schemas as $schema)
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" type="application/ld+json">@json($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)</script>
    @endforeach
</head>
<body class="bg-slate-950 text-white antialiased">
    <header class="sticky inset-x-0 top-0 z-30 border-b border-white/10 bg-slate-950/90 backdrop-blur-xl">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}" class="shrink-0 text-lg font-black tracking-[0.08em] text-lime-300" aria-label="FurimaDeck トップページ">FurimaDeck</a>
            <nav class="hidden items-center gap-6 text-sm font-bold text-slate-200 md:flex" aria-label="メインナビゲーション">
                <a href="#features" class="transition hover:text-lime-300">できること</a>
                <a href="#analysis" class="transition hover:text-lime-300">販売分析</a>
                <a href="{{ route('marketing.use-cases') }}" class="transition hover:text-lime-300">活用例</a>
                <a href="#pricing" class="transition hover:text-lime-300">料金</a>
                <a href="{{ route('legal.faq') }}" class="transition hover:text-lime-300">FAQ</a>
            </nav>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-bold text-white transition hover:bg-white/10">ログイン</a>
                <a href="{{ route('register') }}" class="rounded-lg bg-lime-300 px-4 py-2.5 text-sm font-black text-slate-950 shadow-lg shadow-lime-950/30 transition hover:bg-lime-200">無料で始める</a>
            </div>
        </div>
        <details class="group border-t border-white/10 px-4 py-2 md:hidden">
            <summary class="mx-auto max-w-7xl cursor-pointer list-none py-1 text-xs font-bold text-slate-300">メニューを開く</summary>
            <nav class="mx-auto flex max-w-7xl flex-wrap gap-x-5 gap-y-2 py-3 text-sm font-bold text-slate-200" aria-label="モバイルメニュー">
                <a href="#features" class="hover:text-lime-300">できること</a>
                <a href="#analysis" class="hover:text-lime-300">販売分析</a>
                <a href="{{ route('marketing.use-cases') }}" class="hover:text-lime-300">活用例</a>
                <a href="#pricing" class="hover:text-lime-300">料金</a>
                <a href="{{ route('legal.faq') }}" class="hover:text-lime-300">FAQ</a>
            </nav>
        </details>
    </header>

    <main>
        <section class="relative isolate overflow-hidden border-b border-white/10 bg-slate-950">
            <img src="{{ asset('images/bg.png') }}" alt="" class="absolute inset-0 -z-20 h-full w-full object-cover object-center" fetchpriority="high">
            <div class="absolute inset-0 -z-10 bg-gradient-to-r from-slate-950 via-slate-950/90 to-slate-950/65"></div>
            <div class="absolute inset-0 -z-10 bg-gradient-to-t from-slate-950/80 via-transparent to-slate-950/30"></div>
            <div class="mx-auto grid min-h-[min(760px,calc(100svh-4rem))] max-w-7xl items-center gap-10 px-4 py-16 sm:px-6 md:py-20 lg:grid-cols-[1.05fr_0.95fr] lg:px-8">
                <div class="max-w-3xl">
                    <p class="inline-flex items-center gap-2 rounded-full border border-lime-300/30 bg-slate-950/60 px-4 py-2 text-xs font-black tracking-[0.14em] text-lime-200 sm:text-sm">
                        <span class="h-2 w-2 rounded-full bg-lime-300"></span>商品・出品・販売・利益をひとつに
                    </p>
                    <h1 class="mt-6 text-4xl font-black leading-[1.15] tracking-tight text-white sm:text-5xl lg:text-6xl">
                        いくつもの出品先を、<span class="text-lime-300">ひとつの利益管理</span>へ。
                    </h1>
                    <p class="mt-6 max-w-2xl text-base font-semibold leading-8 text-slate-200 sm:text-lg">
                        商品、出品価格、仕入れ、販売をまとめて記録。手数料や送料を差し引いた実利益まで見えるから、次に何を見直すかがわかります。
                    </p>
                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <a href="{{ route('register') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-lime-300 px-6 py-3 text-sm font-black text-slate-950 shadow-lg shadow-lime-950/40 transition hover:bg-lime-200">Freeで始める <span class="ml-2" aria-hidden="true">→</span></a>
                        <a href="#features" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-white/25 bg-slate-950/40 px-6 py-3 text-sm font-black text-white transition hover:bg-white/10">機能を見る</a>
                        @if ((bool) config('demo.user_enabled') && filled(config('demo.user_email')) && filled(config('demo.user_password')))
                            <form method="POST" action="{{ route('login.demo') }}">
                                @csrf
                                <button type="submit" class="inline-flex min-h-12 items-center justify-center rounded-lg border border-white/25 px-5 py-3 text-sm font-black text-white transition hover:bg-white/10">デモを見る</button>
                            </form>
                        @endif
                    </div>
                    <p class="mt-5 text-sm font-bold text-slate-300">Freeは商品{{ number_format($freeProductLimit) }}件まで。Premiumは{{ $trialDays }}日間無料、その後月額{{ number_format($premiumPrice) }}円（税込）。</p>
                    <div class="mt-7 flex flex-wrap gap-2" aria-label="対応する販売先の例">
                        @foreach (['メルカリ', 'Yahoo!フリマ', 'ヤフオク!', 'ラクマ'] as $marketplace)
                            <span class="rounded-full border border-white/15 bg-slate-950/45 px-3 py-1.5 text-xs font-bold text-slate-200">{{ $marketplace }}</span>
                        @endforeach
                        <span class="self-center text-xs font-semibold text-slate-400">など複数の出品先を管理</span>
                    </div>
                </div>
                <figure class="relative mx-auto w-full max-w-2xl">
                    <div class="absolute -inset-4 rounded-3xl bg-lime-300/10 blur-2xl" aria-hidden="true"></div>
                    <img src="{{ $valueImage }}" alt="商品と売上分析を一緒に確認するFurimaDeckの画面イメージ" class="relative w-full rounded-2xl border border-white/20 shadow-2xl shadow-black/60" loading="lazy">
                    <figcaption class="relative mx-auto -mt-5 w-[92%] rounded-xl border border-white/15 bg-slate-900/95 px-4 py-3 text-center text-xs font-bold text-slate-200 shadow-xl sm:text-sm">
                        出品中の商品から、売れた後の実利益まで。
                    </figcaption>
                </figure>
            </div>
        </section>

        <section id="features" class="border-b border-white/10 bg-slate-950 py-16 sm:py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <p class="text-xs font-black tracking-[0.24em] text-lime-300">ONE WORKSPACE</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">バラバラだった販売の記録を、ひとつにつなぐ。</h2>
                    <p class="mt-4 text-base font-semibold leading-8 text-slate-300">商品一覧を中心に、出品情報と販売記録をつなげて管理。二重入力を減らし、日々の確認から月ごとの振り返りまで同じデータで行えます。</p>
                </div>
                <div class="mt-10 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ([
                        ['商品・出品情報', '写真、商品ID、ジャンルを登録し、販売先ごとの出品価格を管理。仕入情報や仕入先は会計データメニューで管理します。', '01'],
                        ['販売を記録', '商品と販売先、売上、送料を記録。会計データに登録した仕入額や費用を反映した利益を確認できます。', '02'],
                        ['売上・実利益の分析', '月ごとの売上と実利益を並べて確認。手数料や送料を反映した数字で、販売の結果を振り返れます。', '03'],
                        ['ジャンル別ランキング', '大・中・小ジャンルそれぞれ売上上位10件を縦棒グラフで比較。売上・実利益・販売数を確認できます。', '04'],
                        ['ジャンル × 出品先', 'どのジャンルがどの販売先で売れているか、売上・販売数・実利益をクロスして比較できます。', '05'],
                        ['売れていない商品を発見', '初期表示は出品から30日以上。日数を指定して絞り込み、価格や説明を見直す商品を探せます。', '06'],
                    ] as [$heading, $body, $number])
                        <article class="group rounded-2xl border border-white/10 bg-white/[0.04] p-6 transition hover:border-lime-300/40 hover:bg-white/[0.07]">
                            <p class="text-xs font-black tracking-[0.18em] text-lime-300">{{ $number }} / FURIMADECK</p>
                            <h3 class="mt-4 text-xl font-black text-white">{{ $heading }}</h3>
                            <p class="mt-3 text-sm font-medium leading-7 text-slate-300">{{ $body }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="analysis" class="border-b border-white/10 bg-slate-900 py-16 sm:py-20">
            <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
                <div>
                    <p class="text-xs font-black tracking-[0.24em] text-lime-300">FROM DATA TO ACTION</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">数字を並べるだけで終わらせない。</h2>
                    <p class="mt-5 text-base font-semibold leading-8 text-slate-300">売上分析では、月ごとの推移、ジャンルごとの違い、出品先ごとの販売実績を確認できます。さらに、売れずに残っている商品を日数で絞り込み、次の価格見直しや再出品の候補を見つけられます。</p>
                    <ul class="mt-6 space-y-3 text-sm font-bold text-slate-200">
                        <li class="flex gap-3"><span class="text-lime-300" aria-hidden="true">✓</span><span>ジャンル別の売上・実利益・販売数を比較</span></li>
                        <li class="flex gap-3"><span class="text-lime-300" aria-hidden="true">✓</span><span>ジャンル × 出品先のクロス分析</span></li>
                        <li class="flex gap-3"><span class="text-lime-300" aria-hidden="true">✓</span><span>30日以上の長期在庫を標準表示、日数指定も可能</span></li>
                    </ul>
                    <a href="{{ route('marketing.features') }}" class="mt-8 inline-flex items-center gap-2 text-sm font-black text-lime-300 transition hover:text-lime-200">機能を詳しく見る <span aria-hidden="true">→</span></a>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <article class="rounded-2xl border border-white/10 bg-slate-950 p-6 shadow-xl shadow-black/20 sm:col-span-2">
                        <p class="text-xs font-black tracking-[0.18em] text-lime-300">CROSS ANALYSIS</p>
                        <h3 class="mt-3 text-xl font-black text-white">ジャンル × 出品先</h3>
                        <p class="mt-3 text-sm font-medium leading-7 text-slate-300">ジャンルごとに販売先を横断して、売上・販売数・実利益を比べます。</p>
                        <div class="mt-5 flex flex-wrap items-center gap-2 text-xs font-bold">
                            <span class="rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-slate-200">ジャンル</span>
                            <span class="text-lime-300" aria-hidden="true">×</span>
                            <span class="rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-slate-200">出品先</span>
                            <span class="text-lime-300" aria-hidden="true">→</span>
                            <span class="rounded-lg border border-lime-300/30 bg-lime-300/10 px-3 py-2 text-lime-100">売上・件数・利益</span>
                        </div>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-slate-950 p-6">
                        <p class="text-xs font-black tracking-[0.18em] text-lime-300">CATEGORY RANKING</p>
                        <h3 class="mt-3 text-lg font-black text-white">ジャンル別上位を確認</h3>
                        <p class="mt-2 text-sm font-medium leading-7 text-slate-300">大・中・小ジャンルそれぞれ売上上位10件を、売上・利益・販売数で比較。</p>
                    </article>
                    <article class="rounded-2xl border border-white/10 bg-slate-950 p-6">
                        <p class="text-xs font-black tracking-[0.18em] text-lime-300">STALE INVENTORY</p>
                        <h3 class="mt-3 text-lg font-black text-white">長期在庫を見直す</h3>
                        <p class="mt-2 text-sm font-medium leading-7 text-slate-300">出品後30日以上を標準に、日数を変えて検索。</p>
                    </article>
                    <article class="rounded-2xl border border-lime-300/25 bg-lime-300/[0.06] p-6">
                        <p class="text-xs font-black tracking-[0.18em] text-lime-300">ACCOUNTING DATA</p>
                        <h3 class="mt-3 text-lg font-black text-white">会計データをCSVで出力</h3>
                        <p class="mt-2 text-sm font-medium leading-7 text-slate-300">確認済みの取引データをfreee・Money Forward用CSVとして出力できます。CSVの取込、仕訳の確認、確定申告書の作成・提出は各会計サービス側で行います。FurimaDeckは税務判断や申告書の作成を行いません。</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="workflow" class="border-b border-white/10 bg-slate-950 py-16 sm:py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <p class="text-xs font-black tracking-[0.24em] text-lime-300">A SIMPLE FLOW</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">いつもの作業の流れで使えます。</h2>
                </div>
                <div class="mt-9 grid gap-4 md:grid-cols-3">
                    @foreach ([['1', '商品を登録', '商品画像、商品名、状態、ジャンルなどの商品情報を登録します。仕入情報や会計データは会計データメニューで管理します。'], ['2', '販売を記録', '売れた商品と販売先を記録。商品情報をもとに売上と利益を整理します。'], ['3', '次の一手を確認', '月別・ジャンル別に振り返り、長期在庫から見直し候補を探します。']] as [$step, $heading, $body])
                        <article class="relative rounded-2xl border border-white/10 bg-white/[0.04] p-6">
                            <p class="text-sm font-black tracking-[0.18em] text-lime-300">STEP {{ $step }}</p>
                            <h3 class="mt-3 text-xl font-black text-white">{{ $heading }}</h3>
                            <p class="mt-3 text-sm font-medium leading-7 text-slate-300">{{ $body }}</p>
                        </article>
                    @endforeach
                </div>
                <div class="mt-6 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-white/10 bg-slate-900 px-5 py-4 text-sm font-bold text-slate-300">
                    <span class="text-lime-300">CSV</span>
                    <span>対応CSVの取り込み・出力、バックアップにも対応。</span>
                    <span class="text-slate-500">｜</span>
                    <span class="text-lime-300">PWA</span>
                    <span>スマートフォンのホーム画面に追加して利用できます。</span>
                </div>
            </div>
        </section>

        <section id="pricing" class="border-b border-white/10 bg-slate-900 py-16 sm:py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="text-xs font-black tracking-[0.24em] text-lime-300">SIMPLE PRICING</p>
                    <h2 class="mt-3 text-3xl font-black tracking-tight text-white sm:text-4xl">まずはFreeから。</h2>
                    <p class="mt-4 text-base font-semibold leading-8 text-slate-300">小さく試して、必要になったらPremiumへ。料金と利用できる機能を事前に確認できます。</p>
                </div>
                <div class="mx-auto mt-9 grid max-w-5xl gap-4 md:grid-cols-2">
                    <article class="rounded-2xl border border-white/15 bg-slate-950 p-6 sm:p-8">
                        <p class="text-sm font-black tracking-[0.18em] text-slate-400">FREE</p>
                        <p class="mt-3 text-4xl font-black text-white">¥0<span class="ml-2 text-sm font-bold text-slate-400">/ 月</span></p>
                        <p class="mt-4 text-sm leading-7 text-slate-300">基本の使い心地を確認したい方に。</p>
                        <ul class="mt-6 space-y-3 text-sm font-bold text-slate-200">
                            <li>商品登録 {{ number_format($freeProductLimit) }}件まで</li>
                            <li>商品・出品情報の管理</li>
                        </ul>
                        <a href="{{ route('register') }}" class="mt-8 inline-flex w-full items-center justify-center rounded-lg border border-white/20 px-5 py-3 text-sm font-black text-white transition hover:bg-white/10">Freeで始める</a>
                    </article>
                    <article class="rounded-2xl border border-lime-300/60 bg-gradient-to-br from-lime-300/10 to-slate-950 p-6 shadow-xl shadow-black/20 sm:p-8">
                        <p class="text-sm font-black tracking-[0.18em] text-lime-300">PREMIUM</p>
                        <p class="mt-3 text-4xl font-black text-white">¥{{ number_format($premiumPrice) }}<span class="ml-2 text-sm font-bold text-slate-300">/ 月（税込）</span></p>
                        <p class="mt-3 text-sm font-bold text-lime-200">最初の{{ $trialDays }}日間は無料</p>
                        <p class="mt-3 text-sm leading-7 text-slate-300">商品数を増やし、CSVと分析を本格的に使いたい方に。</p>
                        <ul class="mt-6 space-y-3 text-sm font-bold text-slate-100">
                            <li>商品登録数の制限なし</li>
                            <li>CSV取り込み・出力、バックアップ</li>
                            <li>売上・利益、ジャンル・出品先分析</li>
                            <li>長期在庫の検索と販売改善</li>
                            <li>会計サービス用データの確認・CSV出力</li>
                        </ul>
                        <a href="{{ route('marketing.pricing') }}" class="mt-8 inline-flex w-full items-center justify-center rounded-lg bg-lime-300 px-5 py-3 text-sm font-black text-slate-950 transition hover:bg-lime-200">料金・機能を確認する</a>
                    </article>
                </div>
                <p class="mt-5 text-center text-xs font-medium leading-6 text-slate-400">Premiumは無料お試し終了後、解約されるまで月ごとに自動更新されます。詳しい条件は<a href="{{ route('marketing.pricing') }}" class="underline decoration-slate-500 underline-offset-4 hover:text-white">料金ページ</a>をご確認ください。</p>
            </div>
        </section>

        <section class="bg-slate-950 py-14 sm:py-16">
            <div class="mx-auto flex max-w-7xl flex-col items-start justify-between gap-6 rounded-2xl border border-lime-300/25 bg-gradient-to-r from-lime-300/10 to-slate-900 px-6 py-8 sm:px-9 md:flex-row md:items-center">
                <div>
                    <p class="text-xs font-black tracking-[0.2em] text-lime-300">START WITH YOUR INVENTORY</p>
                    <h2 class="mt-3 text-2xl font-black text-white sm:text-3xl">商品と利益の管理を、今日からひとつに。</h2>
                    <p class="mt-3 text-sm font-semibold leading-7 text-slate-300">まずはFreeで、FurimaDeckの使い方をお試しください。</p>
                </div>
                <a href="{{ route('register') }}" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-lime-300 px-6 py-3 text-sm font-black text-slate-950 transition hover:bg-lime-200">無料で始める <span class="ml-2" aria-hidden="true">→</span></a>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/10 bg-slate-950 py-8">
        <div class="mx-auto flex max-w-7xl flex-col gap-5 px-4 text-sm font-bold text-slate-300 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}" class="w-fit text-lg font-black tracking-[0.08em] text-lime-300">FurimaDeck</a>
            <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="フッター">
                <a href="{{ route('marketing.features') }}" class="hover:text-white">機能</a>
                <a href="{{ route('marketing.use-cases') }}" class="hover:text-white">活用例</a>
                <a href="{{ route('marketing.pricing') }}" class="hover:text-white">料金</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-white">利用規約</a>
                <a href="{{ route('legal.privacy') }}" class="hover:text-white">プライバシーポリシー</a>
                <a href="{{ route('legal.commercial') }}" class="hover:text-white">特定商取引法に基づく表記</a>
                <a href="{{ route('legal.faq') }}" class="hover:text-white">よくある質問</a>
                <a href="{{ route('legal.contact') }}" class="hover:text-white">お問い合わせ</a>
            </nav>
            <p class="text-xs text-slate-500">&copy; 2026 FurimaDeck. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
