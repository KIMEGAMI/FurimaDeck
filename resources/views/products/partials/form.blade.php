@if ($errors->any())<div class="mb-5 rounded bg-red-50 p-4 font-bold text-red-800">{{ $errors->first() }}</div>@endif
@php($editing = isset($product))
@php($purchaseDate = $editing && $product->purchase_date ? $product->purchase_date->format('Y-m-d') : '')
@php($remainingImageSlots = max(0, (int) config('furimadeck.product_images.max_count') - ($editing ? $product->images->count() : 0)))
<div class="grid gap-5 md:grid-cols-2">
<label class="block text-sm font-bold text-slate-700">SKU<input required name="internal_sku" value="{{ old('internal_sku', $product->internal_sku ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">商品名<input required name="product_name" value="{{ old('product_name', $product->product_name ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">カテゴリ<select id="category_id" name="category_id" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">未分類</option>@foreach($categories as $root)<optgroup label="{{ $root->name }}">@foreach($root->children as $child)<option value="{{ $child->id }}" @selected(old('category_id', $product->category_id ?? '') == $child->id)>{{ $child->name }}</option>@foreach($child->children as $leaf)<option value="{{ $leaf->id }}" @selected(old('category_id', $product->category_id ?? '') == $leaf->id)>&nbsp;&nbsp;{{ $leaf->name }}</option>@endforeach@endforeach</optgroup>@endforeach</select></label>
<label class="block text-sm font-bold text-slate-700">商品状態<select name="condition" class="mt-1 w-full rounded border-slate-300 text-slate-900">@foreach($conditions as $condition)<option value="{{ $condition }}" @selected(old('condition', $product->condition ?? 'used') === $condition)>{{ \App\Models\Product::conditionLabel($condition) }}</option>@endforeach</select></label>
<label class="block text-sm font-bold text-slate-700">仕入先<select name="supplier_id" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">未設定</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id', $product->supplier_id ?? '') == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
<label class="block text-sm font-bold text-slate-700">仕入日<input type="date" name="purchase_date" value="{{ old('purchase_date', $purchaseDate) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">仕入単価<input required min="0" type="number" name="purchase_unit_cost" value="{{ old('purchase_unit_cost', $product->purchase_unit_cost ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">仕入数量<input required min="1" type="number" name="purchase_quantity" value="{{ old('purchase_quantity', $product->purchase_quantity ?? 1) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">在庫数<input required min="0" type="number" name="quantity_available" value="{{ old('quantity_available', $product->quantity_available ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">仕入送料<input min="0" type="number" name="purchase_shipping_cost" value="{{ old('purchase_shipping_cost', $product->purchase_shipping_cost ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">その他仕入費用<input min="0" type="number" name="other_purchase_expense" value="{{ old('other_purchase_expense', $product->other_purchase_expense ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">在庫状態<select name="inventory_status" class="mt-1 w-full rounded border-slate-300 text-slate-900">@foreach($inventoryStatuses as $status)<option value="{{ $status }}" @selected(old('inventory_status', $product->inventory_status ?? 'draft') === $status)>{{ \App\Models\Product::inventoryStatusLabel($status) }}</option>@endforeach</select></label>
<label class="block text-sm font-bold text-slate-700">保管場所<input name="storage_location" value="{{ old('storage_location', $product->storage_location ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">JAN / EAN<input name="jan_ean" value="{{ old('jan_ean', $product->jan_ean ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">ISBN<input name="isbn" value="{{ old('isbn', $product->isbn ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700 md:col-span-2">メーカー・型番<input name="manufacturer_model_number" value="{{ old('manufacturer_model_number', $product->manufacturer_model_number ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700 md:col-span-2">シリアル番号<input name="serial_number" value="{{ old('serial_number', $product->serial_number ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
</div>
<label class="mt-5 block text-sm font-bold text-slate-700">基本説明<textarea name="description_base" rows="5" class="mt-1 w-full rounded border-slate-300 text-slate-900">{{ old('description_base', $product->description_base ?? '') }}</textarea></label>
<label class="mt-5 block text-sm font-bold text-slate-700">メモ<textarea name="memo" rows="4" class="mt-1 w-full rounded border-slate-300 text-slate-900">{{ old('memo', $product->memo ?? '') }}</textarea></label>
<section class="mt-5 rounded border border-slate-200 bg-slate-50 p-4">
    <h3 class="font-black text-slate-800">カテゴリ別属性</h3>
    <div id="category-attributes" class="mt-3 grid gap-4 md:grid-cols-2">
        @foreach($categoryAttributes as $attribute)
            @php($existingValue = $editing ? $product->attributeValues->firstWhere('category_attribute_id', $attribute->id)?->value_text : null)
            <label class="block text-sm font-bold text-slate-700">{{ $attribute->label }}<input type="hidden" name="attributes[{{ $loop->index }}][id]" value="{{ $attribute->id }}"><input name="attributes[{{ $loop->index }}][value_text]" value="{{ old('attributes.'.$loop->index.'.value_text', $existingValue) }}" @required($attribute->is_required) class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
        @endforeach
    </div>
