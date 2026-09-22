<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">今日の販売改善</h2></x-slot>
    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <p class="text-sm text-gray-600">過去の販売実績と現在の在庫をもとにした判断材料です。自動値下げや自動出品は行いません。</p>
        @php($sections = [
            'stagnant_stock' => ['title' => '滞留在庫', 'columns' => ['商品', '滞留日数', '仕入額', '優先度']],
            'price_review' => ['title' => '価格見直しの参考', 'columns' => ['商品', '現在価格', '過去売価中央値', 'サンプル数', '滞留日数']],
            'relist' => ['title' => '再出品を検討', 'columns' => ['商品', '滞留日数', '根拠']],
            'marketplace_change' => ['title' => '出品先変更を検討', 'columns' => ['商品', '比較サンプル数', '比較先の販売日数中央値', '根拠']],
            'rebuy' => ['title' => '再仕入れ候補', 'columns' => ['カテゴリID', 'サンプル数', '実利益', '利益率', '根拠']],
        ])
        @foreach ($sections as $key => $section)
            <section class="overflow-x-auto rounded-lg bg-white p-5 shadow-sm">
                <h3 class="text-lg font-bold text-gray-900">{{ $section['title'] }} <span class="text-sm font-normal text-gray-500">{{ count($actions[$key]) }}件</span></h3>
                @if ($key === 'stagnant_stock')
                    <p class="mt-1 text-sm text-gray-600">仕入日から設定日数以上経過し、在庫が残っている商品です。商品名を選ぶと編集画面を開けます。</p>
                @endif
                <table class="mt-4 min-w-full text-sm"><thead><tr class="border-b text-left">@foreach ($section['columns'] as $column)<th class="py-2 pr-4">{{ $column }}</th>@endforeach</tr></thead><tbody>
                    @forelse ($actions[$key] as $row)
                        <tr class="border-b"><td class="py-2 pr-4">
                            @if (isset($row['product_id']))
                                <a href="{{ route('products.edit', $row['product_id']) }}" class="font-bold text-cyan-700 underline decoration-cyan-300 underline-offset-2 hover:text-cyan-900">{{ $row['product_name'] }}</a>
                            @else
                                {{ $row['product_name'] ?? ($row['category_id'] === null ? '未設定' : $row['category_id']) }}
                            @endif
                        </td>
                        @if ($key === 'stagnant_stock')<td class="py-2 pr-4">{{ $row['stagnant_days'] }}日</td><td class="py-2 pr-4">{{ number_format($row['purchase_amount']) }}円</td><td class="py-2">{{ $row['priority'] }}</td>
                        @elseif ($key === 'price_review')<td class="py-2 pr-4">{{ number_format($row['current_price']) }}円</td><td class="py-2 pr-4">{{ number_format($row['median_sold_price']) }}円</td><td class="py-2 pr-4">{{ $row['sample_count'] }}件</td><td class="py-2">{{ $row['stagnant_days'] }}日</td>
                        @elseif ($key === 'relist')<td class="py-2 pr-4">{{ $row['stagnant_days'] }}日</td><td class="py-2">{{ $row['reason'] }}</td>
                        @elseif ($key === 'marketplace_change')<td class="py-2 pr-4">{{ $row['sample_count'] }}件</td><td class="py-2 pr-4">{{ $row['comparison_median_days'] }}日</td><td class="py-2">{{ $row['reason'] }}</td>
                        @else<td class="py-2 pr-4">{{ $row['sample_count'] }}件</td><td class="py-2 pr-4">{{ number_format($row['profit']) }}円</td><td class="py-2 pr-4">{{ number_format($row['profit_margin'], 1) }}%</td><td class="py-2">{{ $row['reason'] }}</td>@endif</tr>
                    @empty
                        <tr><td colspan="{{ count($section['columns']) }}" class="py-4 text-gray-500">該当する候補はありません。</td></tr>
                    @endforelse
                </tbody></table>
            </section>
        @endforeach
    </div></div>
</x-app-layout>
