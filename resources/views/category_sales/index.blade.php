<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-white">ジャンル別売上</h2>
                <p class="mt-1 text-sm text-slate-300">大ジャンル・小ジャンル・出品先の組み合わせから売上と実利益を分析します。</p>
            </div>
            <a href="{{ route('sales.index') }}" class="inline-flex items-center justify-center rounded-lg border border-cyan-300/30 bg-cyan-500/20 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-400/25">
                売上管理へ戻る
            </a>
        </div>
    </x-slot>

    <div class="py-8 text-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/45 p-6 shadow-sm backdrop-blur-md">
                <form method="GET" action="{{ route('category-sales.index') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="w-full rounded-2xl bg-white p-3 sm:max-w-xs">
                        <label for="month" class="block text-sm font-black text-black">集計月</label>
                        <input id="month" type="month" name="month" value="{{ $month }}" class="mt-2 h-11 w-full rounded-xl border-slate-300 bg-white text-black shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                        <p class="mt-2 text-xs font-bold text-black">入力例: 2026年7月なら「2026-07」を選択または入力してください。</p>
                    </div>
                    <button type="submit" class="h-11 rounded-xl bg-cyan-500 px-5 text-sm font-black text-slate-950 hover:bg-cyan-400">この月を表示</button>
                    <a href="{{ route('category-sales.index') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-cyan-300/30 px-5 text-sm font-black text-white hover:bg-cyan-400/10">全期間</a>
                </form>
                <p class="mt-3 text-sm text-slate-300">表示期間: {{ $selectedMonth ? $selectedMonth->format('Y年n月') : '全期間' }}</p>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/45 p-5"><p class="text-sm text-slate-300">売上合計</p><p class="mt-2 text-2xl font-black text-white">¥{{ number_format($summary['sales']) }}</p></div>
                <div class="rounded-2xl border border-lime-300/30 bg-slate-950/45 p-5"><p class="text-sm text-slate-300">実利益合計</p><p class="mt-2 text-2xl font-black {{ $summary['profit'] < 0 ? 'text-red-400' : 'text-lime-300' }}">¥{{ number_format($summary['profit']) }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/45 p-5"><p class="text-sm text-slate-300">SOLD件数</p><p class="mt-2 text-2xl font-black text-white">{{ number_format($summary['count']) }}件</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/45 p-5"><p class="text-sm text-slate-300">利益率</p><p class="mt-2 text-2xl font-black text-white">{{ number_format($summary['profit_rate'], 1) }}%</p></div>
            </div>

            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/45 p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-white">大ジャンル別 売上・実利益</h3>
                    <div class="mt-6 h-80"><canvas id="categorySalesChart"></canvas></div>
                </div>
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/45 p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-white">大ジャンル別 販売数</h3>
                    <div class="mt-6 h-80"><canvas id="categoryCountChart"></canvas></div>
                </div>
            </div>

            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                @include('sales.partials.category-sales-table', [
                    'title' => '大ジャンル別 売上集計',
                    'description' => '表示期間内のSOLD商品を大ジャンルごとに集計しています。',
                    'rows' => $parentCategorySales,
                ])
                @include('sales.partials.category-sales-table', [
                    'title' => '小ジャンル別 売上集計',
                    'description' => '表示期間内のSOLD商品を大ジャンル / 小ジャンルごとに集計しています。',
                    'rows' => $childCategorySales,
                ])
            </div>

            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                @include('sales.partials.ranking-table', [
                    'title' => '大ジャンル 売上ランキング',
                    'description' => '表示期間内で売上が高い大ジャンル上位10件です。',
                    'labelHeading' => '大ジャンル',
                    'rows' => $parentCategoryRanking,
                ])
                @include('sales.partials.ranking-table', [
                    'title' => '小ジャンル 売上ランキング',
                    'description' => '表示期間内で売上が高い小ジャンル上位10件です。',
                    'labelHeading' => '大ジャンル / 小ジャンル',
                    'rows' => $childCategoryRanking,
                ])
            </div>

            <div class="mt-8">
                @include('sales.partials.category-platform-cross-table', [
                    'rows' => $categoryPlatformCrossSales,
                    'platformNames' => $platformNames,
                ])
            </div>
        </div>
    </div>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" integrity="sha384-vsrfeLOOY6KuIYKDlmVH5UiBmgIdB1oEf7p01YgWHuqmOHfZr374+odEv96n9tNC" crossorigin="anonymous"></script>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        const categoryLabels = @json($chartLabels);
        const categorySalesData = @json($chartSalesData);
        const categoryProfitData = @json($chartProfitData);
        const categoryCountData = @json($chartCountData);
        const chartTextColor = '#f8fafc';
        const chartGridColor = 'rgba(148, 163, 184, 0.22)';
        const yen = function(value) { return '¥' + Number(value).toLocaleString(); };
        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { color: chartTextColor } } },
            scales: {
                x: { ticks: { color: chartTextColor }, grid: { color: chartGridColor } },
                y: { beginAtZero: true, ticks: { color: chartTextColor }, grid: { color: chartGridColor } }
            }
        };

        const salesCanvas = document.getElementById('categorySalesChart');
        const countCanvas = document.getElementById('categoryCountChart');

        if (salesCanvas) {
            new Chart(salesCanvas, {
                type: 'bar',
                data: {
                    labels: categoryLabels,
                    datasets: [
                        { label: '売上', data: categorySalesData, backgroundColor: 'rgba(34, 211, 238, 0.65)' },
                        { label: '実利益', data: categoryProfitData, backgroundColor: 'rgba(163, 230, 53, 0.65)' }
                    ]
                },
                options: {
                    ...commonOptions,
                    plugins: { ...commonOptions.plugins, tooltip: { callbacks: { label: (context) => context.dataset.label + ': ' + yen(context.raw) } } },
                    scales: { ...commonOptions.scales, y: { ...commonOptions.scales.y, ticks: { color: chartTextColor, callback: yen } } }
                }
            });
        }

        if (countCanvas) {
            new Chart(countCanvas, {
                type: 'bar',
                data: { labels: categoryLabels, datasets: [{ label: '販売数', data: categoryCountData, backgroundColor: 'rgba(96, 165, 250, 0.7)' }] },
                options: {
                    ...commonOptions,
                    plugins: { ...commonOptions.plugins, tooltip: { callbacks: { label: (context) => Number(context.raw).toLocaleString() + '件' } } },
                    scales: { ...commonOptions.scales, y: { ...commonOptions.scales.y, ticks: { color: chartTextColor, precision: 0, callback: (value) => Number(value).toLocaleString() + '件' } } }
                }
            });
        }
    </script>
</x-app-layout>
