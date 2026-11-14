@if ($errors->any())<div class="mb-5 rounded bg-red-50 p-4 font-bold text-red-800">{{ $errors->first() }}</div>@endif
@php($editing = isset($product))
@php($remainingImageSlots = max(0, (int) config('furimadeck.product_images.max_count') - ($editing ? $product->images->count() : 0)))
@php($selectedCategoryId = (string) old('category_id', $product->category_id ?? ''))
@php($selectedInitialMarketplaceId = (string) old('initial_marketplace_id', ''))
@php($categoryList = $categories->map(fn ($category) => ['id' => $category->id, 'parent_id' => $category->parent_id, 'name' => $category->name])->values())
@include('products.partials.images')
<section class="rounded-xl border border-slate-200 bg-slate-50 p-4">
<h3 class="text-lg font-black text-slate-900">基本情報</h3><p class="mt-1 text-sm text-slate-600">まずは必須項目だけで登録できます。詳細はあとから編集できます。</p>
<div class="mt-4 grid gap-5 md:grid-cols-2">
<label class="block text-sm font-bold text-slate-700">商品ID<input required name="internal_sku" value="{{ old('internal_sku', $product->internal_sku ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="block text-sm font-bold text-slate-700">商品名<input required name="product_name" value="{{ old('product_name', $product->product_name ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<div class="md:col-span-2">
    <span class="block text-sm font-bold text-slate-700">カテゴリ</span>
    <input id="category_id" name="category_id" type="hidden" value="{{ $selectedCategoryId }}">
    <div id="category-levels" class="mt-1 grid gap-3 md:grid-cols-2 xl:grid-cols-4"></div>
    <p class="mt-2 text-xs text-slate-600">大項目から順に選択してください。Yahoo!フリマと同じく、詳細カテゴリがある場合は次の選択欄が表示されます。</p>
</div>
<label class="block text-sm font-bold text-slate-700">商品状態<select name="condition" class="mt-1 w-full rounded border-slate-300 text-slate-900">@foreach($conditions as $condition)<option value="{{ $condition }}" @selected(old('condition', $product->condition ?? 'used') === $condition)>{{ \App\Models\Product::conditionLabel($condition) }}</option>@endforeach</select></label>
<label class="block text-sm font-bold text-slate-700 md:col-span-2">商品説明<textarea name="description_base" rows="5" maxlength="10000" placeholder="商品の特徴、状態、注意点などを入力してください。" class="mt-1 w-full rounded border-slate-300 text-slate-900">{{ old('description_base', $product->description_base ?? '') }}</textarea></label>
</div></section>
@unless($editing)
<section class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4" aria-labelledby="initial-purchase-info-heading">
    <h3 id="initial-purchase-info-heading" class="text-base font-black text-slate-900">仕入情報（任意）</h3>
    <p class="mt-1 text-sm leading-6 text-slate-800">仕入日・仕入単価・仕入先を商品と一緒に登録できます。商品IDは現物1点を表すため、仕入数量と初期在庫は各1点です。現在庫は入出庫履歴で管理します。</p>
    <div class="mt-3 grid gap-4 md:grid-cols-2">
        <label class="block text-sm font-bold text-slate-800">仕入日（任意）<input type="date" name="purchase_date" value="{{ old('purchase_date') }}" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"></label>
        <label class="block text-sm font-bold text-slate-800">仕入単価（任意）<input min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="purchase_unit_cost" value="{{ old('purchase_unit_cost') }}" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"><span class="mt-1 block text-xs font-normal text-slate-700">未入力の場合は未確認として保存します。0円を入力した場合は仕入単価0円として確定します。</span></label>
        <label class="block text-sm font-bold text-slate-800">仕入送料（任意）<input min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="purchase_shipping_cost" value="{{ old('purchase_shipping_cost', 0) }}" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"></label>
        <label class="block text-sm font-bold text-slate-800">その他仕入費（任意）<input min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="other_purchase_expense" value="{{ old('other_purchase_expense', 0) }}" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"></label>
        <label class="block text-sm font-bold text-slate-800 md:col-span-2">仕入先（任意）<select name="supplier_id" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"><option value="">未設定</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) old('supplier_id', '') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
    </div>
</section>
@else
<section id="purchase-info" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4" aria-labelledby="purchase-info-heading">
    <h3 id="purchase-info-heading" class="text-base font-black text-slate-900">仕入情報</h3>
    <p class="mt-1 text-sm leading-6 text-slate-800">仕入情報はこの商品に紐付けて管理します。商品IDは現物1点を表すため、仕入数量は1点です。現在庫は入出庫履歴から管理し、この画面では直接変更できません。</p>
    @if ($canEditPurchaseInfo)
        <div class="mt-3 grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-bold text-slate-800">仕入日（任意）<input type="date" name="purchase_date" value="{{ old('purchase_date', $product->purchase_date?->format('Y-m-d')) }}" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"></label>
            <label class="block text-sm font-bold text-slate-800">仕入単価<input required min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="purchase_unit_cost" value="{{ old('purchase_unit_cost', $product->purchase_unit_cost) }}" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"><span class="mt-1 block text-xs font-normal text-slate-700">0円も登録できます。</span></label>
            <label class="block text-sm font-bold text-slate-800">仕入送料（任意）<input min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="purchase_shipping_cost" value="{{ old('purchase_shipping_cost', $product->purchase_shipping_cost) }}" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"></label>
            <label class="block text-sm font-bold text-slate-800">その他仕入費（任意）<input min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="other_purchase_expense" value="{{ old('other_purchase_expense', $product->other_purchase_expense) }}" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"></label>
            <label class="block text-sm font-bold text-slate-800 md:col-span-2">仕入先（任意）<select name="supplier_id" class="mt-1 w-full rounded border-slate-300 bg-white text-slate-900"><option value="">未設定</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $product->supplier_id ?? '') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
        </div>
    @else
        <dl class="mt-3 grid gap-3 rounded border border-slate-300 bg-white p-4 text-sm text-slate-900 sm:grid-cols-2">
            <div><dt class="font-bold">仕入日</dt><dd>{{ $product->purchase_date?->format('Y/m/d') ?? '未設定' }}</dd></div>
            <div><dt class="font-bold">仕入単価</dt><dd>{{ number_format((int) $product->purchase_unit_cost) }}円</dd></div>
            <div><dt class="font-bold">仕入送料</dt><dd>{{ number_format((int) $product->purchase_shipping_cost) }}円</dd></div>
            <div><dt class="font-bold">その他仕入費</dt><dd>{{ number_format((int) $product->other_purchase_expense) }}円</dd></div>
            <div><dt class="font-bold">仕入先</dt><dd>{{ $product->supplier?->name ?? '未設定' }}</dd></div>
        </dl>
        <p class="mt-3 rounded border border-amber-400 bg-amber-50 p-3 text-sm font-bold text-amber-950">販売・会計記録があるため、仕入情報の編集を保留しています。記録との整合を確認してください。</p>
    @endif
    @foreach (['purchase_date', 'purchase_unit_cost', 'purchase_shipping_cost', 'other_purchase_expense', 'supplier_id', 'purchase_info'] as $purchaseField)
        @error($purchaseField)<p class="mt-2 text-sm font-bold text-red-800">{{ $message }}</p>@enderror
    @endforeach
</section>
@endunless
@unless($editing)
<section class="mt-4 rounded-xl border border-cyan-200 bg-cyan-50 p-4">
    <h3 class="text-base font-black text-slate-900">初回出品価格（任意）</h3>
    <p class="mt-1 text-sm text-slate-700">販売先と価格を入力すると、商品登録と同時に出品下書きを作成します。</p>
    <div class="mt-3 grid gap-4 md:grid-cols-2">
        <label class="block text-sm font-bold text-slate-700">販売先<select name="initial_marketplace_id" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">選択してください</option>@foreach($marketplaces as $marketplace)<option value="{{ $marketplace->id }}" @selected($selectedInitialMarketplaceId === (string) $marketplace->id)>{{ $marketplace->name }}</option>@endforeach</select></label>
        <label class="block text-sm font-bold text-slate-700">出品価格<input min="0" type="number" name="initial_listing_price" value="{{ old('initial_listing_price', '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"><span class="mt-1 block text-xs font-normal text-slate-600">登録後は商品詳細画面の「出品先・出品価格」から確認・追加できます。</span></label>
    </div>
</section>
@endunless
<script nonce="{{ Vite::cspNonce() }}">
    const categoryInput = document.getElementById('category_id');

    const categoryList = @json($categoryList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    const categoryLevels = document.getElementById('category-levels');
    const categoriesById = new Map(categoryList.map(function (category) { return [String(category.id), category]; }));
    const childrenByParentId = categoryList.reduce(function (groups, category) {
        const parentKey = category.parent_id === null ? 'root' : String(category.parent_id);
        groups[parentKey] ??= [];
        groups[parentKey].push(category);

        return groups;
    }, {});
    const categoryLevelNames = ['大項目', '中項目', '小項目', '詳細カテゴリ'];

    function updateCategoryId(id) {
        categoryInput.value = id;
        categoryInput.dispatchEvent(new Event('change'));
    }

    function categoryLevelName(level) {
        return categoryLevelNames[level] ?? `第${level + 1}階層`;
    }

    function categoryChildren(parentId) {
        return childrenByParentId[parentId === null ? 'root' : String(parentId)] ?? [];
    }

    function removeLevelsAfter(level) {
        while (categoryLevels.children.length > level + 1) {
            categoryLevels.lastElementChild.remove();
        }
    }

    function appendCategoryLevel(parentId, selectedId = '') {
        const level = categoryLevels.children.length;
        const options = categoryChildren(parentId);
        if (options.length === 0) return;

        const label = document.createElement('label');
        label.className = 'block text-sm font-bold text-slate-700';
        label.textContent = categoryLevelName(level);
        const select = document.createElement('select');
        select.className = 'category-level-select mt-1 w-full rounded border-slate-300 text-slate-900';
        select.dataset.level = String(level);
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = level === 0 ? '未分類' : '選択してください';
        select.append(placeholder);

        options.forEach(function (category) {
            const option = document.createElement('option');
            option.value = category.id;
            option.textContent = category.name;
            option.selected = String(category.id) === String(selectedId);
            select.append(option);
        });

        select.addEventListener('change', function () {
            const selectedCategory = categoriesById.get(this.value);
            removeLevelsAfter(level);

            if (!selectedCategory) {
                const previousSelect = level === 0 ? null : categoryLevels.children[level - 1].querySelector('select');
                updateCategoryId(previousSelect?.value ?? '');
                return;
            }

            updateCategoryId(selectedCategory.id);
            appendCategoryLevel(selectedCategory.id);
        });

        label.append(select);
        categoryLevels.append(label);
    }

    function selectedCategoryPath() {
        const path = [];
        const visited = new Set();
        let category = categoriesById.get(categoryInput.value);

        while (category && !visited.has(String(category.id))) {
            path.unshift(category);
            visited.add(String(category.id));
            category = category.parent_id === null ? null : categoriesById.get(String(category.parent_id));
        }

        return path;
    }

    const initialPath = selectedCategoryPath();
    appendCategoryLevel(null, initialPath[0]?.id ?? '');
    initialPath.forEach(function (category, index) {
        if (index < initialPath.length - 1) {
            appendCategoryLevel(category.id, initialPath[index + 1].id);
        }
    });
</script>
<script nonce="{{ Vite::cspNonce() }}">
    document.addEventListener('DOMContentLoaded', function () {
        const input = document.getElementById('product-images'); const folderInput = document.getElementById('product-image-folder'); const cameraInput = document.getElementById('product-image-camera'); const zone = document.getElementById('product-image-drop-zone'); const choose = document.getElementById('product-image-choose'); const chooseFolder = document.getElementById('product-image-folder-choose'); const chooseCamera = document.getElementById('product-image-camera-choose'); const preview = document.getElementById('product-image-preview'); const error = document.getElementById('product-image-error'); const files = [];
        const maximumCount = {{ $remainingImageSlots }}; const maximumBytes = {{ (int) config('furimadeck.product_images.max_size_kilobytes') * 1024 }}; const maximumSourcePixels = {{ (int) config('furimadeck.product_images.max_source_pixels') }}; const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        function sync() { const transfer = new DataTransfer(); files.forEach(entry => transfer.items.add(entry)); input.files = transfer.files; }
        function render() { preview.querySelectorAll('img').forEach(image => URL.revokeObjectURL(image.src)); preview.replaceChildren(); files.forEach((file, index) => { const card = document.createElement('div'); card.draggable = true; const image = document.createElement('img'); image.src = URL.createObjectURL(file); image.className = 'aspect-square w-full rounded object-cover'; image.alt = `商品画像 ${index + 1}`; card.append(image); card.addEventListener('dragstart', event => event.dataTransfer.setData('text/plain', index)); card.addEventListener('dragover', event => event.preventDefault()); card.addEventListener('drop', event => { event.preventDefault(); const from = Number(event.dataTransfer.getData('text/plain')); const [moved] = files.splice(from, 1); files.splice(index, 0, moved); sync(); render(); }); preview.append(card); }); }
        function add(selected) { error.classList.add('hidden'); Array.from(selected).forEach(file => { if (!allowed.includes(file.type) || file.size > maximumBytes || files.length >= maximumCount) { error.textContent = '画像形式、容量、または枚数が上限を超えています。'; error.classList.remove('hidden'); return; } files.push(file); }); sync(); render(); }
        async function prepareCapturedPhoto(file) {
            if (allowed.includes(file.type) && file.size <= maximumBytes) return file;

            try {
                const bitmap = await createImageBitmap(file);
                const scale = Math.min(1, Math.sqrt(maximumSourcePixels / (bitmap.width * bitmap.height)));
                const maximumCompressionAttempts = 8;
                const jpegQuality = {{ min(90, max(50, (int) config('furimadeck.product_images.webp_quality'))) / 100 }};
                let compressionScale = scale;
                let lastBlob = null;

                for (let attempt = 0; attempt < maximumCompressionAttempts; attempt += 1) {
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.floor(bitmap.width * compressionScale));
                    canvas.height = Math.max(1, Math.floor(bitmap.height * compressionScale));
                    const context = canvas.getContext('2d');
                    if (!context) throw new Error('Canvas is unavailable');
                    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                    lastBlob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', jpegQuality));
                    if (!lastBlob || lastBlob.size <= maximumBytes) break;

                    compressionScale *= Math.sqrt(maximumBytes / lastBlob.size);
                }
                bitmap.close();

                if (lastBlob && lastBlob.size <= maximumBytes) {
                    const baseName = file.name.replace(/\.[^.]+$/, '') || 'furimadeck-photo';
                    return new File([lastBlob], `${baseName}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
                }
            } catch (exception) {
                // Unsupported camera formats are reported below without changing the selected file list.
            }

            throw new Error('写真をJPEGに変換できませんでした。JPEG・PNG・WEBPで撮影し直してください。');
        }
        async function addCaptured(selected) {
            error.classList.add('hidden');
            for (const file of Array.from(selected)) {
                if (files.length >= maximumCount) {
                    error.textContent = '追加できる画像枚数の上限を超えています。';
                    error.classList.remove('hidden');
                    break;
                }
                try {
                    files.push(await prepareCapturedPhoto(file));
                } catch (exception) {
                    error.textContent = exception.message;
                    error.classList.remove('hidden');
                }
            }
            sync();
            render();
        }
        choose.addEventListener('click', () => input.click()); chooseFolder.addEventListener('click', () => folderInput.click()); chooseCamera.addEventListener('click', () => cameraInput.click());
        input.addEventListener('change', () => { const selected = Array.from(input.files); input.value = ''; add(selected); });
        folderInput.addEventListener('change', () => { const selected = Array.from(folderInput.files); folderInput.value = ''; add(selected); });
        cameraInput.addEventListener('change', async () => { await addCaptured(cameraInput.files); cameraInput.value = ''; });
        ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, event => { event.preventDefault(); zone.classList.add('border-cyan-500'); }));
        ['dragleave', 'drop'].forEach(type => zone.addEventListener(type, event => { event.preventDefault(); zone.classList.remove('border-cyan-500'); }));
        zone.addEventListener('drop', event => add(event.dataTransfer.files));

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
