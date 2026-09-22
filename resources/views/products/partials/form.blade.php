@if ($errors->any())<div class="mb-5 rounded bg-red-50 p-4 font-bold text-red-800">{{ $errors->first() }}</div>@endif
@php($editing = isset($product))
@php($purchaseDate = $editing && $product->purchase_date ? $product->purchase_date->format('Y-m-d') : '')
@php($remainingImageSlots = max(0, (int) config('furimadeck.product_images.max_count') - ($editing ? $product->images->count() : 0)))
@php($selectedCategoryId = (string) old('category_id', $product->category_id ?? ''))
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
<div class="md:col-span-2 mt-2 border-t border-slate-200 pt-4"><h3 class="text-base font-black text-slate-900">仕入・商品詳細（任意）</h3><p class="mt-1 text-sm text-slate-600">登録後に編集できる補足情報です。</p></div>
<label class="block text-sm font-bold text-slate-700">仕入単価<input required min="0" type="number" name="purchase_unit_cost" value="{{ old('purchase_unit_cost', $product->purchase_unit_cost ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
</div></section>
<script>
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
