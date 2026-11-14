<section class="rounded-xl border border-cyan-200 bg-cyan-50 p-4">
    <div class="flex items-start gap-3"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-cyan-600 text-sm font-black text-white">1</span><div><h3 class="text-lg font-black text-slate-900">商品画像</h3><p class="mt-1 text-sm text-slate-700">画像なしでも通常どおり登録できます。</p></div></div>
    <div class="mt-4">
        <p class="text-sm font-bold text-slate-700">JPEG / PNG / WEBP、合計最大{{ config('furimadeck.product_images.max_count') }}枚、追加は{{ $remainingImageSlots }}枚まで、各{{ config('furimadeck.product_images.max_size_kilobytes') / 1024 }}MB</p>
        <input id="product-images" type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="sr-only">
        <input id="product-image-camera" type="file" accept="image/*" capture="environment" class="sr-only">
        <input id="product-image-folder" type="file" webkitdirectory directory multiple accept="image/jpeg,image/png,image/webp" class="sr-only">
        <div id="product-image-drop-zone" class="mt-2 rounded border-2 border-dashed border-slate-300 bg-white p-6 text-center text-slate-700"><p class="font-bold">画像をドラッグ＆ドロップ</p><div class="mt-3 flex flex-wrap justify-center gap-2"><button id="product-image-choose" type="button" class="rounded bg-slate-800 px-4 py-2 text-sm font-bold text-white">画像を選択</button><button id="product-image-folder-choose" type="button" class="rounded border border-slate-700 px-4 py-2 text-sm font-bold text-slate-800">フォルダから選択</button><button id="product-image-camera-choose" type="button" class="product-image-camera-control rounded border border-cyan-800 bg-cyan-50 px-4 py-2 text-sm font-bold text-cyan-950">写真を撮る</button></div><p class="product-image-camera-help mt-2 text-sm font-semibold text-slate-700">撮影後、写真はこの下の一覧に追加されます。</p></div>
        <p id="product-image-error" class="mt-2 hidden font-bold text-red-700"></p>
        <div id="product-image-preview" class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-5"></div>
    </div>

</section>

<style nonce="{{ Vite::cspNonce() }}">
    .product-image-camera-control { display: none; }
    .product-image-camera-help { display: none; }

    @media (max-width: 639px) {
        .product-image-camera-control { display: inline-flex; order: -1; min-height: 3rem; min-width: 100%; align-items: center; justify-content: center; font-size: 1rem; }
        .product-image-camera-help { display: block; }
        #product-image-drop-zone { padding: 1rem; }
        #product-image-preview img { min-height: 6rem; }
    }
</style>

@if($editing && $product->images->isNotEmpty())
    <div id="stored-product-images" data-order-url="{{ route('products.images.order', $product) }}" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
        @foreach($product->images->sortBy('position') as $image)
            <div class="stored-product-image relative cursor-move" draggable="true" data-image-id="{{ $image->id }}"><img src="{{ route('products.images.show', ['product' => $product, 'image' => $image, 'variant' => 'thumbnail']) }}" alt="商品画像" class="aspect-square rounded object-cover"><button type="button" class="absolute right-1 top-1 rounded bg-red-600 px-2 py-1 text-xs font-bold text-white" data-delete-image-url="{{ route('products.images.destroy', [$product, $image]) }}">削除</button></div>
        @endforeach
    </div>
@endif