</section>
<section class="mt-5"><p class="text-sm font-bold text-slate-700">商品画像（JPEG / PNG / WEBP、合計最大{{ config('furimadeck.product_images.max_count') }}枚、追加は{{ $remainingImageSlots }}枚まで、各{{ config('furimadeck.product_images.max_size_kilobytes') / 1024 }}MB）</p><input id="product-images" type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="sr-only"><input id="product-image-folder" type="file" webkitdirectory directory multiple accept="image/jpeg,image/png,image/webp" class="sr-only"><div id="product-image-drop-zone" class="mt-2 rounded border-2 border-dashed border-slate-300 bg-slate-50 p-6 text-center text-slate-700"><p class="font-bold">画像をドラッグ＆ドロップ</p><div class="mt-3 flex flex-wrap justify-center gap-2"><button id="product-image-choose" type="button" class="rounded bg-slate-800 px-4 py-2 text-sm font-bold text-white">画像を選択</button><button id="product-image-folder-choose" type="button" class="rounded border border-slate-700 px-4 py-2 text-sm font-bold text-slate-800">フォルダから選択</button></div></div><p id="product-image-error" class="mt-2 hidden font-bold text-red-700"></p><div id="product-image-preview" class="mt-3 grid grid-cols-3 gap-3 sm:grid-cols-5"></div></section>
@if($editing && $product->images->isNotEmpty())<div id="stored-product-images" data-order-url="{{ route('products.images.order', $product) }}" class="mt-3 grid grid-cols-5 gap-2">@foreach($product->images->sortBy('position') as $image)<div class="stored-product-image relative cursor-move" draggable="true" data-image-id="{{ $image->id }}"><img src="{{ route('products.images.show', ['product' => $product, 'image' => $image, 'variant' => 'thumbnail']) }}" alt="商品画像" class="aspect-square rounded object-cover"><form method="POST" action="{{ route('products.images.destroy', [$product, $image]) }}" class="absolute right-1 top-1">@csrf @method('DELETE')<button class="rounded bg-red-600 px-2 py-1 text-xs font-bold text-white" onclick="return confirm('この画像を削除しますか？')">削除</button></form></div>@endforeach</div>@endif
<script>
    document.getElementById('category_id')?.addEventListener('change', async function (event) {
        const container = document.getElementById('category-attributes');
        container.replaceChildren();

        if (!event.target.value) return;

        const response = await fetch(`{{ route('products.category-attributes') }}?category_id=${encodeURIComponent(event.target.value)}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;

        const payload = await response.json();
        payload.attributes.forEach(function (attribute, index) {
            const label = document.createElement('label');
            label.className = 'block text-sm font-bold text-slate-700';
            label.textContent = attribute.label;
            const id = document.createElement('input');
            id.type = 'hidden'; id.name = `attributes[${index}][id]`; id.value = attribute.id;
            const input = document.createElement('input');
            input.name = `attributes[${index}][value_text]`; input.required = attribute.is_required;
            input.className = 'mt-1 w-full rounded border-slate-300 text-slate-900';
            label.append(id, input); container.append(label);
        });
    });
</script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const input = document.getElementById('product-images'); const folderInput = document.getElementById('product-image-folder'); const zone = document.getElementById('product-image-drop-zone'); const choose = document.getElementById('product-image-choose'); const chooseFolder = document.getElementById('product-image-folder-choose'); const preview = document.getElementById('product-image-preview'); const error = document.getElementById('product-image-error'); const files = [];
        const maximumCount = {{ $remainingImageSlots }}; const maximumBytes = {{ (int) config('furimadeck.product_images.max_size_kilobytes') * 1024 }}; const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        function sync() { const transfer = new DataTransfer(); files.forEach(entry => transfer.items.add(entry)); input.files = transfer.files; }
        function render() { preview.replaceChildren(); files.forEach((file, index) => { const card = document.createElement('div'); card.draggable = true; const image = document.createElement('img'); image.src = URL.createObjectURL(file); image.className = 'aspect-square w-full rounded object-cover'; image.alt = `商品画像 ${index + 1}`; card.append(image); card.addEventListener('dragstart', event => event.dataTransfer.setData('text/plain', index)); card.addEventListener('dragover', event => event.preventDefault()); card.addEventListener('drop', event => { event.preventDefault(); const from = Number(event.dataTransfer.getData('text/plain')); const [moved] = files.splice(from, 1); files.splice(index, 0, moved); sync(); render(); }); preview.append(card); }); }
        function add(selected) { error.classList.add('hidden'); Array.from(selected).forEach(file => { if (!allowed.includes(file.type) || file.size > maximumBytes || files.length >= maximumCount) { error.textContent = '画像形式、容量、または枚数が上限を超えています。'; error.classList.remove('hidden'); return; } files.push(file); }); sync(); render(); }
        choose.addEventListener('click', () => input.click()); chooseFolder.addEventListener('click', () => folderInput.click()); input.addEventListener('change', () => add(input.files)); folderInput.addEventListener('change', () => add(folderInput.files)); ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, event => { event.preventDefault(); zone.classList.add('border-cyan-500'); })); ['dragleave', 'drop'].forEach(type => zone.addEventListener(type, event => { event.preventDefault(); zone.classList.remove('border-cyan-500'); })); zone.addEventListener('drop', event => add(event.dataTransfer.files));

        const storedImages = document.getElementById('stored-product-images');
        if (storedImages) {
            let draggedImage = null;
            storedImages.querySelectorAll('.stored-product-image').forEach(card => {
                card.addEventListener('dragstart', () => { draggedImage = card; });
                card.addEventListener('dragover', event => event.preventDefault());
                card.addEventListener('drop', async event => {
                    event.preventDefault();
                    if (!draggedImage || draggedImage === card) return;

                    const cards = Array.from(storedImages.children);
                    if (cards.indexOf(draggedImage) < cards.indexOf(card)) card.after(draggedImage); else card.before(draggedImage);
                    const response = await fetch(storedImages.dataset.orderUrl, {
                        method: 'PATCH',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content },
                        body: JSON.stringify({ image_ids: Array.from(storedImages.children).map(image => Number(image.dataset.imageId)) }),
                    });
                    if (!response.ok) window.location.reload();
                });
            });
        }
    });
</script>
