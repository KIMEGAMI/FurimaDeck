<div class="overflow-x-auto rounded-xl bg-white shadow-sm">
    <table class="min-w-max text-left text-sm text-slate-800">
        <thead class="bg-slate-100 text-xs text-slate-600"><tr><th class="whitespace-nowrap px-4 py-3">{{ $label }}</th><th class="whitespace-nowrap px-4 py-3 text-right">売上</th><th class="whitespace-nowrap px-4 py-3 text-right">販売手数料</th><th class="whitespace-nowrap px-4 py-3 text-right">実利益</th><th class="whitespace-nowrap px-4 py-3 text-right">件数</th><th class="whitespace-nowrap px-4 py-3 text-right">構成比</th><th class="whitespace-nowrap px-4 py-3 text-right">利益率</th></tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-t border-slate-100"><td class="whitespace-nowrap px-4 py-3 font-bold">{{ $row['name'] }}</td><td class="whitespace-nowrap px-4 py-3 text-right">¥{{ number_format($row['sales_total']) }}</td><td class="whitespace-nowrap px-4 py-3 text-right">¥{{ number_format($row['sales_fee_total']) }}</td><td class="whitespace-nowrap px-4 py-3 text-right">¥{{ number_format($row['profit_total']) }}</td><td class="whitespace-nowrap px-4 py-3 text-right">{{ number_format($row['sale_count']) }}</td><td class="whitespace-nowrap px-4 py-3 text-right">{{ number_format($row['share'], 1) }}%</td><td class="whitespace-nowrap px-4 py-3 text-right">{{ number_format($row['profit_margin'], 1) }}%</td></tr>
            @empty
                <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">この月の販売データはありません。</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
