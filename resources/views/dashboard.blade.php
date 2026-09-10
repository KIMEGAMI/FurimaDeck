<x-app-layout>
    @php
        $displayTotalItems = ($sellingCount ?? 0) + ($soldCount ?? 0) + ($draftCount ?? 0);
        $monthlyChartLabels = collect($monthlyStats ?? [])->pluck('label')->values();
        $monthlyChartSales = collect($monthlyStats ?? [])->pluck('sales')->values();
        $monthlyChartProfit = collect($monthlyStats ?? [])->pluck('profit')->values();
        $displayYear = $year ?? now()->year;
        $insights = $businessInsights ?? [];
        $actionToneClasses = [
            'amber' => 'border-amber-300/30 bg-amber-400/10 text-amber-100',
            'rose' => 'border-rose-300/30 bg-rose-400/10 text-rose-100',
            'cyan' => 'border-cyan-300/30 bg-cyan-400/10 text-cyan-100',
            'emerald' => 'border-emerald-300/30 bg-emerald-400/10 text-emerald-100',
        ];
    @endphp

    <div class="min-h-screen bg-slate-950 bg-cover bg-center bg-fixed text-white" style="background-image: linear-gradient(rgba(2, 6, 23, 0.42), rgba(2, 6, 23, 0.68)), url('{{ asset('images/bg.png') }}');">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-12">
                <section class="rounded-2xl border border-amber-300 bg-black p-4 text-white shadow-2xl lg:col-span-4">
                    <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-black tracking-[0.18em] text-amber-300">NOTICE BOARD</p>
                            <h1 class="mt-1 text-lg font-black">管理者からのお知らせ</h1>
                        </div>
                        <a href="{{ route('notices.index') }}" class="inline-flex w-fit rounded-full bg-amber-300 px-3 py-1 text-xs font-black text-black hover:bg-amber-200">
                            すべて見る
                        </a>
                    </div>

                    @if (($dashboardNotices ?? collect())->isNotEmpty())
                        <div class="divide-y divide-white/15">
                            @foreach ($dashboardNotices as $notice)
                                <a href="{{ route('notices.show', $notice) }}" class="block py-2 transition hover:bg-white/10">
                                    <div class="flex flex-col gap-1 px-2 md:flex-row md:items-center md:justify-between">
                                        <h2 class="text-sm font-black text-white">{{ $notice->title }}</h2>
                                        <time datetime="{{ $notice->published_at?->toDateString() }}" class="text-xs font-bold text-amber-200">
                                            {{ $notice->published_at?->format('Y/m/d') }}
                                        </time>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <p class="rounded-xl border border-white/15 bg-white/10 p-4 text-sm font-bold text-white">
                            現在、掲載中のお知らせはありません。
                        </p>
                    @endif
                </section>

                <section class="rounded-2xl border border-emerald-300/25 bg-slate-950/60 p-4 shadow-2xl backdrop-blur-md lg:col-span-8">
                    <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-xs font-black tracking-[0.18em] text-emerald-300">BUSINESS INSIGHTS</p>
                            <h2 class="mt-1 text-lg font-black">経営インサイト</h2>
                        </div>
                        <p class="text-xs font-bold text-slate-300">今月の売上目標 ¥{{ number_format($insights['monthly_sales_target'] ?? 0) }}</p>
                    </div>

                    <div class="mb-4">
                        <div class="mb-2 flex items-center justify-between text-xs font-bold">
                            <span>目標進捗</span>
                            <span>{{ number_format($insights['monthly_target_progress'] ?? 0, 1) }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-800">
                            <div class="h-full rounded-full bg-emerald-400" style="width: {{ min(100, max(0, $insights['monthly_target_progress'] ?? 0)) }}%"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                        <div class="rounded-xl border border-cyan-300/20 bg-cyan-400/10 p-3">
                            <p class="text-xs font-bold text-cyan-200">今月売上</p>
                            <p class="mt-2 text-xl font-black">¥{{ number_format($insights['current_month_sales'] ?? 0) }}</p>
                            <p class="mt-2 text-xs font-bold text-slate-300">前月比 {{ ($insights['sales_trend_percent'] ?? null) === null ? 'データ待ち' : (($insights['sales_trend_percent'] ?? 0) >= 0 ? '+' : '').($insights['sales_trend_percent'] ?? 0).'%' }}</p>
                        </div>
                        <div class="rounded-xl border border-emerald-300/20 bg-emerald-400/10 p-3">
                            <p class="text-xs font-bold text-emerald-200">今月利益</p>
                            <p class="mt-2 text-xl font-black">¥{{ number_format($insights['current_month_profit'] ?? 0) }}</p>
                            <p class="mt-2 text-xs font-bold text-slate-300">前月比 {{ ($insights['profit_trend_percent'] ?? null) === null ? 'データ待ち' : (($insights['profit_trend_percent'] ?? 0) >= 0 ? '+' : '').($insights['profit_trend_percent'] ?? 0).'%' }}</p>
                        </div>
                        <div class="rounded-xl border border-violet-300/20 bg-violet-400/10 p-3">
                            <p class="text-xs font-bold text-violet-200">利益率 / 平均利益</p>
                            <p class="mt-2 text-xl font-black">{{ number_format($insights['profit_margin'] ?? 0, 1) }}%</p>
                            <p class="mt-2 text-xs font-bold text-slate-300">1件平均 ¥{{ number_format($insights['average_profit'] ?? 0) }}</p>
                        </div>
                        <div class="rounded-xl border border-amber-300/20 bg-amber-400/10 p-3">
                            <p class="text-xs font-bold text-amber-200">滞留在庫</p>
                            <p class="mt-2 text-xl font-black">{{ number_format($insights['stale_count'] ?? 0) }}件</p>
                            <p class="mt-2 text-xs font-bold text-slate-300">原価 ¥{{ number_format($insights['stale_inventory_cost'] ?? 0) }}</p>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
                        @foreach (($insights['actions'] ?? collect()) as $action)
                            @php $tone = $actionToneClasses[$action['tone'] ?? 'cyan'] ?? $actionToneClasses['cyan']; @endphp
                            <article class="rounded-xl border p-3 {{ $tone }}">
                                <h3 class="text-sm font-black">{{ $action['title'] }}</h3>
                                <p class="mt-2 max-h-10 overflow-hidden text-xs font-semibold leading-5 text-slate-200">{{ $action['body'] }}</p>
                                <a href="{{ $action['href'] }}" class="mt-3 inline-flex rounded-lg bg-white/15 px-3 py-1.5 text-xs font-black text-white hover:bg-white/25">{{ $action['label'] }}</a>
                            </article>
                        @endforeach
                    </div>
                </section>
            </div>

            <section class="mb-8 rounded-2xl border border-lime-300/25 bg-slate-950/70 p-5 shadow-2xl backdrop-blur-md">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-black tracking-[0.18em] text-lime-300">PROFIT SUMMARY</p>
                        <h2 class="mt-1 text-xl font-black text-white">利益サマリー</h2>
                        <p class="mt-2 max-w-3xl text-sm font-bold leading-7 text-slate-200">
                            今月の利益、利益率、出品中商品の見込み利益をまとめて確認できます。仕入れ・値下げ・追加出品の判断に使ってください。
                        </p>
                    </div>
                    <a href="{{ route('sales.index') }}" class="inline-flex items-center justify-center rounded-lg bg-lime-300 px-5 py-3 text-sm font-black text-slate-950 hover:bg-lime-200">
                        売上詳細を見る
                    </a>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-lime-300/20 bg-lime-400/10 p-4">
                        <p class="text-xs font-black text-lime-200">今月利益</p>
                        <p class="mt-2 text-2xl font-black {{ ($insights['current_month_profit'] ?? 0) < 0 ? 'text-red-300' : 'text-lime-200' }}">¥{{ number_format($insights['current_month_profit'] ?? 0) }}</p>
                        <p class="mt-2 text-xs font-bold text-slate-300">利益率 {{ number_format($insights['current_month_profit_margin'] ?? 0, 1) }}%</p>
                    </div>
                    <div class="rounded-xl border border-emerald-300/20 bg-emerald-400/10 p-4">
                        <p class="text-xs font-black text-emerald-200">累計利益</p>
                        <p class="mt-2 text-2xl font-black {{ ($totalProfit ?? 0) < 0 ? 'text-red-300' : 'text-emerald-200' }}">¥{{ number_format($totalProfit ?? 0) }}</p>
                        <p class="mt-2 text-xs font-bold text-slate-300">累計利益率 {{ number_format($insights['profit_margin'] ?? 0, 1) }}%</p>
                    </div>
                    <div class="rounded-xl border border-cyan-300/20 bg-cyan-400/10 p-4">
                        <p class="text-xs font-black text-cyan-200">出品中の見込み売上</p>
                        <p class="mt-2 text-2xl font-black text-cyan-100">¥{{ number_format($insights['inventory_potential_sales'] ?? 0) }}</p>
                        <p class="mt-2 text-xs font-bold text-slate-300">原価 ¥{{ number_format($insights['inventory_cost'] ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-amber-300/20 bg-amber-400/10 p-4">
                        <p class="text-xs font-black text-amber-200">出品中の見込み利益</p>
                        <p class="mt-2 text-2xl font-black {{ ($insights['inventory_potential_profit'] ?? 0) < 0 ? 'text-red-300' : 'text-amber-100' }}">¥{{ number_format($insights['inventory_potential_profit'] ?? 0) }}</p>
                        <p class="mt-2 text-xs font-bold text-slate-300">売れ残り {{ number_format($insights['stale_count'] ?? 0) }}件</p>
                    </div>
                </div>
            </section>

            <section class="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-5">
                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-5 shadow-xl backdrop-blur-md"><div class="text-sm font-bold text-slate-300">総商品数</div><div class="mt-3 text-3xl font-black">{{ number_format($displayTotalItems) }}</div></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-cyan-400/10 p-5 shadow-xl backdrop-blur-md"><div class="text-sm font-bold text-cyan-200">出品中</div><div class="mt-3 text-3xl font-black text-cyan-100">{{ number_format($sellingCount ?? 0) }}</div></div>
                <div class="rounded-2xl border border-violet-300/20 bg-violet-400/10 p-5 shadow-xl backdrop-blur-md"><div class="text-sm font-bold text-violet-200">販売済み</div><div class="mt-3 text-3xl font-black text-violet-100">{{ number_format($soldCount ?? 0) }}</div></div>
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-400/10 p-5 shadow-xl backdrop-blur-md"><div class="text-sm font-bold text-emerald-200">累計売上</div><div class="mt-3 text-2xl font-black text-emerald-100">¥{{ number_format($totalSales ?? 0) }}</div></div>
                <div class="rounded-2xl border border-orange-300/20 bg-orange-400/10 p-5 shadow-xl backdrop-blur-md"><div class="text-sm font-bold text-orange-200">累計利益</div><div class="mt-3 text-2xl font-black text-orange-100">¥{{ number_format($totalProfit ?? 0) }}</div></div>
            </section>

            <section class="mb-8 rounded-2xl border border-emerald-300/25 bg-slate-950/70 p-5 shadow-2xl backdrop-blur-md">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-xs font-black tracking-[0.18em] text-emerald-300">DATA PROTECTION</p>
                        <h2 class="mt-1 text-xl font-black text-white">データ保護とバックアップ</h2>
                        <p class="mt-2 max-w-3xl text-sm font-bold leading-7 text-slate-200">
                            商品全削除、CSV一括取り込み、端末変更、データ整理の前には「復元用CSV」を保存してください。戻す時はCSV管理の「FurimaDeck形式CSVを一括登録」から復元用CSVを取り込みます。
                        </p>
                        <div class="mt-4 grid gap-3 text-sm font-bold leading-6 text-slate-100 md:grid-cols-3">
                            <div class="rounded-xl border border-emerald-300/20 bg-emerald-400/10 p-3">
                                <p class="font-black text-emerald-200">1. 削除や整理の前</p>
                                <p class="mt-1 text-slate-200">CSV管理で「復元用CSV」を出力します。確認用には「全商品バックアップCSV」も保存してください。</p>
                            </div>
                            <div class="rounded-xl border border-cyan-300/20 bg-cyan-400/10 p-3">
                                <p class="font-black text-cyan-200">2. 失敗した時</p>
                                <p class="mt-1 text-slate-200">CSV管理のFurimaDeck形式CSV取り込みから、保存していた復元用CSVを選びます。</p>
                            </div>
                            <div class="rounded-xl border border-amber-300/20 bg-amber-400/10 p-3">
                                <p class="font-black text-amber-200">3. 重複はスキップ</p>
                                <p class="mt-1 text-slate-200">同じ管理IDの商品は重複登録されません。未登録の商品だけ戻ります。</p>
                            </div>
                        </div>
                    </div>
                    @if ($hasActiveSubscription ?? false)
                        <a href="{{ route('auction-items.csv-import') }}" class="inline-flex items-center justify-center rounded-lg bg-emerald-400 px-5 py-3 text-sm font-black text-slate-950 hover:bg-emerald-300">
                            CSV管理へ
                        </a>
                    @else
                        <div class="rounded-xl border border-cyan-300/25 bg-cyan-400/10 p-4">
                            <p class="text-sm font-black text-cyan-100">Freeは商品{{ number_format($freeItemLimit ?? 50) }}件までです。Premiumは7日間無料お試し後、月額480円（税込）でCSV管理や売上分析まで使えます。</p>
                            <a href="{{ route('subscriptions.index') }}" class="mt-3 inline-flex rounded-lg bg-cyan-400 px-5 py-2 text-sm font-black text-slate-950 hover:bg-cyan-300">
                                7日間無料でPremiumを試す
                            </a>
                        </div>
                    @endif
                </div>
            </section>

            <section class="mb-8 rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-6 shadow-2xl backdrop-blur-md">
                <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="text-xl font-black">{{ $displayYear }}年 月別売上・利益</h2>
                    <span class="text-xs font-bold text-cyan-300">sold_at / sold_price / profit</span>
                </div>
                <div class="h-96"><canvas id="monthlySalesProfitLineChart"></canvas></div>
            </section>

            <section class="rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-6 shadow-2xl backdrop-blur-md">
                <div class="mb-5 flex items-center justify-between">
                    <h2 class="text-xl font-black">最近更新した商品</h2>
                    <a href="{{ route('auction-items.index') }}" class="text-sm font-bold text-emerald-300 hover:text-emerald-200">すべて見る</a>
                </div>

                @if (($recentItems ?? collect())->count() > 0)
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($recentItems as $item)
                            @php $displayImagePath = $item->status === 'sold' && $item->sold_image_path ? $item->sold_image_path : $item->image_path; @endphp
                            <a href="{{ route('auction-items.show', $item) }}" class="group overflow-hidden rounded-2xl border border-cyan-300/20 bg-white/10 shadow-xl transition hover:-translate-y-1 hover:bg-white/15">
                                <div class="aspect-[4/3] bg-slate-800">
                                    @if ($displayImagePath)
                                        <img src="{{ asset('storage/' . $displayImagePath) }}" alt="{{ $item->title }}" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                                    @else
                                        <div class="flex h-full items-center justify-center text-sm font-bold text-cyan-200">NO IMAGE</div>
                                    @endif
                                </div>
                                <div class="p-4">
                                    <div class="line-clamp-2 text-base font-black text-white">{{ $item->title }}</div>
                                    <div class="mt-2 text-xs font-bold text-cyan-300">管理ID: {{ $item->management_id }}</div>
                                    <div class="mt-3 flex items-center justify-between">
                                        <span class="rounded-full bg-white/10 px-3 py-1 text-xs font-bold text-slate-200">{{ $item->platform ?: '未設定' }}</span>
                                        <span class="rounded-full {{ $item->status === 'sold' ? 'bg-red-500/20 text-red-200' : 'bg-emerald-500/20 text-emerald-200' }} px-3 py-1 text-xs font-black">{{ $item->status === 'sold' ? 'SOLD' : '出品中' }}</span>
                                    </div>
                                    @if ($item->status === 'sold')
                                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-bold text-slate-300">
                                            <div class="rounded-xl bg-slate-950/45 p-2">売値<br>¥{{ number_format($item->sold_price ?? 0) }}</div>
                                            <div class="rounded-xl bg-slate-950/45 p-2">利益<br>¥{{ number_format($item->profit ?? 0) }}</div>
                                        </div>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="rounded-2xl bg-white/10 p-8 text-sm font-bold text-cyan-300">最近更新した商品はありません。</div>
                @endif
            </section>

            <footer class="mt-8 rounded-2xl border border-cyan-300/20 bg-slate-950/55 p-6 text-sm font-bold text-cyan-100 shadow-2xl backdrop-blur-md">
                <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="ダッシュボード下部リンク">
                    <a href="{{ route('legal.terms') }}" class="hover:text-cyan-300">利用規約</a>
                    <a href="{{ route('legal.privacy') }}" class="hover:text-cyan-300">プライバシーポリシー</a>
                    <a href="{{ route('legal.commercial') }}" class="hover:text-cyan-300">特定商取引法に基づく表記</a>
                    <a href="{{ route('legal.faq') }}" class="hover:text-cyan-300">よくある質問</a>
                    <a href="{{ route('legal.contact') }}" class="hover:text-cyan-300">お問い合わせ</a>
                </nav>
                <p class="mt-4 text-xs text-slate-300">&copy; 2026 FurimaDeck. All rights reserved.</p>
            </footer>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const monthlyChartLabels = @json($monthlyChartLabels);
        const monthlyChartSales = @json($monthlyChartSales);
        const monthlyChartProfit = @json($monthlyChartProfit);
        const monthlySalesProfitLineCanvas = document.getElementById('monthlySalesProfitLineChart');
        const yenTickCallback = function(value) { return '¥' + Number(value).toLocaleString(); };
        const yenTooltipLabel = function(context) { return context.dataset.label + ': ¥' + Number(context.raw).toLocaleString(); };

        if (monthlySalesProfitLineCanvas) {
            new Chart(monthlySalesProfitLineCanvas, {
                type: 'line',
                data: {
                    labels: monthlyChartLabels,
                    datasets: [
                        { label: '月別売上', data: monthlyChartSales, borderColor: '#22d3ee', backgroundColor: 'rgba(34, 211, 238, 0.14)', borderWidth: 3, tension: 0.35, pointRadius: 4, pointHoverRadius: 6, fill: true },
                        { label: '月別利益', data: monthlyChartProfit, borderColor: '#34d399', backgroundColor: 'rgba(52, 211, 153, 0.12)', borderWidth: 3, tension: 0.35, pointRadius: 4, pointHoverRadius: 6, fill: true }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { labels: { color: '#e2e8f0', font: { weight: 'bold' } } },
                        tooltip: { callbacks: { label: yenTooltipLabel } }
                    },
                    scales: {
                        x: { ticks: { color: '#cbd5e1' }, grid: { color: 'rgba(148, 163, 184, 0.16)' } },
                        y: { beginAtZero: true, ticks: { color: '#cbd5e1', callback: yenTickCallback }, grid: { color: 'rgba(148, 163, 184, 0.16)' } }
                    }
                }
            });
        }
    </script>
</x-app-layout>
