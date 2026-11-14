<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-white leading-tight">今日の販売改善</h2></x-slot>
    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <p class="text-sm text-slate-200">過去の販売実績と現在の在庫をもとにした判断材料です。自動値下げや自動出品は行いません。</p>
        <form method="GET" action="{{ route('furimadeck-improvement.index') }}" class="flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow-sm">
            <div>
                <label for="stagnant_days" class="block text-sm font-semibold text-gray-800">出品後何日以上で滞留在庫を探すか</label>
                <input id="stagnant_days" name="stagnant_days" type="number" min="{{ config('furimadeck.improvement.stagnant_search_min_days') }}" max="{{ config('furimadeck.improvement.stagnant_search_max_days') }}" value="{{ $stagnantDays }}" required class="mt-1 w-40 rounded-md border-gray-400 text-gray-900">
            </div>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700">滞留商品を検索</button>
            <a href="{{ route('furimadeck-improvement.index') }}" class="rounded-md border border-slate-400 px-4 py-2 font-semibold text-slate-800 hover:bg-slate-100">{{ config('furimadeck.improvement.stagnant_days.0') }}日に戻す</a>
            <p class="w-full text-sm text-gray-600">初期表示は{{ config('furimadeck.improvement.stagnant_days.0') }}日以上です。指定した日数以上の出品中商品を滞留在庫に表示します。</p>
        </form>
        @php($sectionIds = ['stagnant_stock' => 'stagnant-stock', 'price_review' => 'price-review', 'relist' => 'relist', 'marketplace_change' => 'marketplace-change', 'rebuy' => 'rebuy'])
        @php($priorityLabels = ['notice' => '注意', 'medium' => '中', 'high' => '高'])
        @php($sections = [
            'stagnant_stock' => ['title' => '滞留在庫', 'columns' => ['商品', '出品先', '現在価格', '仕入額', '出品後', '比較件数', '過去売価中央値', '販売日数中央値', '優先度']],
            'price_review' => ['title' => '価格見直しの参考', 'columns' => ['商品', '出品先', '現在価格', '売価中央値', '実績価格範囲', '比較件数', '販売日数中央値', '出品後', '根拠']],
            'relist' => ['title' => '再出品を検討', 'columns' => ['商品', '出品先', '現在価格', '出品後', '根拠']],
            'marketplace_change' => ['title' => '出品先変更を検討', 'columns' => ['商品', '現在の出品先', '候補先', '現状比較件数', '現状販売日数中央値', '候補比較件数', '候補販売日数中央値', '滞留日数', '根拠']],
            'rebuy' => ['title' => '再仕入れ候補', 'columns' => ['分析軸', '対象', '販売件数', '実利益', '利益率', '販売日数中央値', '根拠']],
        ])
        @foreach ($sections as $key => $section)
            <section id="{{ $sectionIds[$key] }}" class="overflow-x-auto rounded-lg bg-white p-5 shadow-sm">
                <h3 class="text-lg font-bold text-gray-900">{{ $section['title'] }} <span class="text-sm font-normal text-gray-500">{{ count($actions[$key]) }}件</span></h3>
                @if (in_array($key, ['stagnant_stock', 'price_review'], true))
                    <p class="mt-1 text-sm text-gray-600">出品日からの日数で判定しています。比較件数と実績値を確認し、商品の状態・サイズなども確認して判断してください。自動値下げは行いません。</p>
                @endif
                <table class="mt-4 min-w-full text-sm"><thead><tr class="border-b text-left">@foreach ($section['columns'] as $column)<th class="py-2 pr-4">{{ $column }}</th>@endforeach</tr></thead><tbody>
                    @forelse ($actions[$key] as $row)
                        <tr class="border-b"><td class="py-2 pr-4">
                            @if (isset($row['product_id']))
                                <a href="{{ route('products.edit', $row['product_id']) }}" class="font-bold text-cyan-700 underline decoration-cyan-300 underline-offset-2 hover:text-cyan-900">{{ $row['product_name'] }}</a>
                            @else
                                {{ $row['dimension'] ?? '分析' }}
                            @endif
                        </td>
                        @if ($key === 'stagnant_stock')
                            <td class="py-2 pr-4">{{ $row['marketplace'] }}</td><td class="py-2 pr-4">{{ $row['current_price'] === null ? '未設定' : number_format($row['current_price']).'円' }}</td><td class="py-2 pr-4">{{ number_format($row['purchase_amount']) }}円</td><td class="py-2 pr-4">{{ $row['stagnant_days'] }}日</td><td class="py-2 pr-4">{{ $row['comparison_sample_count'] }}件</td><td class="py-2 pr-4">{{ $row['comparable_median_sold_price'] === null ? 'データ不足' : number_format($row['comparable_median_sold_price']).'円' }}</td><td class="py-2 pr-4">{{ $row['comparable_median_sale_days'] === null ? 'データ不足' : $row['comparable_median_sale_days'].'日' }}</td><td class="py-2">{{ $priorityLabels[$row['priority']] ?? '未設定' }}</td>
                        @elseif ($key === 'price_review')
                            <td class="py-2 pr-4">{{ $row['marketplace'] }}</td><td class="py-2 pr-4">{{ $row['current_price'] === null ? '未設定' : number_format($row['current_price']).'円' }}</td><td class="py-2 pr-4">{{ number_format($row['median_sold_price']) }}円</td><td class="py-2 pr-4">{{ number_format($row['price_range_min']) }}～{{ number_format($row['price_range_max']) }}円</td><td class="py-2 pr-4">{{ $row['sample_count'] }}件</td><td class="py-2 pr-4">{{ $row['median_sale_days'] === null ? 'データ不足' : $row['median_sale_days'].'日' }}</td><td class="py-2 pr-4">{{ $row['stagnant_days'] }}日</td><td class="py-2">{{ $row['reason'] }}</td>
                        @elseif ($key === 'relist')
                            <td class="py-2 pr-4">{{ $row['marketplace'] }}</td><td class="py-2 pr-4">{{ $row['current_price'] === null ? '未設定' : number_format($row['current_price']).'円' }}</td><td class="py-2 pr-4">{{ $row['stagnant_days'] }}日</td><td class="py-2">{{ $row['reason'] }}</td>
                        @elseif ($key === 'marketplace_change')
                            <td class="py-2 pr-4">{{ $row['current_marketplace'] }}</td><td class="py-2 pr-4">{{ $row['alternative_marketplace'] }}</td><td class="py-2 pr-4">{{ $row['current_sample_count'] }}件</td><td class="py-2 pr-4">{{ $row['current_median_days'] }}日</td><td class="py-2 pr-4">{{ $row['sample_count'] }}件</td><td class="py-2 pr-4">{{ $row['comparison_median_days'] }}日</td><td class="py-2 pr-4">{{ $row['stagnant_days'] }}日</td><td class="py-2">{{ $row['reason'] }}</td>
                        @else
                            <td class="py-2 pr-4">{{ $row['label'] }}</td><td class="py-2 pr-4">{{ $row['sample_count'] }}件</td><td class="py-2 pr-4">{{ number_format($row['profit']) }}円</td><td class="py-2 pr-4">{{ number_format($row['profit_margin'], 1) }}%</td><td class="py-2 pr-4">{{ $row['median_sale_days'] }}日</td><td class="py-2">{{ $row['reason'] }}</td>
                        @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($section['columns']) }}" class="py-4 text-gray-500">該当する候補はありません。</td></tr>
                    @endforelse
                </tbody></table>
            </section>
        @endforeach
    </div></div>
</x-app-layout>
