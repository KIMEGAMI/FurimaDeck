@php
    $pageSeo = config('seo.pages')['marketing.use-cases'] ?? [];
    $schema = [[
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => 'FurimaDeck 活用例',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'メルカリ販売の在庫管理'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '外部売上CSVの変換登録'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => '仕入れ後の商品整理'],
            ['@type' => 'ListItem', 'position' => 4, 'name' => '月次売上と利益の確認'],
        ],
    ]];
@endphp

<x-marketing-layout
    :title="$pageSeo['title']"
    :description="$pageSeo['description']"
    :canonical="route('marketing.use-cases')"
    :schema="$schema"
>
    <section class="bg-slate-950 py-16 text-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="text-sm font-black tracking-[0.24em] text-cyan-200">USE CASES</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-black leading-tight md:text-5xl">複数販路のフリマ販売を、迷わず管理する。</h1>
            <p class="mt-5 max-w-3xl text-base font-semibold leading-8 text-cyan-100">
                メルカリ、ヤフオク、ラクマ、Yahooフリマなど販売先が増えるほど、商品情報は散らかりやすくなります。FurimaDeckは毎日の販売管理をひとつにまとめます。
            </p>
        </div>
    </section>

    <section class="py-14">
        <div class="mx-auto grid max-w-7xl gap-5 px-4 sm:px-6 lg:px-8">
            @foreach ([
                ['フリマ販売の在庫管理', '出品中の商品、SOLDの商品、仕入れ価格、販売価格を一覧で確認できます。'],
                ['外部売上CSVの整理', 'ヤフオクやメルカリShopsの売上CSVをFurimaDeck形式CSVへ変換し、一括登録の作業を減らせます。'],
                ['仕入れ後の商品登録', '撮影した画像と商品タイトルを登録し、ジャンル別に整理できます。'],
                ['月次の売上確認', '売上、手数料、送料、利益を確認し、販売状況を振り返れます。'],
                ['値下げ・再出品の判断', '売れ残りや低利益の商品を見つけやすくし、次の改善につなげます。'],
            ] as [$heading, $body])
                <article class="rounded-lg border border-slate-200 bg-white p-6">
                    <h2 class="text-xl font-black text-slate-950">{{ $heading }}</h2>
                    <p class="mt-3 font-semibold leading-7 text-slate-700">{{ $body }}</p>
                </article>
            @endforeach
        </div>
    </section>
</x-marketing-layout>
