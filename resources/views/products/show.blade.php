<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">商品詳細</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4">
            @if (session('success'))<p class="rounded-lg bg-emerald-50 p-4 font-bold text-emerald-900">{{ session('success') }}</p>@endif
            @if (session('error'))<p class="rounded-lg border border-red-300 bg-red-50 p-4 font-bold text-red-900">{{ session('error') }}</p>@endif
            @if ($errors->any())<p class="rounded-lg border border-red-300 bg-red-50 p-4 font-bold text-red-900">{{ $errors->first() }}</p>@endif

            <section class="rounded-xl border border-red-300 bg-white p-6 shadow" aria-labelledby="delete-product-heading">
                <h2 id="delete-product-heading" class="text-lg font-black text-red-900">商品を削除</h2>
                <p class="mt-2 text-sm font-semibold leading-6 text-slate-800">削除するとFurimaDeck内の商品情報、出品情報、登録画像が削除されます。外部フリマサイトへの掲載は削除されないため、掲載中の場合は各サイトで先に取り下げてください。</p>
                @if ($canDelete)
                    <form method="POST" action="{{ route('products.destroy', $product) }}" class="mt-4 border-t border-red-200 pt-4">
                        @csrf
                        @method('DELETE')
                        <label class="flex items-start gap-2 text-sm font-bold text-red-900"><input name="confirm_deletion" value="1" type="checkbox" required class="mt-1">この商品と、登録画像およびFurimaDeck内の出品情報を削除することを確認しました。</label>
                        <button type="submit" class="mt-4 rounded-lg bg-red-700 px-4 py-2.5 font-bold text-white hover:bg-red-800">商品を削除</button>
                    </form>
                @else
                    <p class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm font-bold leading-6 text-amber-950">販売・会計履歴がある商品は、履歴を保護するため削除できません。CSV登録とは関係ありません。商品編集画面から在庫数を直接変更する機能もありません。</p>
                @endif
            </section>

            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-bold text-slate-700">商品ID: {{ $product->internal_sku }}</p>
                        <h1 class="mt-2 text-2xl font-black text-slate-950">{{ $product->product_name }}</h1>
                        <p class="mt-2 text-sm font-bold text-slate-700">{{ $product->category?->name ?? '未分類' }} ・ {{ \App\Models\Product::conditionLabel($product->condition) }}</p>
                    </div>
                    <a href="{{ route('products.edit', $product) }}" class="inline-flex rounded-lg bg-cyan-800 px-4 py-2.5 text-sm font-black text-white hover:bg-cyan-900">商品を編集</a>
                </div>

                @if ($product->images->isNotEmpty())
                    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($product->images as $image)
                            <img src="{{ route('products.images.show', ['product' => $product, 'image' => $image, 'variant' => 'thumbnail']) }}" alt="{{ $product->product_name }}の商品画像" class="aspect-square w-full rounded-lg border border-slate-200 bg-slate-100 object-contain">
                        @endforeach
                    </div>
                @endif

                <dl class="mt-6 grid gap-x-8 gap-y-4 border-t border-slate-200 pt-5 sm:grid-cols-2">
                    <div><dt class="text-sm font-bold text-slate-700">在庫数</dt><dd class="mt-1 text-base font-black text-slate-950">{{ number_format((int) $product->quantity_available) }}</dd></div>
                    <div><dt class="text-sm font-bold text-slate-700">在庫状態</dt><dd class="mt-1 text-base font-black text-slate-950">{{ \App\Models\Product::inventoryStatusLabel($product->inventory_status) }}</dd></div>
                    <div><dt class="text-sm font-bold text-slate-700">保管場所</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $product->storage_location ?: '未登録' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm font-bold text-slate-700">商品説明</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $product->description_base ?: '未登録' }}</dd></div>
                    @foreach ($product->attributeValues as $attributeValue)
                        <div><dt class="text-sm font-bold text-slate-700">{{ $attributeValue->categoryAttribute?->label ?? '商品情報' }}</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $attributeValue->value_text ?: '未登録' }}</dd></div>
                    @endforeach
                    <div class="sm:col-span-2"><dt class="text-sm font-bold text-slate-700">メモ</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $product->memo ?: '未登録' }}</dd></div>
                </dl>
            </section>

            <a href="{{ route('products.index') }}" class="inline-flex rounded-lg border border-slate-400 bg-white px-4 py-2 font-bold text-slate-900 hover:bg-slate-50">商品一覧へ戻る</a>
        </div>
    </div>
</x-app-layout>
