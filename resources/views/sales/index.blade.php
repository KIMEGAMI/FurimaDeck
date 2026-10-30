<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-cyan-200">月別利益レポート</h2>
                <p class="mt-1 text-sm font-bold text-cyan-100">売上、仕入れ、手数料、送料、実利益、利益率を月別に確認できます。</p>
            </div>
            <a href="{{ route('auction-items.index') }}" class="inline-flex items-center justify-center rounded-xl border border-cyan-300/30 bg-white/10 px-4 py-3 text-sm font-black text-cyan-100 shadow-sm hover:bg-white/20">
                商品一覧へ戻る
            </a>
        </div>
    </x-slot>

    <div class="py-8 text-white">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-5"><p class="text-sm font-bold text-cyan-100">累計売上</p><p class="mt-2 text-2xl font-black text-white">¥{{ number_format($totalSales) }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-5"><p class="text-sm font-bold text-cyan-100">累計仕入れ</p><p class="mt-2 text-2xl font-black text-white">¥{{ number_format($totalPurchase) }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-5"><p class="text-sm font-bold text-cyan-100">手数料・送料</p><p class="mt-2 text-2xl font-black text-white">¥{{ number_format($totalSalesFee + $totalShippingFee) }}</p></div>
                <div class="rounded-2xl border border-lime-300/30 bg-slate-950/55 p-5"><p class="text-sm font-bold text-cyan-100">累計実利益</p><p class="mt-2 text-2xl font-black {{ $totalProfit < 0 ? 'text-red-300' : 'text-lime-300' }}">¥{{ number_format($totalProfit) }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-5"><p class="text-sm font-bold text-cyan-100">SOLD / 出品中</p><p class="mt-2 text-2xl font-black text-white">{{ number_format($soldCount) }} / {{ number_format($sellingCount) }}</p></div>
            </section>

            <section id="monthly-pdf-report" class="mt-8 rounded-2xl border border-cyan-300/20 bg-white p-6 text-slate-950 shadow-sm">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-black tracking-widest text-cyan-700">PDF REPORT</p>
                        <h3 class="mt-2 text-2xl font-black">{{ $monthlyReport['title'] }}</h3>
                        <p class="mt-2 text-sm font-bold text-slate-600">印刷画面からPDF保存できます。月次報告、振り返り、仕入れ判断用の控えとして使えます。</p>
                    </div>
                    <button type="button" data-action="print" class="no-print inline-flex items-center justify-center rounded-xl bg-slate-900 px-5 py-3 text-sm font-black text-white shadow hover:bg-slate-800">
                        PDF保存 / 印刷
                    </button>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-4">
                    <div class="rounded-xl bg-slate-100 p-4"><p class="text-xs font-black text-slate-600">選択月売上</p><p class="mt-1 text-xl font-black">¥{{ number_format($selectedMonthRow['sales']) }}</p></div>
                    <div class="rounded-xl bg-slate-100 p-4"><p class="text-xs font-black text-slate-600">選択月利益</p><p class="mt-1 text-xl font-black">¥{{ number_format($selectedMonthRow['profit']) }}</p></div>
                    <div class="rounded-xl bg-slate-100 p-4"><p class="text-xs font-black text-slate-600">利益率</p><p class="mt-1 text-xl font-black">{{ number_format($selectedMonthRow['profit_rate'], 1) }}%</p></div>
                    <div class="rounded-xl bg-slate-100 p-4"><p class="text-xs font-black text-slate-600">販売件数</p><p class="mt-1 text-xl font-black">{{ number_format($selectedMonthRow['count']) }}件</p></div>
                </div>

                <div class="mt-5 grid gap-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <h4 class="text-sm font-black text-slate-900">前月比較</h4>
                        <dl class="mt-3 space-y-2 text-sm font-bold text-slate-700">
                            <div class="flex justify-between gap-3"><dt>売上差</dt><dd>¥{{ number_format($monthlyReport['sales_diff']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>利益差</dt><dd>¥{{ number_format($monthlyReport['profit_diff']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt>SOLD件数差</dt><dd>{{ number_format($monthlyReport['count_diff']) }}件</dd></div>
                        </dl>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4">
                        <h4 class="text-sm font-black text-slate-900">次月アクション</h4>
                        <ul class="mt-3 space-y-2 text-sm font-bold leading-6 text-slate-700">
                            @foreach ($monthlyReport['actions'] as $action)
                                <li>・{{ $action }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>

                @if ($monthlyReport['best_platform'])
                    <p class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm font-bold text-emerald-900">
                        今月の注力候補: {{ $monthlyReport['best_platform']['platform'] }} / 利益 ¥{{ number_format($monthlyReport['best_platform']['profit']) }} / 利益率 {{ number_format($monthlyReport['best_platform']['profit_rate'], 1) }}%
                    </p>
                @endif
            </section>

            <section class="mt-8 grid gap-6 lg:grid-cols-[1.25fr_0.75fr]">
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-6 shadow-sm backdrop-blur-md">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <a href="{{ route('sales.index', ['month' => $previousMonth]) }}" class="inline-flex items-center justify-center rounded-xl border border-cyan-300/30 bg-cyan-500/20 px-4 py-2 text-sm font-black text-white hover:bg-cyan-400/25">前の月</a>
                        <div class="text-center">
                            <h3 class="text-lg font-black text-white">{{ $baseMonth->format('Y年n月') }}の利益</h3>
                            <p class="mt-1 text-sm font-bold text-cyan-100">{{ $periodStart->format('Y年n月') }}から{{ $periodEnd->format('Y年n月') }}まで表示</p>
                        </div>
                        <a href="{{ route('sales.index', ['month' => $nextMonth]) }}" class="inline-flex items-center justify-center rounded-xl border border-cyan-300/30 bg-cyan-500/25 px-4 py-2 text-sm font-black text-white hover:bg-cyan-400/30">次の月</a>
                    </div>
                    <div class="mt-6 h-80"><canvas id="monthlySalesChart"></canvas></div>
                </div>

                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-6 shadow-sm backdrop-blur-md">
                    <h3 class="text-lg font-black text-white">月別サマリー</h3>
                    <div class="mt-5 space-y-3">
                        <div class="rounded-xl bg-white/10 p-4">
                            <p class="text-sm font-bold text-cyan-100">選択月の実利益</p>
                            <p class="mt-1 text-3xl font-black {{ $selectedMonthRow['profit'] < 0 ? 'text-red-300' : 'text-lime-300' }}">¥{{ number_format($selectedMonthRow['profit']) }}</p>
                            <p class="mt-1 text-xs font-bold text-cyan-100">利益率 {{ number_format($selectedMonthRow['profit_rate'], 1) }}% / 平均利益 ¥{{ number_format($selectedMonthRow['average_profit']) }}</p>
                        </div>
                        <div class="rounded-xl bg-white/10 p-4">
                            <p class="text-sm font-bold text-cyan-100">前月との差</p>
                            <p class="mt-1 text-xl font-black {{ $monthlyInsights['profit_diff'] < 0 ? 'text-red-300' : 'text-lime-300' }}">¥{{ number_format($monthlyInsights['profit_diff']) }}</p>
                            <p class="mt-1 text-xs font-bold text-cyan-100">売上差 ¥{{ number_format($monthlyInsights['sales_diff']) }} / SOLD差 {{ number_format($monthlyInsights['count_diff']) }}件</p>
                        </div>
                        <div class="rounded-xl border border-cyan-300/20 bg-cyan-400/10 p-4 text-sm font-bold leading-6 text-cyan-50">
                            {{ $monthlyInsights['message'] }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-8 grid gap-6 lg:grid-cols-2">
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-6 shadow-sm backdrop-blur-md">
                    <h3 class="text-lg font-black text-white">月別利益一覧</h3>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-cyan-300/20 text-sm">
                            <thead class="bg-cyan-400/10">
                                <tr>
                                    <th class="px-4 py-3 text-left font-black text-cyan-100">月</th>
                                    <th class="px-4 py-3 text-right font-black text-cyan-100">SOLD</th>
                                    <th class="px-4 py-3 text-right font-black text-cyan-100">売上</th>
                                    <th class="px-4 py-3 text-right font-black text-cyan-100">仕入れ</th>
                                    <th class="px-4 py-3 text-right font-black text-cyan-100">実利益</th>
                                    <th class="px-4 py-3 text-right font-black text-cyan-100">利益率</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cyan-300/15 bg-slate-950/20">
                                @foreach ($monthlySales as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-bold text-white">{{ $row['month'] }}</td>
                                        <td class="px-4 py-3 text-right text-cyan-100">{{ number_format($row['count']) }}件</td>
                                        <td class="px-4 py-3 text-right text-cyan-100">¥{{ number_format($row['sales']) }}</td>
                                        <td class="px-4 py-3 text-right text-cyan-100">¥{{ number_format($row['purchase']) }}</td>
                                        <td class="px-4 py-3 text-right font-black {{ $row['profit'] < 0 ? 'text-red-300' : 'text-lime-300' }}">¥{{ number_format($row['profit']) }}</td>
                                        <td class="px-4 py-3 text-right text-cyan-100">{{ number_format($row['profit_rate'], 1) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-6 shadow-sm backdrop-blur-md">
                    <h3 class="text-lg font-black text-white">出品先別売上</h3>
                    <div class="mt-6 h-80"><canvas id="platformSalesChart"></canvas></div>
                    <div class="mt-5 space-y-2">
                        @foreach ($platformChartBreakdown as $row)
                            <div class="grid grid-cols-[1fr_auto_auto] items-center gap-3 rounded-xl bg-white/10 px-4 py-3 text-sm">
                                <span class="font-black text-white">{{ $row['platform'] }}</span>
                                <span class="font-bold text-cyan-100">¥{{ number_format($row['sales']) }}</span>
                                <span class="rounded-full bg-cyan-300 px-3 py-1 text-xs font-black text-slate-950">{{ number_format($row['share'], 1) }}%</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <div class="mt-8">
                @include('sales.partials.ranking-table', [
                    'title' => '出品先 売上ランキング',
                    'description' => '売上が高い出品先を確認できます。',
                    'labelHeading' => '出品先',
                    'rows' => $platformRanking,
                ])
            </div>
        </div>
    </div>

    <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        @media print {
            body {
                background: #ffffff !important;
            }

            body * {
                visibility: hidden;
            }

            #monthly-pdf-report,
            #monthly-pdf-report * {
                visibility: visible;
            }

            #monthly-pdf-report {
                position: absolute;
                inset: 0 auto auto 0;
                width: 100%;
                border: 0 !important;
                box-shadow: none !important;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" integrity="sha384-vsrfeLOOY6KuIYKDlmVH5UiBmgIdB1oEf7p01YgWHuqmOHfZr374+odEv96n9tNC" crossorigin="anonymous"></script>
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        const monthlyLabels = @json($monthlyChartLabels);
        const monthlySales = @json($monthlyChartSales);
        const monthlyProfit = @json($monthlyChartProfit);
        const platformLabels = @json($platformChartLabels);
        const platformSales = @json($platformChartSales);
        const monthlyCanvas = document.getElementById('monthlySalesChart');
        const platformCanvas = document.getElementById('platformSalesChart');
        const chartTextColor = '#f8fafc';
        const chartGridColor = 'rgba(148, 163, 184, 0.22)';
        const yen = function(value) { return '¥' + Number(value).toLocaleString(); };
        const percentage = function(value, data) {
            const total = data.reduce((sum, current) => sum + Number(current), 0);
            return total <= 0 ? '0.0' : ((Number(value) / total) * 100).toFixed(1);
        };

        if (monthlyCanvas) {
            new Chart(monthlyCanvas, {
                type: 'bar',
                data: {
                    labels: monthlyLabels,
                    datasets: [
                        { label: '売上', data: monthlySales, borderWidth: 1 },
                        { label: '実利益', data: monthlyProfit, borderWidth: 1 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { labels: { color: chartTextColor } }, tooltip: { callbacks: { label: (context) => context.dataset.label + ': ' + yen(context.raw) } } },
                    scales: {
                        x: { ticks: { color: chartTextColor }, grid: { color: chartGridColor } },
                        y: { beginAtZero: true, ticks: { color: chartTextColor, callback: yen }, grid: { color: chartGridColor } }
                    }
                }
            });
        }

        if (platformCanvas) {
            new Chart(platformCanvas, {
                type: 'doughnut',
                data: { labels: platformLabels, datasets: [{ label: '売上', data: platformSales, borderWidth: 1 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: chartTextColor } },
                        tooltip: { callbacks: { label: (context) => context.label + ': ' + yen(context.raw) + ' (' + percentage(context.raw, context.dataset.data) + '%)' } }
                    }
                }
            });
        }
    </script>
</x-app-layout>
