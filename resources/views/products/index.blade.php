<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div><h2 class="text-2xl font-black text-cyan-200">商品管理</h2><p class="mt-1 text-sm font-bold text-cyan-100">在庫、仕入先、商品状態をまとめて管理します。</p></div>
            <a href="{{ route('products.create') }}" class="rounded-lg bg-cyan-400 px-4 py-2 text-sm font-black text-slate-950 hover:bg-cyan-300">商品を登録</a>
        </div>
    </x-slot>
    <div class="min-h-screen bg-slate-100 py-8"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        @if (session('success'))<p class="mb-5 rounded-lg bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</p>@endif
        <form class="mb-6 grid gap-3 rounded-lg bg-white p-4 shadow md:grid-cols-6">
            <input name="keyword" value="{{ $filters['keyword'] ?? '' }}" placeholder="SKU・商品名・型番" class="rounded border-slate-300 text-slate-900 md:col-span-2">
            <select name="category_id" class="rounded border-slate-300 text-slate-900">
                <option value="">全カテゴリ</option>
                @foreach ($categories as $root)
                    <optgroup label="{{ $root->name }}">
                        <option value="{{ $root->id }}" @selected(($filters['category_id'] ?? '') == $root->id)>{{ $root->name }}</option>
                        @foreach ($root->children as $child)
                            <option value="{{ $child->id }}" @selected(($filters['category_id'] ?? '') == $child->id)>&nbsp;&nbsp;{{ $child->name }}</option>
                            @foreach ($child->children as $leaf)
                                <option value="{{ $leaf->id }}" @selected(($filters['category_id'] ?? '') == $leaf->id)>&nbsp;&nbsp;&nbsp;&nbsp;{{ $leaf->name }}</option>
                            @endforeach
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <select name="inventory_status" class="rounded border-slate-300 text-slate-900"><option value="">全在庫状態</option>@foreach (\App\Models\Product::INVENTORY_STATUSES as $status)<option value="{{ $status }}" @selected(($filters['inventory_status'] ?? '') === $status)>{{ \App\Models\Product::inventoryStatusLabel($status) }}</option>@endforeach</select>
            <select name="supplier_id" class="rounded border-slate-300 text-slate-900"><option value="">全仕入先</option>@foreach ($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? '') == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>
            <button class="rounded bg-slate-800 px-4 py-2 font-bold text-white">検索</button>
        </form>
        <div class="overflow-hidden rounded-lg bg-white shadow"><table class="w-full text-left text-sm text-slate-800"><thead class="bg-slate-100 text-xs"><tr><th class="p-3">画像</th><th class="p-3">SKU</th><th class="p-3">商品</th><th class="p-3">在庫</th><th class="p-3">仕入原価</th><th class="p-3">状態</th><th class="p-3"></th></tr></thead><tbody>
        @forelse ($products as $product)<tr class="border-t"><td class="p-3">@if($product->images->isNotEmpty())<img src="{{ route('products.images.show', ['product' => $product, 'image' => $product->images->first(), 'variant' => 'thumbnail']) }}" alt="{{ $product->product_name }}" class="h-12 w-12 rounded object-cover">@endif</td><td class="p-3 font-mono">{{ $product->internal_sku }}</td><td class="p-3"><p class="font-bold">{{ $product->product_name }}</p><p class="text-xs text-slate-500">{{ $product->category?->name ?? '未分類' }}</p></td><td class="p-3">{{ number_format($product->quantity_available) }}</td><td class="p-3">¥{{ number_format($product->purchase_unit_cost) }}</td><td class="p-3">{{ \App\Models\Product::inventoryStatusLabel($product->inventory_status) }}</td><td class="p-3"><a class="font-bold text-cyan-700" href="{{ route('products.edit', $product) }}">編集</a></td></tr>@empty<tr><td colspan="7" class="p-8 text-center text-slate-500">商品はまだありません。</td></tr>@endforelse
        </tbody></table></div><div class="mt-5">{{ $products->links() }}</div>
    </div></div>
</x-app-layout>
