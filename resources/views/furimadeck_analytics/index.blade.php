<x-app-layout>
    @php
        $monthlyRows = $report['monthly_rows'];
        $marketplaceRows = $report['marketplace_rows']->take(8)->values();
        $categoryRows = $report['category_rows']->take(8)->values();
    @endphp

    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-cyan-200">売上分析</h2>
                <p class="mt-1 text-sm font-bold text-cyan-100">月別推移、販売先・ジャンルの内訳を確認します。</p>
            </div>
            <a href="{{ route('furimadeck-analytics.categories', ['month' => $month->format('Y-m')]) }}" class="rounded-lg border border-cyan-300 bg-cyan-50 px-4 py-2 text-sm font-black text-cyan-900 hover:bg-cyan-100">ジャンル別分析へ</a>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <form method="GET" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm">
                <label class="text-sm font-bold text-slate-700">集計月<input type="month" name="month" value="{{ $month->format('Y-m') }}" class="mt-1 block rounded-lg border-slate-300 text-slate-900"></label>
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-black text-white hover:bg-slate-700">表示</button>
                <a href="{{ route('furimadeck-dashboard', ['month' => $month->format('Y-m')]) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-black text-slate-700 hover:bg-slate-50">ダッシュボードへ</a>
            </form>

            @include('furimadeck_analytics.partials.summary', ['summary' => $report['summary']])            @if (! $report['summary']['sales_data_available'])
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm">
                    <h3 class="font-black text-amber-950">売上分析に使える販売データがありません</h3>
                    <p class="mt-2 text-sm font-bold leading-6 text-amber-900">現在の登録内容は商品・在庫CSVのみです。売上金額、売却日、販売手数料、送料がないため、売上・利益・販売先・ジャンル別の金額は計算していません。商品CSVの在庫集計は下に正確に表示しています。</p>
                </section>
            @endif
            <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-black text-slate-900">在庫分析</h3>
                        <p class="mt-1 text-sm font-bold text-slate-700">今持っている在庫の量と金額を確認し、仕入れや販売の判断に使うデータです。</p>
                    </div>
                    <a href="{{ route('furimadeck-analytics.advanced', ['from' => $month->startOfMonth()->format('Y-m-d'), 'to' => $month->endOfMonth()->format('Y-m-d')]) }}" class="rounded-lg border border-emerald-300 bg-white px-4 py-2 text-sm font-black text-emerald-900 hover:bg-emerald-100">高度分析で詳しく見る</a>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="rounded-xl border border-emerald-100 bg-white p-4"><p class="text-xs font-black text-slate-500">在庫商品数</p><p class="mt-1 text-2xl font-black text-slate-900">{{ number_format($report['inventory']['product_count']) }}件</p><p class="mt-2 text-xs leading-5 text-slate-500">在庫を持っている商品が何種類あるかを見るためのデータです。</p></div>
                    <div class="rounded-xl border border-emerald-100 bg-white p-4"><p class="text-xs font-black text-slate-500">在庫数量</p><p class="mt-1 text-2xl font-black text-slate-900">{{ number_format($report['inventory']['stock_units']) }}点</p><p class="mt-2 text-xs leading-5 text-slate-500">これから販売できる商品が何点あるかを見るためのデータです。</p></div>
                    <div class="rounded-xl border border-emerald-100 bg-white p-4"><p class="text-xs font-black text-slate-500">在庫仕入額</p><p class="mt-1 text-2xl font-black text-slate-900">¥{{ number_format($report['inventory']['inventory_capital']) }}</p><p class="mt-2 text-xs leading-5 text-slate-500">在庫として眠っている仕入れ金額を確認するためのデータです。</p></div>
                    <div class="rounded-xl border border-emerald-100 bg-white p-4"><p class="text-xs font-black text-slate-500">出品中</p><p class="mt-1 text-2xl font-black text-slate-900">{{ number_format($report['inventory']['listed_count']) }}件</p><p class="mt-2 text-xs leading-5 text-slate-500">現在フリマサイトに出品している商品数を確認するためのデータです。</p></div>
                    <div class="rounded-xl border border-emerald-100 bg-white p-4"><p class="text-xs font-black text-slate-500">仕入日未設定</p><p class="mt-1 text-2xl font-black text-slate-900">{{ number_format($report['inventory']['purchase_date_unset']) }}件</p><p class="mt-2 text-xs leading-5 text-slate-500">在庫の滞留期間を判断できない商品数を確認するためのデータです。</p></div>
                </div>
                @if ($report['summary']['sale_count'] === 0)
                    <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-900">販売データが登録されていないため、売上分析は計算対象がありません。登録済み商品の在庫は上の数値に反映されています。</p>
                @endif
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <div><p class="text-xs font-black tracking-[0.18em] text-cyan-700">YEARLY TREND</p><h3 class="mt-1 text-xl font-black text-slate-900">{{ $month->year }}年 月別売上・実利益</h3><p class="mt-1 text-sm text-slate-600">月ごとの売れ行きと利益の変化を見て、販売状況を判断するためのグラフです。</p></div>
                    <span class="text-xs font-bold text-slate-500">売上 / 実利益 / 販売件数</span>
                </div>
                <div class="mt-5 h-80"><canvas id="furimadeckAnalyticsTrendChart" aria-label="月別の売上・実利益推移グラフ"></canvas></div>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><p class="text-xs font-black tracking-[0.18em] text-cyan-700">MARKETPLACE MIX</p><h3 class="mt-1 text-xl font-black text-slate-900">販売先別 売上構成</h3><p class="mt-1 text-sm text-slate-600">どのフリマサイトで売上が多いかを比較するためのグラフです。</p></div>
                    <div class="mt-5 h-72"><canvas id="furimadeckMarketplaceChart" aria-label="販売先別売上グラフ"></canvas></div>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><p class="text-xs font-black tracking-[0.18em] text-cyan-700">CATEGORY MIX</p><h3 class="mt-1 text-xl font-black text-slate-900">商品ジャンル別 売上構成</h3><p class="mt-1 text-sm text-slate-600">どのジャンルの商品が売上につながっているかを比較するためのグラフです。</p></div>
                    <div class="mt-5 h-72"><canvas id="furimadeckSalesCategoryChart" aria-label="商品ジャンル別売上グラフ"></canvas></div>
                </article>
            </section>

            <section><h3 class="mb-3 text-lg font-black text-slate-900">販売先別 明細</h3>@include('furimadeck_analytics.partials.table', ['rows' => $report['marketplace_rows'], 'label' => '販売先'])</section>
            <section><h3 class="mb-3 text-lg font-black text-slate-900">商品ジャンル別 明細</h3>@include('furimadeck_analytics.partials.table', ['rows' => $report['category_rows'], 'label' => 'ジャンル'])</section>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        const yen = value => `¥${Number(value).toLocaleString()}`;
        const renderChart = (elementId, config) => {
            const canvas = document.getElementById(elementId);
            if (canvas && window.Chart) new Chart(canvas, config);
        };

        renderChart('furimadeckAnalyticsTrendChart', {
            type: 'line',
            data: {
                labels: @json($monthlyRows->pluck('label')->values()),
                datasets: [
                    { label: '売上', data: @json($monthlyRows->pluck('sales')->values()), borderColor: '#0891b2', backgroundColor: 'rgba(6,182,212,.12)', fill: true, tension: .32, borderWidth: 3 },
                    { label: '実利益', data: @json($monthlyRows->pluck('profit')->values()), borderColor: '#059669', backgroundColor: 'rgba(16,185,129,.10)', fill: true, tension: .32, borderWidth: 3 },
                ],
            },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { tooltip: { callbacks: { label: context => `${context.dataset.label}: ${yen(context.raw)}` } } }, scales: { y: { beginAtZero: true, ticks: { callback: yen } } } },
        });

        renderChart('furimadeckMarketplaceChart', {
            type: 'bar',
            data: { labels: @json($marketplaceRows->pluck('name')->values()), datasets: [{ label: '売上', data: @json($marketplaceRows->pluck('sales_total')->values()), backgroundColor: '#0891b2', borderRadius: 6 }] },
            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => yen(context.raw) } } }, scales: { x: { beginAtZero: true, ticks: { callback: yen } } } },
        });

        renderChart('furimadeckSalesCategoryChart', {
            type: 'doughnut',
            data: { labels: @json($categoryRows->pluck('name')->values()), datasets: [{ data: @json($categoryRows->pluck('sales_total')->values()), backgroundColor: ['#0891b2', '#059669', '#7c3aed', '#ea580c', '#db2777', '#4f46e5', '#65a30d', '#ca8a04'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: context => `${context.label}: ${yen(context.raw)}` } }, legend: { position: 'bottom' } } },
        });
    </script>
</x-app-layout>
