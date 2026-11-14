<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white leading-tight">高度分析</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <form method="get" action="{{ route('furimadeck-analytics.advanced') }}" class="bg-white p-4 shadow-sm sm:rounded-lg flex flex-wrap gap-4 items-end">
                <label class="block text-sm text-gray-700">開始日<input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="mt-1 block rounded-md border-gray-300"></label>
                <label class="block text-sm text-gray-700">終了日<input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="mt-1 block rounded-md border-gray-300"></label>
                <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">集計</button>
            </form>

            @if ($report['sales']['sale_count'] === 0)
                <section class="rounded-lg border border-amber-300 bg-amber-50 p-5 shadow-sm">
                    <h3 class="font-semibold text-amber-950">販売データ不足</h3>
                    <p class="mt-2 text-sm font-bold leading-6 text-amber-900">指定期間に販売レコードがありません。商品CSVには売上金額・売却日・手数料・送料がないため、売上系指標は推測せずデータ不足として扱います。</p>
                </section>
            @endif            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-7">
                @foreach ([['売上', $report['sales']['sales_amount'].'円'], ['販売手数料', $report['sales']['sales_fee'].'円'], ['実利益', $report['sales']['profit'].'円'], ['実利益率', $report['sales']['profit_margin'].'%'], ['SOLD件数', $report['sales']['sale_count'].'件'], ['在庫仕入額', $report['inventory']['capital'].'円']] as [$label, $value])
                    <div class="bg-white p-4 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">{{ $label }}</p><p class="mt-1 text-xl font-bold text-gray-900">{{ number_format((float) str_replace(['円', '%', '件'], '', $value), str_contains($value, '%') ? 1 : 0) }}{{ str_contains($value, '%') ? '%' : (str_contains($value, '件') ? '件' : '円') }}</p></div>
                @endforeach
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-semibold text-gray-900">販売日数</h3>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <dt class="text-gray-500">中央値</dt><dd>{{ $report['sale_days']['median'] === null ? 'データ不足' : $report['sale_days']['median'].'日' }}</dd>
                        <dt class="text-gray-500">平均</dt><dd>{{ $report['sale_days']['average'] === null ? 'データ不足' : $report['sale_days']['average'].'日' }}</dd>
                        <dt class="text-gray-500">最短 / 最長</dt><dd>{{ $report['sale_days']['minimum'] ?? '-' }} / {{ $report['sale_days']['maximum'] ?? '-' }}日</dd>
                        <dt class="text-gray-500">算出対象外</dt><dd>{{ $report['sale_days']['excluded_count'] }}件</dd>
                    </dl>
                </section>
                <section class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="font-semibold text-gray-900">利益速度</h3>
                    <p class="mt-4 text-2xl font-bold text-gray-900">{{ $report['profit_velocity']['median'] === null ? 'データ不足' : number_format($report['profit_velocity']['median'], 1).'円 / 日' }}</p>
                    <p class="mt-2 text-sm text-gray-500">実利益 ÷ max(1, 販売日数)。税務指標ではありません。</p>
                </section>
            </div>

            <section class="bg-white p-6 shadow-sm sm:rounded-lg overflow-x-auto">
                <h3 class="font-semibold text-gray-900">価格帯分析</h3>
                <table class="mt-4 min-w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2 pr-4">価格帯</th><th class="py-2 pr-4">SOLD</th><th class="py-2 pr-4">売上</th><th class="py-2 pr-4">販売手数料</th><th class="py-2 pr-4">実利益</th><th class="py-2">販売日数中央値</th></tr></thead><tbody>
                    @foreach ($report['price_bands'] as $band)<tr class="border-b"><td class="py-2 pr-4">{{ $band['label'] }}</td><td class="py-2 pr-4">{{ $band['sale_count'] }}件</td><td class="py-2 pr-4">{{ number_format($band['sales_amount']) }}円</td><td class="py-2 pr-4">{{ number_format($band['sales_fee']) }}円</td><td class="py-2 pr-4">{{ number_format($band['profit']) }}円</td><td class="py-2">{{ $band['median_sale_days'] === null ? 'データ不足' : $band['median_sale_days'].'日' }}</td></tr>@endforeach
                </tbody></table>
            </section>

            <section class="bg-white p-6 shadow-sm sm:rounded-lg overflow-x-auto">
                <h3 class="font-semibold text-gray-900">ブランド別分析</h3>
                <table class="mt-4 min-w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2 pr-4">ブランド</th><th class="py-2 pr-4">SOLD</th><th class="py-2 pr-4">売上</th><th class="py-2 pr-4">実利益</th><th class="py-2 pr-4">実利益率</th><th class="py-2 pr-4">販売日数中央値</th><th class="py-2">平均販売価格</th></tr></thead><tbody>
                    @forelse ($report['brand'] as $row)
                        <tr class="border-b"><td class="py-2 pr-4">{{ $row['name'] }}</td><td class="py-2 pr-4">{{ $row['sale_count'] }}件</td><td class="py-2 pr-4">{{ number_format($row['sales_amount']) }}円</td><td class="py-2 pr-4">{{ number_format($row['profit']) }}円</td><td class="py-2 pr-4">{{ number_format($row['profit_margin'], 1) }}%</td><td class="py-2 pr-4">{{ $row['median_sale_days'] === null ? 'データ不足' : $row['median_sale_days'].'日' }}</td><td class="py-2">{{ number_format($row['average_sold_price']) }}円</td></tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-gray-500">データ不足</td></tr>
                    @endforelse
                </tbody></table>
            </section>

            <section class="bg-white p-6 shadow-sm sm:rounded-lg overflow-x-auto">
                <h3 class="font-semibold text-gray-900">季節性（月別）</h3>
                @if (($report['seasonality'][0]['is_reference'] ?? true))
                    <p class="mt-2 text-sm font-medium text-amber-800">販売データが12か月分に満たないため参考値です。</p>
                @endif
                <table class="mt-4 min-w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2 pr-4">月</th><th class="py-2 pr-4">SOLD</th><th class="py-2 pr-4">売上</th><th class="py-2 pr-4">実利益</th><th class="py-2 pr-4">実利益率</th><th class="py-2">ジャンル構成（販売件数 / 売上）</th></tr></thead><tbody>
                    @forelse ($report['seasonality'] as $row)
                        <tr class="border-b align-top"><td class="py-2 pr-4">{{ $row['label'] }}</td><td class="py-2 pr-4">{{ $row['sale_count'] }}件</td><td class="py-2 pr-4">{{ number_format($row['sales_amount']) }}円</td><td class="py-2 pr-4">{{ number_format($row['profit']) }}円</td><td class="py-2 pr-4">{{ number_format($row['profit_margin'], 1) }}%</td><td class="py-2"><ul class="space-y-1">@foreach ($row['genres'] as $genre)<li>{{ $genre['name'] }}：{{ $genre['sale_count'] }}件 / {{ number_format($genre['sales_amount']) }}円</li>@endforeach</ul></td></tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-gray-500">データ不足</td></tr>
                    @endforelse
                </tbody></table>
            </section>

            <section class="bg-white p-6 shadow-sm sm:rounded-lg overflow-x-auto">
                <h3 class="font-semibold text-gray-900">仕入先分析</h3>
                <table class="mt-4 min-w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2 pr-4">仕入先</th><th class="py-2 pr-4">仕入件数</th><th class="py-2 pr-4">販売原価</th><th class="py-2 pr-4">売上</th><th class="py-2 pr-4">販売手数料</th><th class="py-2 pr-4">実利益</th><th class="py-2 pr-4">利益率</th><th class="py-2 pr-4">SOLD</th><th class="py-2 pr-4">販売日数中央値</th><th class="py-2">現在庫仕入額</th></tr></thead><tbody>
                    @forelse ($report['supplier'] as $row)
                        <tr class="border-b"><td class="py-2 pr-4">{{ $row['name'] }}</td><td class="py-2 pr-4">{{ $row['purchase_count'] }}件</td><td class="py-2 pr-4">{{ number_format($row['sale_cost']) }}円</td><td class="py-2 pr-4">{{ number_format($row['sales_amount']) }}円</td><td class="py-2 pr-4">{{ number_format($row['sales_fee']) }}円</td><td class="py-2 pr-4">{{ number_format($row['profit']) }}円</td><td class="py-2 pr-4">{{ number_format($row['profit_margin'], 1) }}%</td><td class="py-2 pr-4">{{ $row['sale_count'] }}件</td><td class="py-2 pr-4">{{ $row['median_sale_days'] === null ? 'データ不足' : $row['median_sale_days'].'日' }}</td><td class="py-2">{{ number_format($row['inventory_capital']) }}円</td></tr>
                    @empty
                        <tr><td colspan="10" class="py-4 text-gray-500">データ不足</td></tr>
                    @endforelse
                </tbody></table>
            </section>
            <section class="bg-white p-6 shadow-sm sm:rounded-lg overflow-x-auto">
                <h3 class="font-semibold text-gray-900">利益率 × 回転速度マトリクス</h3>
                <p class="mt-2 text-sm text-gray-600">各区分のユーザー内中央値で分類します。利益速度は実利益 ÷ 販売日数による業務分析値で、税務指標ではありません。</p>
                @php
                    $matrixDimensions = [
                        '商品別' => $report['matrix'],
                        'ジャンル別' => $report['matrix']['dimensions']['genre'],
                        'ブランド別' => $report['matrix']['dimensions']['brand'],
                        '仕入先別' => $report['matrix']['dimensions']['supplier'],
                    ];
                @endphp
                <div class="mt-4 space-y-6">
                    @foreach ($matrixDimensions as $dimensionName => $matrix)
                        <div class="overflow-x-auto">
                            <h4 class="font-medium text-gray-900">{{ $dimensionName }}</h4>
                            @if (! $matrix['available'])
                                <p class="mt-2 text-sm text-gray-600">データ不足。販売日数を算出できる区分が3つ以上必要です。</p>
                            @else
                                <p class="mt-2 text-sm text-gray-600">利益率中央値 {{ number_format($matrix['margin_median'], 1) }}%、利益速度中央値 {{ number_format($matrix['velocity_median'], 1) }}円 / 日</p>
                                <table class="mt-3 min-w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2 pr-4">{{ $dimensionName === '商品別' ? '商品' : '区分' }}</th><th class="py-2 pr-4">利益率</th><th class="py-2 pr-4">利益速度</th><th class="py-2 pr-4">件数</th><th class="py-2">分類</th></tr></thead><tbody>
                                    @foreach ($matrix['rows'] as $row)
                                        <tr class="border-b"><td class="py-2 pr-4">{{ $row['product_name'] }}</td><td class="py-2 pr-4">{{ number_format($row['profit_margin'], 1) }}%</td><td class="py-2 pr-4">{{ number_format($row['profit_velocity'], 1) }}円 / 日</td><td class="py-2 pr-4">{{ $row['sample_count'] }}件</td><td class="py-2">{{ $row['quadrant'] }}</td></tr>
                                    @endforeach
                                </tbody></table>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
            <section class="grid gap-6 lg:grid-cols-2">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg"><h3 class="font-semibold text-gray-900">在庫資金・滞留</h3><dl class="mt-4 space-y-2 text-sm"><div class="flex justify-between"><dt>30日以上</dt><dd>{{ number_format($report['inventory']['over_30_days']) }}円</dd></div><div class="flex justify-between"><dt>60日以上</dt><dd>{{ number_format($report['inventory']['over_60_days']) }}円</dd></div><div class="flex justify-between"><dt>90日以上</dt><dd>{{ number_format($report['inventory']['over_90_days']) }}円</dd></div><div class="flex justify-between"><dt>仕入日未設定</dt><dd>{{ number_format($report['inventory']['purchase_date_unset']) }}円</dd></div></dl></div>
                <div class="bg-white p-6 shadow-sm sm:rounded-lg"><h3 class="font-semibold text-gray-900">曜日別実績</h3><div class="mt-4 space-y-2 text-sm">@forelse ($report['weekday'] as $row)<div class="flex justify-between"><span>{{ $row['label'] }}</span><span>{{ $row['sale_count'] }}件 / {{ number_format($row['profit']) }}円</span></div>@empty<p class="text-gray-500">データ不足</p>@endforelse</div></div>
            </section>
        </div>
    </div>
</x-app-layout>
