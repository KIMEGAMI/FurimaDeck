@php
    $pageSeo = config('seo.pages')['marketing.features'] ?? [];
    $schema = [[
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => config('seo.site_name'),
        'applicationCategory' => config('seo.software.category'),
        'operatingSystem' => config('seo.software.operating_system'),
        'url' => route('home'),
        'description' => $pageSeo['description'],
        'featureList' => [
            '画像付き商品登録',
            '在庫管理',
            'SOLD管理',
            '売上・利益分析',
            'CSV管理',
            '外部CSV変換',
            '重複チェック',
            'PWA対応',
        ],
    ]];
@endphp

<x-marketing-layout
    :title="$pageSeo['title']"
    :description="$pageSeo['description']"
    :canonical="route('marketing.features')"
    :schema="$schema"
>
    <section class="bg-slate-950 py-16 text-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <p class="text-sm font-black tracking-[0.24em] text-cyan-200">FEATURES</p>
            <h1 class="mt-4 max-w-4xl text-4xl font-black leading-tight md:text-5xl">フリマ販売の収益管理を、ひとつのサービスに。</h1>
            <p class="mt-5 max-w-3xl text-base font-semibold leading-8 text-cyan-100">
                FurimaDeckは、フリマ販売や複数販路運用で散らばりやすい商品情報、画像、販売状況、売上、利益をまとめて管理する収益管理SaaSです。
            </p>
        </div>
    </section>

    <section class="py-14">
        <div class="mx-auto grid max-w-7xl gap-5 px-4 sm:px-6 md:grid-cols-2 lg:grid-cols-3 lg:px-8">
            @foreach ([
                ['画像付き商品登録', '管理ID、商品タイトル、ジャンル、仕入れ価格、販売価格、手数料、送料、コメント、商品画像をまとめて登録できます。'],
                ['出品中・SOLD管理', '販売前の商品とSOLD商品を切り替えて管理し、売上や利益の集計に反映できます。'],
                ['CSV管理・変換', 'FurimaDeck形式CSVに加えて、ヤフオク売上CSV、メルカリShops CSVなど外部CSVの変換、バックアップCSV、復元用CSVをまとめて管理できます。'],
                ['売上・利益分析', '売上、仕入れ、手数料、送料、実利益を確認し、月次の振り返りや価格調整に活用できます。'],
                ['重複チェック', '同じ商品が複数登録された場合に、最新の商品を残して重複分を整理できます。'],
                ['スマホ・PWA対応', 'スマホから商品画像を登録しやすく、ホーム画面追加によるアプリ風の利用にも対応しています。'],
            ] as [$heading, $body])
                <article class="rounded-lg border border-slate-200 bg-white p-6">
                    <h2 class="text-xl font-black text-slate-950">{{ $heading }}</h2>
                    <p class="mt-3 font-semibold leading-7 text-slate-700">{{ $body }}</p>
                </article>
            @endforeach
        </div>
    </section>
</x-marketing-layout>
