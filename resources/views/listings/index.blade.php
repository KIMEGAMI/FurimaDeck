<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-black text-cyan-200">出品管理</h2>
                <p class="mt-1 text-sm font-bold text-cyan-100">販売先ごとの商品情報、出品価格、配送情報を管理します。</p>
            </div>
            <a href="{{ route('listings.create') }}" class="rounded-lg bg-cyan-400 px-4 py-2 font-black text-slate-950">出品情報を登録</a>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-7xl px-4">
            <div class="overflow-hidden rounded-lg bg-white shadow">
                <table class="w-full text-left text-sm text-slate-800">
                    <thead class="bg-slate-100">
                        <tr>
                            <th scope="col" class="p-3">商品</th>
                            <th scope="col" class="p-3">販売先</th>
                            <th scope="col" class="p-3">出品価格</th>
                            <th scope="col" class="p-3">状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($listings as $listing)
                            <tr class="border-t hover:bg-cyan-50">
                                <th scope="row" class="p-3 text-left font-bold">
                                    <a
                                        href="{{ route('listings.edit', $listing) }}"
                                        aria-label="{{ $listing->product->product_name }}の出品情報を編集"
                                        class="rounded text-blue-800 underline-offset-4 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
                                    >
                                        {{ $listing->product->product_name }}
                                    </a>
                                </th>
                                <td class="p-3">{{ $listing->marketplace_other_name ?: $listing->marketplace->name }}</td>
                                <td class="p-3">{{ $listing->listing_price === null ? '-' : '¥'.number_format($listing->listing_price) }}</td>
                                <td class="p-3">{{ \App\Models\Listing::statusLabel($listing->status) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="p-8 text-center text-slate-600">出品情報はまだありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-5">{{ $listings->links() }}</div>
        </div>
    </div>
</x-app-layout>
