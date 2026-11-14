<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-white leading-tight">出品先適性分析</h2></x-slot>
    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <form method="get" action="{{ route('furimadeck-analytics.suitability') }}" class="flex flex-wrap items-end gap-4 rounded-lg bg-white p-4 shadow-sm">
            <label class="block text-sm text-gray-700">対象月<input type="month" name="month" value="{{ $month->format('Y-m') }}" class="mt-1 block rounded-md border-gray-300"></label>
            <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">表示</button>
        </form>
        <section class="rounded-lg bg-white p-6 shadow-sm">
            <h3 class="text-lg font-semibold text-gray-900">あなたの販売実績に基づく比較</h3>
            <p class="mt-2 text-sm text-gray-600">外部相場ではなく、選択月のSOLD実績だけを比較しています。3件未満の出品先は断定しません。</p>
            <div class="mt-4 rounded-lg border border-cyan-200 bg-cyan-50 p-4">
                <h4 class="font-semibold text-cyan-950">比較結果の参考</h4>
                <p class="mt-1 text-sm text-cyan-900">利益重視・回転重視で、過去のあなたの実績から候補を表示します。将来の販売結果を保証するものではありません。</p>
                @forelse ($report['recommendations'] as $recommendation)
                    <div class="mt-3 grid gap-2 text-sm text-cyan-950 sm:grid-cols-3">
                        <p><span class="font-semibold">{{ $recommendation['category'] }}</span></p>
                        <p>利益重視：{{ $recommendation['profit_marketplace'] }}（実利益中央値 {{ number_format($recommendation['median_profit']) }}円 / {{ $recommendation['profit_sample_count'] }}件）</p>
                        <p>回転重視：{{ $recommendation['speed_marketplace'] }}（販売日数中央値 {{ $recommendation['median_sale_days'] }}日 / {{ $recommendation['speed_sample_count'] }}件）</p>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-cyan-900">同一カテゴリで比較できる出品先が2つ以上あり、それぞれ有効な販売日数が3件以上そろうと候補を表示します。</p>
                @endforelse
            </div>
            <div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2 pr-4">カテゴリ</th><th class="py-2 pr-4">出品先</th><th class="py-2 pr-4">件数</th><th class="py-2 pr-4">売価中央値</th><th class="py-2 pr-4">実利益中央値</th><th class="py-2 pr-4">利益率</th><th class="py-2 pr-4">販売日数中央値</th><th class="py-2 pr-4">利益速度</th><th class="py-2">判定</th></tr></thead><tbody>
                @forelse ($report['rows'] as $row)
                    <tr class="border-b"><td class="py-2 pr-4">{{ $row['category'] }}</td><td class="py-2 pr-4">{{ $row['marketplace'] }}</td><td class="py-2 pr-4">{{ $row['sample_count'] }}件</td><td class="py-2 pr-4">{{ $row['median_sale_price'] === null ? 'データ不足' : number_format($row['median_sale_price']).'円' }}</td><td class="py-2 pr-4">{{ $row['median_profit'] === null ? 'データ不足' : number_format($row['median_profit']).'円' }}</td><td class="py-2 pr-4">{{ $row['median_profit_margin'] === null ? 'データ不足' : number_format($row['median_profit_margin'], 1).'%' }}</td><td class="py-2 pr-4">{{ $row['median_sale_days'] === null ? 'データ不足' : $row['median_sale_days'].'日' }}</td><td class="py-2 pr-4">{{ $row['profit_velocity'] === null ? 'データ不足' : number_format($row['profit_velocity'], 1).'円 / 日' }}</td><td class="py-2">{{ $row['comparable'] ? '比較可能' : 'データ不足' }}</td></tr>
                @empty
                    <tr><td colspan="9" class="py-6 text-center text-gray-500">販売実績がありません。</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    </div></div>
</x-app-layout>
