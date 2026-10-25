<x-app-layout>
    <div class="mx-auto max-w-3xl px-4 py-10">
        <section class="rounded-xl border border-cyan-700 bg-slate-950 p-6 text-cyan-50 shadow-xl">
            <h1 class="text-2xl font-bold">Chrome拡張の連携端末</h1>
            <p class="mt-2 text-sm text-cyan-100">紛失した端末や使わなくなった端末は、ここから連携を失効できます。</p>
            @if (session('status'))
                <p class="mt-4 rounded-lg bg-emerald-950 p-3 text-emerald-100">{{ session('status') }}</p>
            @endif
            <div class="mt-5 space-y-3">
                @forelse ($devices as $device)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-700 p-4">
                        <div>
                            <p class="font-semibold">{{ $device->name }}</p>
                            <p class="mt-1 text-xs text-slate-300">最終利用: {{ $device->last_used_at?->format('Y/m/d H:i') ?? '未使用' }}</p>
                        </div>
                        <form method="POST" action="{{ route('chrome-extension.devices.revoke', $device->id) }}">
                            @csrf
                            @method('DELETE')
                            <button class="rounded-lg border border-rose-500 px-3 py-2 text-sm font-semibold text-rose-100 hover:bg-rose-950" type="submit">連携を解除</button>
                        </form>
                    </div>
                @empty
                    <p class="rounded-lg border border-slate-700 p-4 text-slate-200">連携中の端末はありません。</p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
