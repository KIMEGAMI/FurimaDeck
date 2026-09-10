<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-cyan-200">商品一覧</h2>
                <p class="mt-1 text-sm font-bold text-cyan-100">画像、販売状況、価格、利益、売れ残り期間をまとめて確認できます。</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                <a href="{{ route('auction-items.duplicates') }}" class="inline-flex items-center justify-center rounded-xl border border-amber-200 bg-white px-5 py-3 text-sm font-bold text-black shadow transition hover:bg-amber-50">重複チェック</a>
                <a href="{{ route('auction-items.csv-import') }}" class="inline-flex items-center justify-center rounded-xl border border-cyan-200 bg-white px-5 py-3 text-sm font-bold text-black shadow transition hover:bg-cyan-50">CSV管理</a>
                <a href="{{ route('auction-items.bulk-destroy.confirm') }}" class="inline-flex items-center justify-center rounded-xl border border-red-200 bg-red-50 px-5 py-3 text-sm font-bold text-red-700 shadow transition hover:bg-red-100">商品全削除</a>
                <a href="{{ route('auction-items.create') }}" class="inline-flex items-center justify-center rounded-xl bg-blue-700 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-blue-800">商品を登録</a>
            </div>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-6 sm:py-10">
        <div class="mx-auto max-w-7xl px-4 pb-24 sm:px-6 sm:pb-0 lg:px-8">
            @foreach (['success' => 'blue', 'error' => 'red'] as $flashKey => $color)
                @if (session($flashKey))
                    <div class="mb-6 rounded-2xl border border-{{ $color }}-200 bg-{{ $color }}-50 px-6 py-5">
                        <p class="font-bold text-black">{{ session($flashKey) }}</p>
                    </div>
                @endif
            @endforeach

            <section class="mb-6 rounded-3xl border border-slate-200 bg-white p-4 shadow">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <a href="{{ route('auction-items.index', array_filter(['platform' => $platform, 'keyword' => $keyword, 'parent_category_id' => $parentCategoryId, 'category_id' => $categoryId])) }}" class="rounded-2xl px-4 py-3 text-center text-sm font-black transition {{ empty($status) && ! ($unsoldOnly ?? false) ? 'bg-blue-700 text-white shadow' : 'bg-slate-100 text-black hover:bg-slate-200' }}">すべて</a>
                    <a href="{{ route('auction-items.index', array_filter(['status' => 'selling', 'platform' => $platform, 'keyword' => $keyword, 'parent_category_id' => $parentCategoryId, 'category_id' => $categoryId])) }}" class="rounded-2xl px-4 py-3 text-center text-sm font-black transition {{ $status === 'selling' ? 'bg-blue-700 text-white shadow' : 'bg-slate-100 text-black hover:bg-slate-200' }}">出品中</a>
                    <a href="{{ route('auction-items.index', array_filter(['status' => 'sold', 'platform' => $platform, 'keyword' => $keyword, 'parent_category_id' => $parentCategoryId, 'category_id' => $categoryId])) }}" class="rounded-2xl px-4 py-3 text-center text-sm font-black transition {{ $status === 'sold' ? 'bg-red-600 text-white shadow' : 'bg-slate-100 text-black hover:bg-slate-200' }}">SOLD</a>
                    <a href="{{ route('auction-items.index', array_filter(['unsold' => '1', 'unsold_before' => $unsoldBeforeInput, 'platform' => $platform, 'keyword' => $keyword, 'parent_category_id' => $parentCategoryId, 'category_id' => $categoryId])) }}" class="rounded-2xl px-4 py-3 text-center text-sm font-black transition {{ ($unsoldOnly ?? false) ? 'bg-amber-500 text-white shadow' : 'bg-slate-100 text-black hover:bg-slate-200' }}">{{ $unsoldFilterLabel ?? '未売却' }}</a>
                </div>
            </section>

            <section class="mb-8 rounded-3xl border border-slate-200 bg-white p-5 shadow sm:p-6">
                <form method="GET" action="{{ route('auction-items.index') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    <div class="lg:col-span-3">
                        <label for="platform" class="block text-sm font-black text-slate-700">出品先</label>
                        <select id="platform" name="platform" class="mt-2 w-full rounded-xl border-slate-300 text-black shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">すべて</option>
                            @foreach ($platforms as $platformName)
                                <option value="{{ $platformName }}" @selected($platform === $platformName)>{{ $platformName }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="lg:col-span-6">
                        @include('auction_items.partials.category-selects-v2', [
                            'parentCategories' => $parentCategories,
                            'parentSelectId' => 'filter_parent_category_id',
                            'categorySelectId' => 'filter_category_id',
                            'parentPlaceholder' => 'すべて',
                            'categoryPlaceholder' => 'すべて',
                            'selectedParentId' => $parentCategoryId,
                            'selectedCategoryId' => $categoryId,
                        ])
                    </div>

                    <div class="lg:col-span-6">
                        <label for="keyword" class="block text-sm font-black text-slate-700">キーワード</label>
                        <input id="keyword" type="text" name="keyword" value="{{ $keyword }}" placeholder="管理ID・タイトル・コメントで検索" class="mt-2 w-full rounded-xl border-slate-300 text-black shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <input type="hidden" name="status" value="{{ $status }}">
                    @if ($unsoldOnly ?? false)
                        <input type="hidden" name="unsold" value="1">
                    @endif

                    <div class="lg:col-span-3">
                        <label for="unsold_before" class="block text-sm font-black text-slate-700">未売却の基準日</label>
                        <input id="unsold_before" type="text" name="unsold_before" value="{{ $unsoldBeforeInput ?? '' }}" inputmode="numeric" placeholder="例 20260723" class="mt-2 w-full rounded-xl border-slate-300 text-black shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs font-semibold text-slate-500">この日以前に登録した出品中商品だけを表示します。</p>
                    </div>

                    <div class="lg:col-span-3">
                        <label for="unsold_before_calendar" class="block text-sm font-black text-slate-700">カレンダーで選択</label>
                        <input id="unsold_before_calendar" type="date" value="{{ $unsoldBeforeDate ?? '' }}" class="mt-2 w-full rounded-xl border-slate-300 text-black shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div class="flex items-end gap-3 lg:col-span-3">
                        <button type="submit" class="w-full rounded-xl bg-blue-700 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-blue-800">検索</button>
                        <a href="{{ route('auction-items.index') }}" class="w-full rounded-xl bg-slate-200 px-5 py-3 text-center text-sm font-bold text-black transition hover:bg-slate-300">解除</a>
                    </div>
                </form>
            </section>

            @if (($inventoryAlerts['selling_count'] ?? 0) > 0)
                <section class="mb-8 rounded-3xl border border-amber-200 bg-white p-5 shadow">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-black tracking-widest text-amber-700">UNSOLD ALERT</p>
                            <h3 class="mt-2 text-xl font-black text-slate-950">売れ残りアラート</h3>
                            <p class="mt-2 text-sm font-bold leading-6 text-slate-700">{{ $inventoryAlerts['message'] }}</p>
                        </div>
                        <a href="{{ route('auction-items.unsold-alerts') }}" class="inline-flex items-center justify-center rounded-xl bg-amber-600 px-5 py-3 text-sm font-black text-white shadow hover:bg-amber-700">
                            アラート商品一覧を見る
                        </a>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <div class="rounded-2xl bg-amber-50 p-4">
                            <p class="text-xs font-black text-amber-800">14日以上</p>
                            <p class="mt-1 text-2xl font-black text-slate-950">{{ number_format($inventoryAlerts['older_than_14_count']) }}件</p>
                        </div>
                        <div class="rounded-2xl bg-red-50 p-4">
                            <p class="text-xs font-black text-red-700">30日以上</p>
                            <p class="mt-1 text-2xl font-black text-slate-950">{{ number_format($inventoryAlerts['older_than_30_count']) }}件</p>
                        </div>
                        <div class="rounded-2xl bg-slate-100 p-4">
                            <p class="text-xs font-black text-slate-700">出品中仕入れ額</p>
                            <p class="mt-1 text-xl font-black text-slate-950">¥{{ number_format($inventoryAlerts['total_cost']) }}</p>
                        </div>
                        <div class="rounded-2xl bg-slate-100 p-4">
                            <p class="text-xs font-black text-slate-700">30日以上の仕入れ額</p>
                            <p class="mt-1 text-xl font-black text-slate-950">¥{{ number_format($inventoryAlerts['stale_cost']) }}</p>
                            <p class="mt-1 text-xs font-bold text-slate-600">{{ number_format($inventoryAlerts['stale_cost_rate'], 1) }}%</p>
                        </div>
                    </div>
                </section>
            @endif

            @if ($auctionItems->count() > 0)
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($auctionItems as $item)
                        @php
                            $displayImagePath = $item->status === 'sold' && $item->sold_image_path ? $item->sold_image_path : $item->image_path;
                            $platformName = \App\Models\AuctionItem::normalizePlatformName($item->platform) ?: '未設定';
                            $purchasePrice = (int) ($item->purchase_price ?? 0);
                            $soldPrice = (int) ($item->sold_price ?? 0);
                            $salesFeeRate = (float) ($item->sales_fee_rate ?? 0);
                            $salesFee = (int) ($item->sales_fee ?? round($soldPrice * ($salesFeeRate / 100)));
                            $shippingFee = (int) ($item->shipping_fee ?? 0);
                            $profit = $item->status === 'sold' ? (int) ($item->profit ?? ($soldPrice - $purchasePrice - $salesFee - $shippingFee)) : ($soldPrice - $purchasePrice - $salesFee - $shippingFee);
                            $categoryLabel = $item->category
                                ? collect([$item->category->parent?->parent?->name, $item->category->parent?->name, $item->category->name])->filter()->implode(' / ')
                                : '未設定';
                            $daysListed = $item->created_at ? max(0, (int) $item->created_at->diffInDays(now())) : 0;
                            $statusLabel = $item->status === 'sold' ? 'SOLD' : '出品中';
                        @endphp

                        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow">
                            <a href="{{ route('auction-items.show', $item) }}" class="block">
                                <div class="relative aspect-[4/3] bg-slate-200">
                                    @if ($displayImagePath)
                                        <img src="{{ asset('storage/' . $displayImagePath) }}" alt="{{ $item->title }}" class="h-full w-full object-cover">
                                    @else
                                        <div class="flex h-full w-full items-center justify-center bg-slate-200 font-black text-black">NO IMAGE</div>
                                    @endif
                                    <div class="absolute left-3 right-3 top-3 flex flex-wrap gap-2">
                                        <span class="rounded-full {{ $item->status === 'sold' ? 'bg-red-600' : 'bg-blue-700' }} px-3 py-1.5 text-xs font-black text-white shadow">{{ $statusLabel }}</span>
                                        <span class="rounded-full bg-white px-3 py-1.5 text-xs font-black text-black shadow">{{ $platformName }}</span>
                                        @if ($item->status !== 'sold' && ($unsoldOnly ?? false))
                                            <span class="rounded-full bg-amber-100 px-3 py-1.5 text-xs font-black text-black shadow">未売却 {{ $daysListed }}日</span>
                                        @endif
                                    </div>
                                </div>
                            </a>

                            <div class="p-5">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-black tracking-widest text-slate-700">{{ $item->management_id }}</p>
                                    <p class="text-xs font-black text-slate-700">{{ $categoryLabel }}</p>
                                </div>
                                <h3 class="mt-3 line-clamp-2 min-h-[3.5rem] text-lg font-black leading-7 text-slate-950">{{ $item->title }}</h3>
                                <p class="mt-2 line-clamp-2 min-h-[3rem] text-sm font-semibold leading-6 text-slate-700">{{ $item->comment ?: 'コメントはありません。' }}</p>

                                <div class="mt-5 grid grid-cols-2 gap-2 rounded-2xl border border-slate-100 bg-slate-50 p-4 text-sm">
                                    <div><p class="text-xs font-black text-slate-600">仕入れ</p><p class="mt-1 font-black text-black">¥{{ number_format($purchasePrice) }}</p></div>
                                    <div><p class="text-xs font-black text-slate-600">販売価格</p><p class="mt-1 font-black text-black">¥{{ number_format($soldPrice) }}</p></div>
                                    <div><p class="text-xs font-black text-slate-600">送料・手数料</p><p class="mt-1 font-black text-black">¥{{ number_format($shippingFee + $salesFee) }}</p></div>
                                    <div><p class="text-xs font-black text-slate-600">見込み/実利益</p><p class="mt-1 font-black {{ $profit < 0 ? 'text-red-700' : 'text-green-700' }}">¥{{ number_format($profit) }}</p></div>
                                </div>

                                <div class="mt-5 grid grid-cols-2 gap-2">
                                    <a href="{{ route('auction-items.show', $item) }}" class="rounded-xl bg-slate-100 px-4 py-3 text-center text-sm font-bold text-black transition hover:bg-slate-200">詳細</a>
                                    <a href="{{ route('auction-items.edit', $item) }}" class="rounded-xl bg-blue-700 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-blue-800">編集</a>
                                </div>

                                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    @if ($item->status !== 'sold')
                                        <form action="{{ route('auction-items.sold', $item) }}" method="POST" onsubmit="return confirm('この商品をSOLDにしますか？')">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="w-full rounded-xl bg-amber-500 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-amber-600">SOLDにする</button>
                                        </form>
                                    @else
                                        <form action="{{ route('auction-items.selling', $item) }}" method="POST" onsubmit="return confirm('この商品を出品中に戻しますか？')">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="w-full rounded-xl bg-blue-700 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-blue-800">出品中に戻す</button>
                                        </form>
                                    @endif

                                    <form action="{{ route('auction-items.destroy', $item) }}" method="POST" onsubmit="return confirm('この商品を削除しますか？画像も削除されます。')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="w-full rounded-xl bg-red-600 px-4 py-3 text-center text-sm font-bold text-white transition hover:bg-red-700">削除</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="mt-8">{{ $auctionItems->links('vendor.pagination.furupro') }}</div>
            @else
                <div class="rounded-3xl border border-slate-200 bg-white p-12 text-center shadow">
                    <h3 class="text-2xl font-black text-slate-900">表示できる商品がありません</h3>
                    <p class="mt-3 font-semibold text-slate-700">条件を変更するか、新しい商品を登録してください。</p>
                    <a href="{{ route('auction-items.create') }}" class="mt-8 inline-flex items-center justify-center rounded-xl bg-blue-700 px-6 py-4 text-sm font-bold text-white shadow transition hover:bg-blue-800">商品を登録</a>
                </div>
            @endif
        </div>

        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-cyan-200 bg-white/95 px-3 py-3 shadow-2xl backdrop-blur md:hidden" aria-label="スマートフォン操作">
            <div class="grid grid-cols-3 gap-2">
                <a href="{{ route('auction-items.create') }}" class="rounded-xl bg-blue-700 px-3 py-3 text-center text-xs font-black text-white shadow">商品登録</a>
                <a href="{{ route('auction-items.index', ['status' => 'selling']) }}" class="rounded-xl bg-slate-100 px-3 py-3 text-center text-xs font-black text-black shadow">出品中</a>
                <a href="{{ route('auction-items.csv-import') }}" class="rounded-xl bg-cyan-300 px-3 py-3 text-center text-xs font-black text-slate-950 shadow">CSV管理</a>
            </div>
        </nav>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const dateInput = document.getElementById('unsold_before');
            const calendarInput = document.getElementById('unsold_before_calendar');

            calendarInput?.addEventListener('change', function () {
                if (!calendarInput.value || !dateInput) {
                    return;
                }

                dateInput.value = calendarInput.value.replaceAll('-', '');
            });

            dateInput?.addEventListener('input', function () {
                const compactDate = dateInput.value.replace(/[^\d]/g, '');

                if (compactDate.length === 8 && calendarInput) {
                    calendarInput.value = compactDate.slice(0, 4) + '-' + compactDate.slice(4, 6) + '-' + compactDate.slice(6, 8);
                }
            });
        });
    </script>
</x-app-layout>
