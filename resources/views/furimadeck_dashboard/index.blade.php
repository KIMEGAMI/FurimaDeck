<x-app-layout>
    @php
        $summary = $dashboard['summary'];
        $currentMonth = $dashboard['current_month'];
        $monthlyRows = $dashboard['monthly_stats'];
        $portfolio = $dashboard['portfolio'];
        $salesChart = $monthlyRows->pluck('sales')->values();
        $profitChart = $monthlyRows->pluck('profit')->values();
        $isPremium = $dashboard['can_use_premium_features'];
    @endphp

    <div class="min-h-screen bg-slate-950/70 py-8 text-white" style="background-image: linear-gradient(rgba(2, 6, 23, 0.42), rgba(2, 6, 23, 0.76)), url('{{ asset('images/bg.png') }}'); background-attachment: fixed; background-position: center; background-size: cover;">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <h1 class="sr-only">FurimaDeck Dashboard</h1>

            <section class="grid gap-4 lg:grid-cols-12">
                <article class="rounded-2xl border border-amber-300/80 bg-black/80 p-4 shadow-2xl backdrop-blur-md lg:col-span-4">
                    <p class="text-xs font-black tracking-[0.18em] text-amber-300">NOTICE BOARD</p>
                    <h2 class="mt-1 text-lg font-black text-white">FurimaDeckからのお知らせ</h2>
                    <p class="mt-4 rounded-xl border border-white/15 bg-white/10 p-4 text-sm font-bold text-white">現在、掲載中のお知らせはありません。</p>
                </article>

                <article class="rounded-2xl border border-emerald-300/25 bg-slate-950/75 p-4 shadow-2xl backdrop-blur-md lg:col-span-8">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-black tracking-[0.18em] text-emerald-300">BUSINESS INSIGHTS</p>
                            <h2 class="mt-1 text-lg font-black text-white">経営インサイト</h2>
                        </div>
                        <p class="text-xs font-bold text-slate-200">{{ $month->format('Y年n月') }}の売上目標 ¥{{ number_format($dashboard['monthly_target']) }}</p>
                    </div>

                    <div class="mt-4">
                        <div class="mb-2 flex items-center justify-between text-xs font-bold text-white"><span>目標進捗</span><span>{{ number_format($dashboard['monthly_target_progress'], 1) }}%</span></div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-800"><div class="h-full rounded-full bg-emerald-400" style="width: {{ $dashboard['monthly_target_progress'] }}%"></div></div>
                    </div>

                    @if (! $summary['sales_data_available'])
                        <p class="mb-4 rounded-xl border border-amber-300 bg-amber-400/10 p-3 text-sm font-bold leading-6 text-amber-100">売上データ未登録：現在のCSVは商品・在庫情報のみです。売上金額・利益・販売済み件数は推測せず、売上CSV登録後に集計します。</p>
                    @endif                    <div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
                        <article class="rounded-xl border border-cyan-300/20 bg-cyan-400/10 p-3"><p class="text-xs font-bold text-cyan-200">今月売上</p><p class="mt-2 text-xl font-black text-white">¥{{ number_format($currentMonth['sales']) }}</p><p class="mt-2 text-xs font-bold text-slate-200">前月比 {{ $dashboard['sales_trend_percent'] === null ? 'データ待ち' : (($dashboard['sales_trend_percent'] >= 0 ? '+' : '').number_format($dashboard['sales_trend_percent'], 1).'%') }}</p></article>
                        <article class="rounded-xl border border-emerald-300/20 bg-emerald-400/10 p-3"><p class="text-xs font-bold text-emerald-200">今月利益</p><p class="mt-2 text-xl font-black text-white">¥{{ number_format($currentMonth['profit']) }}</p><p class="mt-2 text-xs font-bold text-slate-200">前月比 {{ $dashboard['profit_trend_percent'] === null ? 'データ待ち' : (($dashboard['profit_trend_percent'] >= 0 ? '+' : '').number_format($dashboard['profit_trend_percent'], 1).'%') }}</p></article>
                        <article class="rounded-xl border border-violet-300/20 bg-violet-400/10 p-3"><p class="text-xs font-bold text-violet-200">利益率 / 平均利益</p><p class="mt-2 text-xl font-black text-white">{{ number_format($dashboard['current_month_profit_margin'], 1) }}%</p><p class="mt-2 text-xs font-bold text-slate-200">1件平均 ¥{{ number_format($dashboard['current_month_average_profit']) }}</p></article>
                        <article class="rounded-xl border border-amber-300/20 bg-amber-400/10 p-3"><p class="text-xs font-bold text-amber-200">{{ $dashboard['stale_inventory_days'] }}日以上の滞留在庫</p><p class="mt-2 text-xl font-black text-white">{{ number_format($dashboard['stale_inventory_count']) }}点</p><p class="mt-2 text-xs font-bold text-slate-200">現在在庫 {{ number_format($summary['inventory_quantity']) }}点 ・ 原価 ¥{{ number_format($summary['inventory_cost']) }}</p></article>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        @if ($dashboard['stale_inventory_count'] > 0)
                            <article class="rounded-xl border border-amber-300/30 bg-amber-400/10 p-3"><h3 class="text-sm font-black text-amber-100">長期在庫を確認してください</h3><p class="mt-2 text-xs font-semibold leading-5 text-slate-100">出品日から{{ $dashboard['stale_inventory_days'] }}日以上、在庫が残っている商品が{{ number_format($dashboard['stale_inventory_count']) }}点あります。価格や説明文の見直し候補です。</p><a href="{{ route('products.index', ['stale_days' => $dashboard['stale_inventory_days']]) }}" class="mt-3 inline-flex rounded-lg bg-white/15 px-3 py-1.5 text-xs font-black text-white hover:bg-white/25">対象商品を見る</a></article>
                        @endif
                        @if ($currentMonth['sales'] < $dashboard['monthly_target'])
                            <article class="rounded-xl border border-cyan-300/30 bg-cyan-400/10 p-3"><h3 class="text-sm font-black text-cyan-100">目標まであと¥{{ number_format($dashboard['monthly_target'] - $currentMonth['sales']) }}</h3><p class="mt-2 text-xs font-semibold leading-5 text-slate-100">出品中商品の見直しと追加登録を検討してください。</p><a href="{{ route('products.create') }}" class="mt-3 inline-flex rounded-lg bg-white/15 px-3 py-1.5 text-xs font-black text-white hover:bg-white/25">商品を登録</a></article>
                        @endif
                        @if ($dashboard['stale_inventory_count'] === 0 && $currentMonth['sales'] >= $dashboard['monthly_target'])
                            <article class="rounded-xl border border-emerald-300/30 bg-emerald-400/10 p-3"><h3 class="text-sm font-black text-emerald-100">良い状態を維持できています</h3><p class="mt-2 text-xs font-semibold leading-5 text-slate-100">売上、利益率、在庫状況が安定しています。ジャンル別分析で次の仕入れ候補を探してください。</p><a href="{{ $isPremium ? route('furimadeck-analytics.categories', ['month' => $month->format('Y-m')]) : route('furimadeck-billing.index') }}" class="mt-3 inline-flex rounded-lg bg-white/15 px-3 py-1.5 text-xs font-black text-white hover:bg-white/25">ジャンル分析</a></article>
                        @endif
                    </div>
                </article>
            </section>

            <section class="mt-8 rounded-2xl border border-lime-300/25 bg-slate-950/80 p-5 shadow-2xl backdrop-blur-md">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div><p class="text-xs font-black tracking-[0.18em] text-lime-300">PROFIT SUMMARY</p><h2 class="mt-1 text-xl font-black text-white">利益サマリー</h2><p class="mt-2 max-w-3xl text-sm font-bold leading-7 text-slate-200">今月の利益、利益率、出品中商品の見込み利益をまとめて確認できます。仕入れ・値下げ・追加出品の判断に使ってください。</p></div>
                    <a href="{{ $isPremium ? route('furimadeck-sales.index') : route('furimadeck-billing.index') }}" class="inline-flex items-center justify-center rounded-lg bg-lime-300 px-5 py-3 text-sm font-black text-slate-950 hover:bg-lime-200">売上詳細を見る</a>
                </div>
                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-lime-300/20 bg-lime-400/10 p-4"><p class="text-xs font-black text-lime-200">今月利益</p><p class="mt-2 text-2xl font-black {{ $currentMonth['profit'] < 0 ? 'text-red-300' : 'text-lime-200' }}">¥{{ number_format($currentMonth['profit']) }}</p><p class="mt-2 text-xs font-bold text-slate-200">利益率 {{ number_format($dashboard['current_month_profit_margin'], 1) }}%</p></article>
                    <article class="rounded-xl border border-emerald-300/20 bg-emerald-400/10 p-4"><p class="text-xs font-black text-emerald-200">累計利益</p><p class="mt-2 text-2xl font-black {{ $summary['profit_total'] < 0 ? 'text-red-300' : 'text-emerald-200' }}">¥{{ number_format($summary['profit_total']) }}</p><p class="mt-2 text-xs font-bold text-slate-200">累計利益率 {{ number_format($summary['profit_margin'], 1) }}%</p></article>
                    <article class="rounded-xl border border-cyan-300/20 bg-cyan-400/10 p-4"><p class="text-xs font-black text-cyan-200">出品中の見込み売上</p><p class="mt-2 text-2xl font-black text-cyan-100">¥{{ number_format($portfolio['potential_sales']) }}</p><p class="mt-2 text-xs font-bold text-slate-200">現在在庫 {{ number_format($summary['inventory_quantity']) }}点</p></article>
                    <article class="rounded-xl border border-amber-300/20 bg-amber-400/10 p-4"><p class="text-xs font-black text-amber-200">出品中の見込み利益</p><p class="mt-2 text-2xl font-black {{ $portfolio['potential_profit'] < 0 ? 'text-red-300' : 'text-amber-100' }}">¥{{ number_format($portfolio['potential_profit']) }}</p><p class="mt-2 text-xs font-bold text-slate-200">出品中 {{ number_format($portfolio['active_listing_count']) }}件</p></article>
                </div>
            </section>

            <section class="mt-8 grid grid-cols-2 gap-4 lg:grid-cols-5">
                <article class="rounded-2xl border border-cyan-300/20 bg-slate-950/75 p-5 shadow-xl backdrop-blur-md"><p class="text-sm font-bold text-slate-200">総商品数</p><p class="mt-3 text-3xl font-black text-white">{{ number_format($portfolio['product_count']) }}</p></article>
                <article class="rounded-2xl border border-cyan-300/20 bg-cyan-400/10 p-5 shadow-xl backdrop-blur-md"><p class="text-sm font-bold text-cyan-200">出品中</p><p class="mt-3 text-3xl font-black text-cyan-100">{{ number_format($portfolio['active_listing_count']) }}</p></article>
                <article class="rounded-2xl border border-violet-300/20 bg-violet-400/10 p-5 shadow-xl backdrop-blur-md"><p class="text-sm font-bold text-violet-200">売上登録件数</p><p class="mt-3 text-3xl font-black text-violet-100">{{ number_format($portfolio['sold_count']) }}</p><p class="mt-2 text-xs font-bold text-slate-300">在庫0の商品 {{ number_format($portfolio['zero_stock_product_count']) }}件</p></article>
                <article class="rounded-2xl border border-emerald-300/20 bg-emerald-400/10 p-5 shadow-xl backdrop-blur-md"><p class="text-sm font-bold text-emerald-200">累計売上</p><p class="mt-3 text-2xl font-black text-emerald-100">¥{{ number_format($summary['sales_total']) }}</p></article>
                <article class="rounded-2xl border border-orange-300/20 bg-orange-400/10 p-5 shadow-xl backdrop-blur-md"><p class="text-sm font-bold text-orange-200">累計販売手数料</p><p class="mt-3 text-2xl font-black text-orange-100">¥{{ number_format($summary['sales_fee_total']) }}</p></article>
            </section>

            <section class="mt-8 rounded-2xl border border-emerald-300/25 bg-slate-950/80 p-5 shadow-2xl backdrop-blur-md">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div><p class="text-xs font-black tracking-[0.18em] text-emerald-300">DATA PROTECTION</p><h2 class="mt-1 text-xl font-black text-white">データ保護とバックアップ</h2><p class="mt-2 max-w-3xl text-sm font-bold leading-7 text-slate-200">CSV取り込み、端末変更、データ整理の前には、商品・出品・販売のCSVを保存してください。</p></div>
                    <a href="{{ $isPremium ? route('products.imports.create') : route('furimadeck-billing.index') }}" class="inline-flex items-center justify-center rounded-lg bg-emerald-400 px-5 py-3 text-sm font-black text-slate-950 hover:bg-emerald-300">{{ $isPremium ? 'CSV管理へ' : 'Premiumの詳細を見る' }}</a>
                </div>
            </section>

            <section class="mt-8 rounded-3xl border border-slate-700 bg-slate-950/90 p-4 shadow-2xl backdrop-blur-md sm:p-6">
                <div class="text-center"><h2 class="text-base font-black text-white sm:text-xl">{{ $month->format('Y年n月') }}の利益</h2><p class="mt-1 text-xs font-bold text-cyan-100 sm:text-sm">{{ $monthlyRows->first()['period_label'] }}から{{ $monthlyRows->last()['period_label'] }}まで表示</p></div>
                <nav class="mt-4 flex flex-wrap justify-center gap-3" aria-label="月次グラフの表示月移動">
                    <a href="{{ route('furimadeck-dashboard', ['month' => $month->subMonth()->format('Y-m')]) }}" aria-label="前の月を表示" class="inline-flex items-center gap-2 rounded-xl border border-cyan-300/50 bg-cyan-900/60 px-3 py-2 text-sm font-black text-cyan-50 hover:bg-cyan-800"><span aria-hidden="true">←</span><span>前の月</span></a>
                    <a href="{{ route('furimadeck-dashboard', ['month' => $month->addMonth()->format('Y-m')]) }}" aria-label="次の月を表示" class="inline-flex items-center gap-2 rounded-xl border border-cyan-300/50 bg-cyan-900/60 px-3 py-2 text-sm font-black text-cyan-50 hover:bg-cyan-800"><span>次の月</span><span aria-hidden="true">→</span></a>
                </nav>
                <div class="mt-5 h-72 sm:h-96"><canvas id="furimadeckMonthlySalesChart" aria-label="{{ $monthlyRows->first()['period_label'] }}から{{ $monthlyRows->last()['period_label'] }}までの売上と実利益のグラフ"></canvas></div>
            </section>

            <section class="mt-8">
                <article class="rounded-2xl border border-slate-700 bg-slate-950/85 p-5 shadow-xl backdrop-blur-md"><div class="flex items-center justify-between"><h2 class="text-lg font-black text-white">最近更新した商品</h2><a href="{{ route('products.index') }}" class="text-sm font-black text-emerald-300 hover:text-emerald-200">すべて見る</a></div><div class="mt-4 grid gap-3 sm:grid-cols-2">@forelse ($recentProducts as $product)<a href="{{ route('products.edit', $product) }}" class="rounded-xl border border-white/10 bg-white/5 p-3 hover:border-cyan-300/40 hover:bg-white/10"><p class="line-clamp-2 font-black text-white">{{ $product->product_name }}</p><p class="mt-2 text-xs font-bold text-cyan-200">商品ID: {{ $product->internal_sku }}</p><p class="mt-1 text-xs font-bold text-slate-300">在庫 {{ number_format($product->quantity_available) }}点</p></a>@empty<p class="rounded-xl bg-white/5 p-5 text-sm font-bold text-slate-200">まだ商品がありません。まず商品を登録すると、売上・在庫の分析が始まります。</p>@endforelse</div></article>
            </section>

            <section class="mt-8 rounded-2xl border border-amber-300/25 bg-slate-950/80 p-5 shadow-xl backdrop-blur-md">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs font-black tracking-[0.18em] text-amber-300">SALES IMPROVEMENT</p><h2 class="mt-1 text-lg font-black text-white">今日の販売改善</h2></div><a href="{{ $isPremium ? route('furimadeck-improvement.index') : route('furimadeck-billing.index') }}" class="text-sm font-black text-amber-200 hover:text-amber-100">詳細を見る</a></div>
                <p class="mt-3 text-xs font-semibold text-slate-300">滞留在庫候補は仕入日基準、長期在庫は出品日基準で集計しています。</p><div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">@foreach (['stagnant_stock' => '滞留在庫', 'price_review' => '価格見直し', 'relist' => '再出品検討', 'marketplace_change' => '出品先変更', 'rebuy' => '再仕入れ候補'] as $key => $label)<div class="rounded-xl border border-white/10 bg-white/5 p-3"><p class="text-xs font-bold text-slate-200">{{ $label }}</p><p class="mt-2 text-2xl font-black text-white">{{ number_format(count($improvement[$key])) }}件</p></div>@endforeach</div>
            </section>
            <section class="mt-8 rounded-2xl border border-slate-700 bg-slate-950/80 p-5 shadow-xl backdrop-blur-md"><h2 class="text-lg font-black text-white">今日やること</h2><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">@foreach (['stock_check' => '在庫あり', 'awaiting_shipment' => '発送待ち', 'long_term_inventory' => '長期在庫', 'other_listing_checks' => '他サイト確認'] as $key => $label)<div class="rounded-xl bg-white/5 p-3"><p class="text-sm font-bold text-slate-200">{{ $label }}</p><p class="mt-2 text-2xl font-black text-white">{{ number_format($todayWork[$key]) }}件</p></div>@endforeach</div></section>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        const monthlySalesChart = document.getElementById('furimadeckMonthlySalesChart');
        if (monthlySalesChart && window.Chart) {
            new Chart(monthlySalesChart, {
                type: 'bar',
                data: {
                    labels: @json($monthlyRows->pluck('label')->values()),
                    datasets: [
                        { label: '売上', data: @json($salesChart), borderColor: '#38bdf8', backgroundColor: 'rgba(56, 189, 248, 0.48)', borderWidth: 1 },
                        { label: '実利益', data: @json($profitChart), borderColor: '#fb7185', backgroundColor: 'rgba(251, 113, 133, 0.52)', borderWidth: 1 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { labels: { color: '#f8fafc', font: { weight: 'bold' } } }, tooltip: { callbacks: { label: context => `${context.dataset.label}: ¥${Number(context.raw).toLocaleString()}` } } },
                    scales: { x: { ticks: { color: '#e2e8f0', font: { weight: 'bold' } }, grid: { color: 'rgba(226, 232, 240, 0.14)' } }, y: { beginAtZero: true, ticks: { color: '#e2e8f0', callback: value => `¥${Number(value).toLocaleString()}` }, grid: { color: 'rgba(226, 232, 240, 0.14)' } } },
                },
            });
        }
    </script>
</x-app-layout>
