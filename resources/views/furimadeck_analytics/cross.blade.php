<x-app-layout>
    <div class="min-h-screen bg-slate-950/70 py-8 text-white" style="background-image: linear-gradient(rgba(2, 6, 23, 0.50), rgba(2, 6, 23, 0.84)), url('{{ asset('images/bg.png') }}'); background-attachment: fixed; background-position: center; background-size: cover;">
        <div class="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8">
            <section class="rounded-2xl border border-slate-700 bg-slate-950/85 p-5 shadow-2xl backdrop-blur-md sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 class="text-xl font-black text-white sm:text-2xl">ジャンル × 出品先 クロス分析</h1>
                        <p class="mt-2 text-sm font-bold text-slate-200">大ジャンルごとに、各出品先の売上・販売手数料・SOLD件数・実利益を比較します。</p>
                    </div>
                    <form method="GET" class="flex items-end gap-2">
                        <label class="text-sm font-bold text-slate-200">集計月<input type="month" name="month" value="{{ $month->format('Y-m') }}" class="mt-1 block rounded-lg border-slate-600 bg-slate-900 text-white"></label>
                        <button class="rounded-lg border border-cyan-300/50 bg-cyan-900/70 px-4 py-2 text-sm font-black text-cyan-50 hover:bg-cyan-800">表示</button>
                    </form>
                </div>

                @if ($report['rows']->isEmpty())
                    <p class="mt-6 rounded-xl border border-slate-700 bg-slate-900/80 p-8 text-center font-bold text-slate-200">{{ $month->format('Y年n月') }}の販売データはありません。</p>
                @else
                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-[1050px] w-full border-separate border-spacing-0 text-left">
                            <thead>
                                <tr class="bg-cyan-950/80 text-sm text-slate-100">
                                    <th class="sticky left-0 z-10 min-w-36 border-b border-slate-700 bg-slate-950 p-4 font-black">大ジャンル</th>
                                    @foreach ($report['marketplaces'] as $marketplace)
                                        <th class="min-w-40 border-b border-slate-700 p-4 text-center font-black">{{ $marketplace['name'] }}</th>
                                    @endforeach
                                    <th class="min-w-44 border-b border-slate-700 bg-cyan-950/90 p-4 text-center font-black">合計</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($report['rows'] as $row)
                                    <tr class="bg-slate-950/75 hover:bg-slate-900/90">
                                        <th scope="row" class="sticky left-0 z-10 border-b border-slate-800 bg-slate-950 p-4 font-black text-white">{{ $row['name'] }}</th>
                                        @foreach ($report['marketplaces'] as $marketplace)
                                            @php($cell = $row['cells'][(string) $marketplace['id']])
                                            <td class="border-b border-slate-800 p-4 text-center">
                                                <p class="font-black text-white">¥{{ number_format($cell['sales']) }}</p>
                                                <p class="mt-1 text-xs font-bold text-slate-300">{{ number_format($cell['sale_count']) }}件 / 手数料 ¥{{ number_format($cell['sales_fee']) }} / 利益 ¥{{ number_format($cell['profit']) }}</p>
                                            </td>
                                        @endforeach
                                        <td class="border-b border-slate-800 bg-cyan-950/45 p-4 text-center">
                                            <p class="font-black text-cyan-100">¥{{ number_format($row['metrics']['sales']) }}</p>
                                            <p class="mt-1 text-xs font-bold text-cyan-100">{{ number_format($row['metrics']['sale_count']) }}件 / 手数料 ¥{{ number_format($row['metrics']['sales_fee']) }} / 利益 ¥{{ number_format($row['metrics']['profit']) }}</p>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bg-slate-900/95">
                                    <th class="sticky left-0 z-10 bg-slate-900 p-4 font-black text-white">合計</th>
                                    @foreach ($report['marketplaces'] as $marketplace)
                                        @php($total = $report['marketplace_totals'][(string) $marketplace['id']])
                                        <td class="p-4 text-center">
                                            <p class="font-black text-white">¥{{ number_format($total['sales']) }}</p>
                                            <p class="mt-1 text-xs font-bold text-slate-300">{{ number_format($total['sale_count']) }}件 / 手数料 ¥{{ number_format($total['sales_fee']) }} / 利益 ¥{{ number_format($total['profit']) }}</p>
                                        </td>
                                    @endforeach
                                    <td class="bg-cyan-950/75 p-4 text-center">
                                        <p class="font-black text-cyan-100">¥{{ number_format($report['total_metrics']['sales']) }}</p>
                                        <p class="mt-1 text-xs font-bold text-cyan-100">{{ number_format($report['total_metrics']['sale_count']) }}件 / 手数料 ¥{{ number_format($report['total_metrics']['sales_fee']) }} / 利益 ¥{{ number_format($report['total_metrics']['profit']) }}</p>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
