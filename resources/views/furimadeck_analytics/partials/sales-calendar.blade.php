<article class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-black tracking-[0.18em] text-cyan-700">DAILY SALES</p>
            <h3 class="mt-1 text-sm font-black text-slate-900">{{ $month->format('Y年n月') }} 売上カレンダー</h3>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('furimadeck-analytics.index', ['month' => $month->subMonth()->format('Y-m')]) }}" aria-label="前月を表示" class="rounded border border-slate-300 px-1.5 py-0.5 text-xs font-black text-slate-700 hover:bg-slate-50">←</a>
            <span class="hidden text-xs font-bold text-slate-500 sm:inline">日別売上</span>
            <a href="{{ route('furimadeck-analytics.index', ['month' => $month->addMonth()->format('Y-m')]) }}" aria-label="翌月を表示" class="rounded border border-slate-300 px-1.5 py-0.5 text-xs font-black text-slate-700 hover:bg-slate-50">→</a>
        </div>
    </div>
    <div class="mt-2 grid grid-cols-7 gap-px text-center text-[9px] font-black text-slate-500">
        @foreach (['月', '火', '水', '木', '金', '土', '日'] as $weekday)<span>{{ $weekday }}</span>@endforeach
    </div>
    <div class="mt-1 space-y-px">
        @foreach ($calendar as $week)
            <div class="grid grid-cols-7 gap-px">
                @foreach ($week as $day)
                    <div title="{{ $day['date']->format('Y/m/d') }} 売上 ¥{{ number_format($day['sales']) }} / 実利益 ¥{{ number_format($day['profit']) }} / {{ $day['count'] }}件" class="h-8 min-w-0 overflow-hidden px-0.5 py-0.5 text-right leading-none {{ $day['in_month'] ? ($day['sales'] > 0 ? 'bg-cyan-100 text-slate-900' : 'bg-slate-50 text-slate-700') : 'bg-slate-50 text-slate-300' }}">
                        <p class="text-[9px] font-black">{{ $day['date']->day }}</p>
                        @if ($day['sales'] > 0)
                            <p class="mt-0.5 truncate text-[7px] font-black text-cyan-900">¥{{ number_format($day['sales']) }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</article>
