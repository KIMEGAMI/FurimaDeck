<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-blue-400">出品詳細</h2>
                <p class="mt-1 text-sm text-cyan-200">商品情報・販売状況・実利益を確認できます。</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a href="{{ route('auction-items.edit', $auctionItem) }}" class="inline-flex items-center justify-center rounded-xl bg-blue-700 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-blue-800">
                    編集する
                </a>
                <a href="{{ route('auction-items.index') }}" class="inline-flex items-center justify-center rounded-xl bg-slate-200 px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-300">
                    一覧へ戻る
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $platformName = \App\Models\AuctionItem::normalizePlatformName($auctionItem->platform) ?: '未設定';
        $displayImagePath = $auctionItem->status === 'sold' && $auctionItem->sold_image_path ? $auctionItem->sold_image_path : $auctionItem->image_path;
        $purchasePrice = (int) ($auctionItem->purchase_price ?? 0);
        $soldPrice = (int) ($auctionItem->sold_price ?? 0);
        $salesFeeRate = (float) ($auctionItem->sales_fee_rate ?? 0);
        $salesFee = (int) ($auctionItem->sales_fee ?? round($soldPrice * ($salesFeeRate / 100)));
        $shippingFee = (int) ($auctionItem->shipping_fee ?? 0);
        $profit = (int) ($auctionItem->profit ?? ($soldPrice - $purchasePrice - $salesFee - $shippingFee));
        $categoryLabel = $auctionItem->category
            ? collect([$auctionItem->category->parent?->parent?->name, $auctionItem->category->parent?->name, $auctionItem->category->name])->filter()->implode(' / ')
            : '未設定';
    @endphp

    <div class="min-h-screen bg-slate-100 py-10">
        <div class="mx-auto max-w-6xl px-4 pb-24 sm:px-6 sm:pb-0 lg:px-8">
            @if(session('success'))
                <div class="mb-6 rounded-2xl border border-blue-200 bg-blue-50 px-6 py-5">
                    <p class="font-bold text-blue-700">{{ session('success') }}</p>
                </div>
            @endif

            <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
                <div class="grid grid-cols-1 lg:grid-cols-2">
                    <div class="relative bg-slate-200">
                        @if($displayImagePath)
                            <img src="{{ asset('storage/' . $displayImagePath) }}" alt="{{ $auctionItem->title }}" class="h-full min-h-[420px] w-full object-cover">
                        @else
                            <div class="flex min-h-[420px] w-full items-center justify-center bg-slate-200 font-black text-slate-700">NO IMAGE</div>
                        @endif

                        <div class="absolute left-5 right-5 top-5 flex flex-wrap gap-2">
                            <span class="rounded-full {{ $auctionItem->status === 'sold' ? 'bg-red-600' : 'bg-blue-700' }} px-4 py-2 text-xs font-black text-white shadow">
                                {{ $auctionItem->status === 'sold' ? 'SOLD' : '出品中' }}
                            </span>
                            <span class="rounded-full bg-slate-800 px-4 py-2 text-xs font-black text-white shadow">{{ $platformName }}</span>
                        </div>
                    </div>

                    <div class="p-8">
                        <p class="text-xs font-black tracking-widest text-slate-700">{{ $auctionItem->management_id }}</p>
                        <h3 class="mt-3 text-3xl font-black leading-tight text-slate-900">{{ $auctionItem->title }}</h3>
                        <p class="mt-3 inline-flex rounded-full bg-slate-100 px-4 py-2 text-sm font-black text-slate-800 shadow">
                            ジャンル: {{ $categoryLabel }}
                        </p>
                        <p class="mt-5 whitespace-pre-line text-sm leading-7 text-slate-700">{{ $auctionItem->comment ?: 'コメントはありません。' }}</p>

                        <div class="mt-8 rounded-3xl bg-slate-50 p-6 shadow">
                            <h4 class="text-lg font-black text-slate-900">収支情報</h4>
                            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div class="rounded-2xl bg-white p-4"><p class="text-xs font-black text-slate-500">仕入れ値</p><p class="mt-2 text-xl font-black text-slate-900">¥{{ number_format($purchasePrice) }}</p></div>
                                <div class="rounded-2xl bg-white p-4"><p class="text-xs font-black text-slate-500">売値</p><p class="mt-2 text-xl font-black text-slate-900">¥{{ number_format($soldPrice) }}</p></div>
                                <div class="rounded-2xl bg-white p-4"><p class="text-xs font-black text-slate-500">販売手数料</p><p class="mt-2 text-xl font-black text-slate-900">¥{{ number_format($salesFee) }}</p><p class="mt-1 text-xs font-bold text-slate-500">{{ rtrim(rtrim(number_format($salesFeeRate, 2), '0'), '.') }}%</p></div>
                                <div class="rounded-2xl bg-white p-4"><p class="text-xs font-black text-slate-500">送料</p><p class="mt-2 text-xl font-black text-slate-900">¥{{ number_format($shippingFee) }}</p></div>
                            </div>

                            <div class="mt-5 rounded-2xl {{ $profit < 0 ? 'bg-red-100' : 'bg-green-100' }} p-5">
                                <p class="text-xs font-black {{ $profit < 0 ? 'text-red-700' : 'text-green-700' }}">実利益</p>
                                <p class="mt-2 text-3xl font-black {{ $profit < 0 ? 'text-red-700' : 'text-green-700' }}">¥{{ number_format($profit) }}</p>
                                <p class="mt-2 text-xs font-bold text-slate-600">売値 - 仕入れ値 - 販売手数料 - 送料</p>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                            @if($auctionItem->status !== 'sold')
                                <form action="{{ route('auction-items.sold', $auctionItem) }}" method="POST" onsubmit="return confirm('この商品をSOLDにしますか？')" class="flex-1">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="w-full rounded-xl bg-amber-500 px-5 py-3 text-sm font-black text-white shadow transition hover:bg-amber-600">SOLDにする</button>
                                </form>
                            @else
                                <form action="{{ route('auction-items.selling', $auctionItem) }}" method="POST" onsubmit="return confirm('この商品を出品中に戻しますか？')" class="flex-1">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="w-full rounded-xl bg-blue-700 px-5 py-3 text-sm font-black text-white shadow transition hover:bg-blue-800">出品中に戻す</button>
                                </form>
                            @endif

                            <form action="{{ route('auction-items.destroy', $auctionItem) }}" method="POST" onsubmit="return confirm('この商品を削除しますか？画像も削除されます。')" class="flex-1">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="w-full rounded-xl bg-red-600 px-5 py-3 text-sm font-black text-white shadow transition hover:bg-red-700">削除する</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-cyan-200 bg-white/95 px-3 py-3 shadow-2xl backdrop-blur md:hidden" aria-label="スマホ操作">
            <div class="grid grid-cols-3 gap-2">
                <a href="{{ route('auction-items.index') }}" class="rounded-xl bg-slate-100 px-3 py-3 text-center text-xs font-black text-black shadow">
                    一覧
                </a>
                <a href="{{ route('auction-items.edit', $auctionItem) }}" class="rounded-xl bg-blue-700 px-3 py-3 text-center text-xs font-black text-white shadow">
                    編集
                </a>
                @if($auctionItem->status !== 'sold')
                    <form action="{{ route('auction-items.sold', $auctionItem) }}" method="POST" onsubmit="return confirm('この商品をSOLDにしますか？')">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="w-full rounded-xl bg-amber-500 px-3 py-3 text-center text-xs font-black text-white shadow">SOLD</button>
                    </form>
                @else
                    <form action="{{ route('auction-items.selling', $auctionItem) }}" method="POST" onsubmit="return confirm('この商品を出品中に戻しますか？')">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="w-full rounded-xl bg-cyan-300 px-3 py-3 text-center text-xs font-black text-slate-950 shadow">出品中</button>
                    </form>
                @endif
            </div>
        </nav>
    </div>
</x-app-layout>
