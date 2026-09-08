@php
    $pageSeo = config('seo.pages')['marketing.pricing'] ?? [];
    $pricingFaqs = [
        ['Freeプランはいくらですか？', 'Freeプランは月額0円です。商品登録50件、カテゴリ登録5件まで利用できます。'],
        ['Premiumプランはいくらですか？', 'Premiumプランは7日間無料お試し後、月額480円（税込）です。商品登録数とカテゴリ数の制限を外し、CSV管理、売上分析、ジャンル別分析、重複チェックまでまとめて使えるため、フリマ販売の毎日の作業を短くできます。'],
        ['Premiumは解約できますか？', 'ログイン後の契約管理画面からStripeの契約管理画面へ進み、解約できます。7日間無料お試し中に使い心地を確認してから継続を判断できます。'],
    ];
    $schema = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'FurimaDeck Premium',
            'description' => $pageSeo['description'],
            'brand' => [
                '@type' => 'Brand',
                'name' => config('seo.site_name'),
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => route('marketing.pricing'),
                'price' => '480',
                'priceCurrency' => 'JPY',
                'availability' => 'https://schema.org/InStock',
                'description' => '7日間無料お試し後、月額480円（税込）で利用できます。',
            ],
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => collect($pricingFaqs)->map(fn ($faq) => [
                '@type' => 'Question',
                'name' => $faq[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq[1]],
            ])->values()->all(),
        ],
    ];
@endphp

<x-marketing-layout
    :title="$pageSeo['title']"
    :description="$pageSeo['description']"
    :canonical="route('marketing.pricing')"
    :schema="$schema"
>
    <section class="bg-slate-950 py-16 text-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="text-sm font-black tracking-[0.24em] text-cyan-200">PRICING</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-black leading-tight md:text-5xl">料金体系</h1>
            <p class="mt-5 max-w-3xl text-base font-semibold leading-8 text-cyan-100">
                Freeは小さく試すための無料プランです。Premiumは7日間無料お試し後、月額480円（税込）で登録制限をなくし、CSV、売上分析、ジャンル別分析などフリマ販売の運用に必要な機能をまとめて利用できます。
            </p>
        </div>
    </section>

    <section class="bg-white py-14">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-5 lg:grid-cols-2">
                <article class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-black tracking-[0.18em] text-slate-500">FREE</p>
                            <h2 class="mt-2 text-3xl font-black text-slate-950">無料</h2>
                            <p class="mt-3 text-sm font-bold leading-7 text-slate-600">
                                まず操作感を確認したい方向けの無料プランです。
                            </p>
                        </div>
                        <div class="rounded-lg bg-slate-100 px-5 py-4 text-center">
                            <p class="text-3xl font-black text-slate-950">¥0</p>
                            <p class="mt-1 text-xs font-bold text-slate-500">月額</p>
                        </div>
                    </div>

                    <div class="mt-8 rounded-lg border border-slate-200 bg-slate-50 p-5">
                        <h3 class="text-lg font-black text-slate-950">Freeの制限</h3>
                        <ul class="mt-4 space-y-3 text-sm font-bold leading-7 text-slate-700">
                            <li>商品登録は50件まで</li>
                            <li>大ジャンル・小ジャンルなどカテゴリ管理は5件まで</li>
                            <li>CSV一括登録は利用不可</li>
                            <li>売上CSV出力は利用不可</li>
                            <li>ジャンル別売上分析は利用不可</li>
                            <li>重複チェックや高度な分析は利用不可</li>
                        </ul>
                    </div>

                    <a href="{{ route('register') }}" class="mt-6 inline-flex w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-black text-slate-900 shadow-sm hover:bg-slate-50">
                        Freeで始める
                    </a>
                </article>

                <article class="rounded-lg border-2 border-cyan-500 bg-cyan-50 p-6 shadow-lg">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-sm font-black tracking-[0.18em] text-cyan-700">PREMIUM</p>
                            <h2 class="mt-2 text-3xl font-black text-slate-950">7日間無料お試し後、月額480円（税込）</h2>
                            <p class="mt-3 text-sm font-bold leading-7 text-slate-700">
                                まずは無料で使い心地を確認。制限を外して、日々の商品登録・分析・CSV作業を本格運用できます。
                            </p>
                        </div>
                        <div class="rounded-lg bg-white px-5 py-4 text-center shadow-sm">
                            <p class="text-3xl font-black text-slate-950">¥480</p>
                            <p class="mt-1 text-xs font-bold text-slate-500">7日間無料後・月額税込</p>
                        </div>
                    </div>

                    <div class="mt-8 rounded-lg border border-cyan-200 bg-white p-5">
                        <h3 class="text-lg font-black text-slate-950">Premiumで使える機能</h3>
                        <ul class="mt-4 space-y-3 text-sm font-bold leading-7 text-slate-700">
                            <li>商品登録数の制限なし</li>
                            <li>カテゴリ登録数の制限なし</li>
                            <li>画像付き商品登録</li>
                            <li>出品中・SOLD管理</li>
                            <li>FurimaDeck形式CSVの一括登録</li>
                            <li>ヤフオク売上CSV・メルカリShops CSVの変換登録</li>
                            <li>売上CSV、全商品バックアップCSV、復元用CSVの出力</li>
                            <li>売上、利益、利益率の分析</li>
                            <li>大ジャンル・小ジャンル別の売上分析</li>
                            <li>重複チェック、滞留在庫確認、運用改善に使える分析</li>
                        </ul>
                    </div>

                    <a href="{{ route('subscriptions.index') }}" class="mt-6 inline-flex w-full items-center justify-center rounded-lg bg-cyan-700 px-6 py-3 text-sm font-black text-white shadow hover:bg-cyan-800">
                        7日間無料でPremiumを試す
                    </a>
                </article>
            </div>

            <div class="mt-8 rounded-lg border border-amber-200 bg-amber-50 p-5">
                <h2 class="text-lg font-black text-amber-900">契約と解約について</h2>
                <p class="mt-3 text-sm font-bold leading-7 text-amber-800">
                    Premiumは7日間無料お試し後、月額480円（税込）で、解約されるまで1か月ごとに自動更新されます。無料期間中に商品登録、CSV、売上分析、重複チェックまで試せます。解約はログイン後の「契約・解約」画面からStripeの契約管理画面へ進んで行えます。期間終了時に解約する場合は現在の請求期間終了までPremium機能を利用でき、即時解約の場合は解約完了時点で利用できなくなる場合があります。
                </p>
            </div>

            <div class="mt-8 grid gap-4 lg:grid-cols-3">
                @foreach ($pricingFaqs as [$question, $answer])
                    <article class="rounded-lg border border-slate-200 bg-white p-5">
                        <h2 class="text-base font-black text-slate-950">{{ $question }}</h2>
                        <p class="mt-3 text-sm font-bold leading-7 text-slate-700">{{ $answer }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
</x-marketing-layout>
