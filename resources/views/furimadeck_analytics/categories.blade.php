<x-app-layout>
    @php
        $categoryRankingLimit = (int) config('furimadeck.analytics.category_ranking_limit');
        $rootRows = $report['root_rows']->take($categoryRankingLimit)->values();
        $middleRows = $report['middle_rows']->take($categoryRankingLimit)->values();
        $smallRows = $report['small_rows']->take($categoryRankingLimit)->values();
        $chartLabels = static fn ($rows) => $rows
            ->pluck('name')
            ->map(static fn (string $name): string => str($name)->afterLast(' / ')->toString())
            ->values();
        $rootChartLabels = $chartLabels($rootRows);
        $middleChartLabels = $chartLabels($middleRows);
        $smallChartLabels = $chartLabels($smallRows);
    @endphp

    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-cyan-200">ジャンル別分析</h2>
                <p class="mt-1 text-sm font-bold text-cyan-100">大分類・中分類・小分類ごとに、売上上位{{ $categoryRankingLimit }}ジャンルを売上・実利益・販売数で比較します。</p>
            </div>
            <a href="{{ route('furimadeck-analytics.index', ['month' => $month->format('Y-m')]) }}" class="rounded-lg border border-cyan-300 bg-cyan-50 px-4 py-2 text-sm font-black text-cyan-900 hover:bg-cyan-100">売上分析へ</a>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <form method="GET" class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm">
                <label class="text-sm font-bold text-slate-700">集計月<input type="month" name="month" value="{{ $month->format('Y-m') }}" class="mt-1 block rounded-lg border-slate-300 text-slate-900"></label>
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-black text-white hover:bg-slate-700">表示</button>
                <a href="{{ route('furimadeck-dashboard', ['month' => $month->format('Y-m')]) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-black text-slate-700 hover:bg-slate-50">ダッシュボードへ</a>
            </form>

            @include('furimadeck_analytics.partials.summary', ['summary' => $report['summary']])            @if ($report['summary']['sale_count'] === 0)
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 shadow-sm">
                    <h3 class="font-black text-amber-950">販売データがないため、ジャンル別売上は計算できません</h3>
                    <p class="mt-2 text-sm font-bold text-amber-900">商品CSVのカテゴリ情報だけでは売上金額・利益・販売数を確定できません。売上CSVを取り込むと反映されます。</p>
                </section>
            @endif

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><p class="text-xs font-black tracking-[0.18em] text-cyan-700">LARGE CATEGORY</p><h3 class="mt-1 text-xl font-black text-slate-900">大ジャンル別 売上・実利益（上位{{ $categoryRankingLimit }}件）</h3></div>
                    <div class="mt-5 h-80"><canvas id="furimadeckRootCategoryChart" aria-label="大分類別の売上と実利益グラフ"></canvas></div>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><p class="text-xs font-black tracking-[0.18em] text-cyan-700">LARGE CATEGORY</p><h3 class="mt-1 text-xl font-black text-slate-900">大ジャンル別 販売数（上位{{ $categoryRankingLimit }}件）</h3></div>
                    <div class="mt-5 h-80"><canvas id="furimadeckRootCategoryQuantityChart" aria-label="大分類別の販売数グラフ"></canvas></div>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><p class="text-xs font-black tracking-[0.18em] text-violet-700">MIDDLE CATEGORY</p><h3 class="mt-1 text-xl font-black text-slate-900">中ジャンル別 売上・実利益（上位{{ $categoryRankingLimit }}件）</h3></div>
                    <div class="mt-5 h-80"><canvas id="furimadeckMiddleCategoryChart" aria-label="中分類別の売上と実利益グラフ"></canvas></div>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><p class="text-xs font-black tracking-[0.18em] text-violet-700">MIDDLE CATEGORY</p><h3 class="mt-1 text-xl font-black text-slate-900">中ジャンル別 販売数（上位{{ $categoryRankingLimit }}件）</h3></div>
                    <div class="mt-5 h-80"><canvas id="furimadeckMiddleCategoryQuantityChart" aria-label="中分類別の販売数グラフ"></canvas></div>
                </article>
            </section>

            <section class="grid gap-6 xl:grid-cols-2">
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><p class="text-xs font-black tracking-[0.18em] text-amber-700">SMALL CATEGORY</p><h3 class="mt-1 text-xl font-black text-slate-900">小ジャンル別 売上・実利益（上位{{ $categoryRankingLimit }}件）</h3></div>
                    <div class="mt-5 h-80"><canvas id="furimadeckSmallCategoryChart" aria-label="小分類別の売上と実利益グラフ"></canvas></div>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div><p class="text-xs font-black tracking-[0.18em] text-amber-700">SMALL CATEGORY</p><h3 class="mt-1 text-xl font-black text-slate-900">小ジャンル別 販売数（上位{{ $categoryRankingLimit }}件）</h3></div>
                    <div class="mt-5 h-80"><canvas id="furimadeckSmallCategoryQuantityChart" aria-label="小分類別の販売数グラフ"></canvas></div>
                </article>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="mb-3 text-lg font-black text-slate-900">大分類別 明細（売上上位{{ $categoryRankingLimit }}件）</h3>@include('furimadeck_analytics.partials.table', ['rows' => $rootRows, 'label' => '大分類'])</section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="mb-3 text-lg font-black text-slate-900">中分類別 明細（売上上位{{ $categoryRankingLimit }}件）</h3>@include('furimadeck_analytics.partials.table', ['rows' => $middleRows, 'label' => '中分類'])</section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="mb-3 text-lg font-black text-slate-900">小分類別 明細（売上上位{{ $categoryRankingLimit }}件）</h3>@include('furimadeck_analytics.partials.table', ['rows' => $smallRows, 'label' => '小分類'])</section>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script>
        const yen = value => `¥${Number(value).toLocaleString()}`;
        const renderCategoryChart = (elementId, config) => {
            const canvas = document.getElementById(elementId);
            if (canvas && window.Chart) new Chart(canvas, config);
        };
        const currencyOptions = { responsive: true, maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: context => `${context.dataset.label}: ${yen(context.raw)}` } } }, scales: { x: { ticks: { maxRotation: 45, minRotation: 45 } }, y: { beginAtZero: true, ticks: { callback: yen } } } };
        const quantityOptions = { responsive: true, maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: context => `${context.dataset.label}: ${Number(context.raw).toLocaleString()}件` } } }, scales: { x: { ticks: { maxRotation: 45, minRotation: 45 } }, y: { beginAtZero: true, ticks: { callback: value => `${Number(value).toLocaleString()}件` } } } };

        renderCategoryChart('furimadeckRootCategoryChart', {
            type: 'bar',
            data: { labels: @json($rootChartLabels), datasets: [
                { label: '売上', data: @json($rootRows->pluck('sales_total')->values()), backgroundColor: '#0891b2', borderRadius: 6 },
                { label: '実利益', data: @json($rootRows->pluck('profit_total')->values()), backgroundColor: '#059669', borderRadius: 6 },
            ] },
            options: currencyOptions,
        });

        renderCategoryChart('furimadeckRootCategoryQuantityChart', {
            type: 'bar',
            data: { labels: @json($rootChartLabels), datasets: [{ label: '販売数', data: @json($rootRows->pluck('quantity_total')->values()), backgroundColor: '#2563eb', borderRadius: 6 }] },
            options: quantityOptions,
        });

        renderCategoryChart('furimadeckMiddleCategoryChart', {
            type: 'bar',
            data: { labels: @json($middleChartLabels), datasets: [
                { label: '売上', data: @json($middleRows->pluck('sales_total')->values()), backgroundColor: '#7c3aed', borderRadius: 6 },
                { label: '実利益', data: @json($middleRows->pluck('profit_total')->values()), backgroundColor: '#a78bfa', borderRadius: 6 },
            ] },
            options: currencyOptions,
        });

        renderCategoryChart('furimadeckMiddleCategoryQuantityChart', {
            type: 'bar',
            data: { labels: @json($middleChartLabels), datasets: [{ label: '販売数', data: @json($middleRows->pluck('quantity_total')->values()), backgroundColor: '#7c3aed', borderRadius: 6 }] },
            options: quantityOptions,
        });

        renderCategoryChart('furimadeckSmallCategoryChart', {
            type: 'bar',
            data: { labels: @json($smallChartLabels), datasets: [
                { label: '売上', data: @json($smallRows->pluck('sales_total')->values()), backgroundColor: '#ea580c', borderRadius: 6 },
                { label: '実利益', data: @json($smallRows->pluck('profit_total')->values()), backgroundColor: '#fdba74', borderRadius: 6 },
            ] },
            options: currencyOptions,
        });

        renderCategoryChart('furimadeckSmallCategoryQuantityChart', {
            type: 'bar',
            data: { labels: @json($smallChartLabels), datasets: [{ label: '販売数', data: @json($smallRows->pluck('quantity_total')->values()), backgroundColor: '#d97706', borderRadius: 6 }] },
            options: quantityOptions,
        });
    </script>
</x-app-layout>
