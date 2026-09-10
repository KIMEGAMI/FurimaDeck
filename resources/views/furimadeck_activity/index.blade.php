<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">操作履歴</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8"><div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded bg-white shadow"><table class="w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr><th class="p-3">日時</th><th class="p-3">操作</th></tr></thead><tbody>@forelse($activities as $activity)<tr class="border-t border-slate-100 text-slate-800"><td class="p-3">{{ $activity->created_at?->format('Y/m/d H:i') }}</td><td class="p-3 font-bold">{{ $activity->action }}</td></tr>@empty<tr><td colspan="2" class="p-8 text-center text-slate-500">操作履歴はありません。</td></tr>@endforelse</tbody></table></div><div class="mt-5">{{ $activities->links() }}</div>
    </div></div>
</x-app-layout>
