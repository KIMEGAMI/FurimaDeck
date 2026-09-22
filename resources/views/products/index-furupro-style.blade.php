<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-black text-cyan-200">商品一覧</h2>
                <p class="mt-1 text-sm font-bold text-cyan-100">画像、在庫、仕入原価、出品状況をまとめて確認できます。</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route('products.create') }}" class="inline-flex items-center justify-center rounded-xl bg-blue-700 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-blue-800">商品を登録</a>
            </div>
        </div>
    </x-slot>

    <div class="min-h-screen bg-slate-100 py-6 sm:py-10">
        <div class="mx-auto max-w-7xl px-4 pb-24 sm:px-6 sm:pb-0 lg:px-8">
            @foreach (['success' => 'emerald', 'error' => 'red'] as $flashKey => $color)
                @if (session($flashKey))
                    <div class="mb-6 rounded-2xl border border-{{ $color }}-200 bg-{{ $color }}-50 px-6 py-5 font-bold text-{{ $color }}-800">{{ session($flashKey) }}</div>
                @endif
            @endforeach

            <section class="mb-6 rounded-3xl border border-slate-200 bg-white p-4 shadow">
                <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                    @foreach (['' => 'すべて', 'in_stock' => '在庫あり', 'out_of_stock' => '在庫なし'] as $status => $label)
                        @php
                            $isActive = ($filters['inventory_status'] ?? '') === $status;
                            $tone = $status === 'out_of_stock' ? 'bg-slate-600' : 'bg-emerald-600';
                        @endphp
                        <a href="{{ route('products.index', array_filter(['inventory_status' => $status, 'keyword' => $filters['keyword'] ?? null, 'category_id' => $filters['category_id'] ?? null, 'supplier_id' => $filters['supplier_id'] ?? null])) }}" class="rounded-2xl px-4 py-3 text-center text-sm font-black transition {{ $isActive ? $tone.' text-white shadow' : 'bg-slate-100 text-slate-900 hover:bg-slate-200' }}">{{ $label }}</a>
                    @endforeach
                    <a href="{{ route('products.index', array_filter(['inventory_status' => $filters['inventory_status'] ?? null, 'keyword' => $filters['keyword'] ?? null, 'category_id' => $filters['category_id'] ?? null, 'supplier_id' => $filters['supplier_id'] ?? null, 'stale_days' => $longTermInventoryDays])) }}" class="rounded-2xl px-4 py-3 text-center text-sm font-black transition {{ ($filters['stale_days'] ?? null) == $longTermInventoryDays ? 'bg-amber-500 text-slate-950 shadow' : 'bg-amber-50 text-amber-950 hover:bg-amber-100' }}">{{ $longTermInventoryDays }}日以上未販売</a>
                </div>
            </section>

            <section class="mb-8 rounded-3xl border border-slate-200 bg-white p-5 shadow sm:p-6">
                <form method="GET" action="{{ route('products.index') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    <div class="lg:col-span-3">
                        <label for="keyword" class="block text-sm font-black text-slate-700">キーワード</label>
                        <input id="keyword" name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="商品ID・商品名・型番で検索" class="mt-2 w-full rounded-xl border-slate-300 text-slate-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div class="lg:col-span-2">
                        <label for="category_id" class="block text-sm font-black text-slate-700">ジャンル</label>
                        <select id="category_id" name="category_id" class="mt-2 w-full rounded-xl border-slate-300 text-slate-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">すべて</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label for="supplier_id" class="block text-sm font-black text-slate-700">仕入先</label>
                        <select id="supplier_id" name="supplier_id" class="mt-2 w-full rounded-xl border-slate-300 text-slate-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">すべて</option>
                            @foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? '') == $supplier->id)>{{ $supplier->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label for="inventory_status" class="block text-sm font-black text-slate-700">在庫状態</label>
                        <select id="inventory_status" name="inventory_status" class="mt-2 w-full rounded-xl border-slate-300 text-slate-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">すべて</option>
                            @foreach (\App\Models\Product::INVENTORY_STATUSES as $status)<option value="{{ $status }}" @selected(($filters['inventory_status'] ?? '') === $status)>{{ \App\Models\Product::inventoryStatusLabel($status) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label for="stale_days" class="block text-sm font-black text-slate-700">未販売の日数</label>
                        <input id="stale_days" name="stale_days" type="number" min="1" max="{{ config('furimadeck.inventory.max_filter_days') }}" value="{{ $filters['stale_days'] ?? '' }}" placeholder="例: 30" class="mt-2 w-full rounded-xl border-slate-300 text-slate-900 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs font-bold text-slate-500">出品日を基準に、30日以上売れていない在庫を絞ります。未出品の商品は対象外です。</p>
                    </div>
                    <div class="flex items-end gap-3 lg:col-span-3">
                        <button type="submit" class="w-full rounded-xl bg-blue-700 px-5 py-3 text-sm font-bold text-white shadow transition hover:bg-blue-800">検索</button>
                        <a href="{{ route('products.index') }}" class="w-full rounded-xl bg-slate-200 px-5 py-3 text-center text-sm font-bold text-slate-900 transition hover:bg-slate-300">解除</a>
                    </div>
                </form>
            </section>

            @if (($filters['stale_days'] ?? null) !== null)
                <section class="mb-6 flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="font-black text-amber-950">出品日から{{ number_format($filters['stale_days']) }}日以上、売上履歴がなく在庫が残っている商品を表示しています。</p>
                    <a href="{{ route('products.index') }}" class="rounded-lg bg-white px-4 py-2 text-sm font-black text-amber-950 shadow hover:bg-amber-100">絞り込みを解除</a>
                </section>
            @endif

            <p class="mb-4 text-sm font-black text-slate-700">該当商品 {{ number_format($products->total()) }}件</p>`r`n`r`n            @if ($products->count() > 0)
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($products as $product)
                        @php
                            $image = $product->images->first();
                            $statusClass = $product->inventory_status === 'in_stock' ? 'bg-emerald-600' : 'bg-slate-600';
                            $salesTotal = (int) $product->validSales->sum('sold_price');
                            $latestSale = $product->validSales->first();
                            $profitTotal = (int) $product->validSales->sum('net_profit');
                        @endphp
                        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow">
                            <a href="{{ route('products.edit', $product) }}" class="block">
                                <div class="relative aspect-[4/3] bg-slate-200">
                                    @if ($image)
                                        <img src="{{ route('products.images.show', ['product' => $product, 'image' => $image, 'variant' => 'thumbnail']) }}" alt="{{ $product->product_name }}" class="h-full w-full object-cover">
                                    @else
                                        <div class="flex h-full w-full items-center justify-center font-black text-slate-600">NO IMAGE</div>
                                    @endif
                                    @if ($product->isSoldOutByValidSale())
                                        <div class="absolute inset-0 flex items-center justify-center bg-black/45"><span class="rotate-[-8deg] border-4 border-red-600 bg-white px-5 py-2 text-3xl font-black tracking-widest text-red-600 shadow-lg">SOLD</span></div>
                                    @endif
                                    <div class="absolute left-3 right-3 top-3 flex flex-wrap gap-2">
                                        <span class="rounded-full {{ $statusClass }} px-3 py-1.5 text-xs font-black text-white shadow">{{ $product->isSoldOutByValidSale() ? '売却済み' : \App\Models\Product::inventoryStatusLabel($product->inventory_status) }}</span>
                                        @if ($product->category)<span class="rounded-full bg-white px-3 py-1.5 text-xs font-black text-slate-900 shadow">{{ $product->category->name }}</span>@endif
                                    </div>
                                </div>
                            </a>
                            <div class="p-5">
                                <div class="flex items-center justify-between gap-3"><p class="text-xs font-black tracking-widest text-slate-700">{{ $product->internal_sku }}</p><p class="text-xs font-black text-slate-700">{{ \App\Models\Product::conditionLabel($product->condition) }}</p></div>
                                <h3 class="mt-3 line-clamp-2 min-h-[3.5rem] text-lg font-black leading-7 text-slate-950">{{ $product->product_name }}</h3>
                                <p class="mt-2 line-clamp-2 min-h-[3rem] text-sm font-semibold leading-6 text-slate-700">{{ $product->manufacturer_model_number ?: ($product->supplier?->name ?: '仕入先は未設定です。') }}</p>
                                <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm">
                                        <p class="font-black text-emerald-950">売上データ</p>
                                        <div class="mt-3 grid grid-cols-2 gap-3">
                                            <div><p class="text-xs font-black text-emerald-800">売上合計</p><p class="mt-1 font-black text-slate-950">¥{{ number_format($salesTotal) }}</p></div>
                                            <div><p class="text-xs font-black text-emerald-800">利益合計</p><p class="mt-1 font-black text-slate-950">¥{{ number_format($profitTotal) }}</p></div>
                                            <div><p class="text-xs font-black text-emerald-800">最新の売却日</p><p class="mt-1 font-black text-slate-950">{{ $latestSale?->sold_at?->format('Y/m/d') ?? '未設定' }}</p></div>
                                            <div><p class="text-xs font-black text-emerald-800">販売先</p><p class="mt-1 truncate font-black text-slate-950">{{ $latestSale?->marketplace?->name ?? '未設定' }}</p></div>
                                        </div>
                                </div>
                                <div class="mt-5 grid grid-cols-2 gap-2 rounded-2xl border border-slate-100 bg-slate-50 p-4 text-sm">
                                    <div><p class="text-xs font-black text-slate-600">仕入原価</p><p class="mt-1 font-black text-slate-950">¥{{ number_format($product->purchase_unit_cost) }}</p></div>
                                    <div><p class="text-xs font-black text-slate-600">仕入日</p><p class="mt-1 font-black text-slate-950">{{ $product->purchase_date?->format('Y/m/d') ?? '未設定' }}</p></div>
                                    <div><p class="text-xs font-black text-slate-600">保管場所</p><p class="mt-1 truncate font-black text-slate-950">{{ $product->storage_location ?: '未設定' }}</p></div>
                                    <div><p class="text-xs font-black text-slate-600">在庫経過</p><p class="mt-1 font-black text-slate-950">登録から{{ $product->inventoryAgeLabel() }}</p></div>
                                </div>
                                <div class="mt-5 grid gap-2 sm:grid-cols-2">
                                    @if ($product->quantity_available > 0)
                                        <a href="{{ route('furimadeck-sales.create', ['product_id' => $product->id]) }}" class="rounded-xl bg-emerald-700 px-3 py-3 text-center text-sm font-bold text-white transition hover:bg-emerald-800">売上を記録</a>
                                    @endif
                                    <a href="{{ route('products.edit', $product) }}" class="rounded-xl bg-blue-700 px-3 py-3 text-center text-sm font-bold text-white transition hover:bg-blue-800">詳細・編集</a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="mt-8">{{ $products->links('vendor.pagination.furupro') }}</div>
            @else
                <div class="rounded-3xl border border-slate-200 bg-white p-12 text-center shadow">
                    <h3 class="text-2xl font-black text-slate-900">表示できる商品がありません</h3>
                    <p class="mt-3 font-semibold text-slate-700">条件を変更するか、新しい商品を登録してください。</p>
                    <a href="{{ route('products.create') }}" class="mt-8 inline-flex items-center justify-center rounded-xl bg-blue-700 px-6 py-4 text-sm font-bold text-white shadow transition hover:bg-blue-800">商品を登録</a>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
