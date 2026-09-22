@if($errors->any())<p class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 font-bold text-red-800">{{ $errors->first() }}</p>@endif

<div class="space-y-5">
    <section class="rounded-xl border border-slate-200 bg-slate-50 p-5">
        <div class="mb-4 flex items-start gap-3"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-cyan-600 text-sm font-black text-white">1</span><div><h3 class="font-black text-slate-900">出品する商品</h3><p class="mt-1 text-sm text-slate-600">在庫として登録済みの商品を選びます。</p></div></div>
        <div class="grid gap-5 md:grid-cols-2">
            <label class="text-sm font-bold text-slate-700">商品<span class="ml-1 text-red-700">必須</span><select required name="product_id" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"><option value="">選択してください</option>@foreach($products as $product)<option value="{{ $product->id }}" data-product-image-url="{{ $product->images->first() ? route('products.images.show', ['product' => $product, 'image' => $product->images->first(), 'variant' => 'thumbnail']) : '' }}" data-product-name="{{ $product->product_name }}" data-product-description="{{ $product->description_base }}" data-purchase-unit-cost="{{ $product->purchase_unit_cost }}" @selected(old('product_id', $listing->product_id ?? '') == $product->id)>商品ID: {{ $product->internal_sku }}｜{{ $product->product_name }}</option>@endforeach</select></label>
            <label class="text-sm font-bold text-slate-700">販売先<span class="ml-1 text-red-700">必須</span><select required name="marketplace_id" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"><option value="">選択してください</option>@foreach($marketplaces as $marketplace)<option value="{{ $marketplace->id }}" @selected(old('marketplace_id', $listing->marketplace_id ?? '') == $marketplace->id)>{{ $marketplace->name }}</option>@endforeach</select></label>
            <label id="other-marketplace-name-field" class="hidden text-sm font-bold text-slate-700 md:col-span-2">販売先名<span class="ml-1 text-red-700">必須</span><input name="marketplace_other_name" value="{{ old('marketplace_other_name', $listing->marketplace_other_name ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
        </div>
        <div id="listing-product-preview" class="mt-5 hidden items-center gap-3 rounded-lg border border-slate-200 bg-white p-3">
            <img id="listing-product-preview-image" alt="選択した商品の画像" class="hidden h-16 w-16 rounded object-cover">
            <p id="listing-product-preview-name" class="font-bold text-slate-900"></p>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <div class="mb-4 flex items-start gap-3"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-cyan-600 text-sm font-black text-white">2</span><div><h3 class="font-black text-slate-900">商品情報</h3><p class="mt-1 text-sm text-slate-600">購入者に伝わるタイトル・説明・販売先上の分類を入力します。</p></div></div>
        <div class="grid gap-5 md:grid-cols-2">
            <label class="text-sm font-bold text-slate-700 md:col-span-2">出品タイトル<span class="ml-1 text-red-700">必須</span><input required name="listing_title" value="{{ old('listing_title', $listing->listing_title ?? '') }}" maxlength="255" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700 md:col-span-2">商品説明<textarea name="listing_description" rows="7" maxlength="10000" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900">{{ old('listing_description', $listing->listing_description ?? '') }}</textarea><span class="mt-1 block text-xs font-normal text-slate-500">傷・付属品・注意点など、購入判断に必要な内容を記載します。</span></label>
            <label class="text-sm font-bold text-slate-700">販売先カテゴリ<input name="marketplace_category" value="{{ old('marketplace_category', $listing->marketplace_category ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">販売先での状態<input name="marketplace_condition" value="{{ old('marketplace_condition', $listing->marketplace_condition ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
        </div>
        <div class="mt-5 flex flex-wrap items-center gap-3" aria-live="polite"><button id="listing-apply-product-info" type="button" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-black text-white">商品情報を反映</button><button id="listing-copy-text" type="button" class="rounded-lg border border-slate-700 bg-white px-4 py-2 text-sm font-black text-slate-900">出品文をコピー</button><p id="listing-copy-status" class="text-sm font-bold text-slate-700"></p></div>
        <p class="mt-2 text-xs font-semibold text-slate-600">商品を選んで「商品情報を反映」を押すと、商品名と基本説明を出品欄へ反映します。反映後のタイトル・説明を確認してからコピーしてください。</p>
    </section>

    <section class="rounded-xl border border-slate-200 bg-slate-50 p-5">
        <div class="mb-4 flex items-start gap-3"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-cyan-600 text-sm font-black text-white">3</span><div><h3 class="font-black text-slate-900">出品価格・販売条件</h3><p class="mt-1 text-sm text-slate-600">出品価格を入力すると、手数料・送料を含む見込み利益を表示します。</p></div></div>
        <div class="grid gap-5 md:grid-cols-2">
<label class="text-sm font-bold text-slate-700">出品価格<input min="0" type="number" name="listing_price" value="{{ old('listing_price', $listing->listing_price ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">想定販売手数料（%）<span class="ml-1 text-red-700">必須</span><input required min="0" max="100" step="0.01" type="number" name="expected_fee_rate" value="{{ old('expected_fee_rate', $listing->expected_fee_rate ?? 0) }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">送料<input min="0" type="number" name="shipping_fee" value="{{ old('shipping_fee', $listing->shipping_fee ?? 0) }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">販売形式<select name="sale_format" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"><option value="">販売先で選択</option>@foreach(\App\Models\Listing::SALE_FORMATS as $saleFormat)<option value="{{ $saleFormat }}" @selected(old('sale_format', $listing->sale_format ?? '') === $saleFormat)>{{ \App\Models\Listing::saleFormatLabel($saleFormat) }}</option>@endforeach</select></label>
            <label class="text-sm font-bold text-slate-700">掲載期間（日）<input min="1" max="365" type="number" name="listing_period_days" value="{{ old('listing_period_days', $listing->listing_period_days ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">購入申請<select name="purchase_application" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"><option value="">販売先で選択</option>@foreach(\App\Models\Listing::PURCHASE_APPLICATIONS as $purchaseApplication)<option value="{{ $purchaseApplication }}" @selected(old('purchase_application', $listing->purchase_application ?? '') === $purchaseApplication)>{{ \App\Models\Listing::purchaseApplicationLabel($purchaseApplication) }}</option>@endforeach</select></label>
            <label class="text-sm font-bold text-slate-700">出品状態<span class="ml-1 text-red-700">必須</span><select name="status" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900">@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('status', $listing->status ?? 'draft') === $status)>{{ \App\Models\Listing::statusLabel($status) }}</option>@endforeach</select></label>
        </div>
        <div class="mt-5 rounded-lg border border-cyan-200 bg-cyan-50 p-4"><h4 class="font-black text-slate-900">見込み利益</h4><p id="listing-profit-estimate" class="mt-2 text-lg font-black text-slate-900">出品価格を入力してください。</p><p class="mt-1 text-sm font-bold text-slate-700">商品原価、想定手数料、送料を含みます。</p></div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <div class="mb-4 flex items-start gap-3"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-cyan-600 text-sm font-black text-white">4</span><div><h3 class="font-black text-slate-900">配送情報</h3><p class="mt-1 text-sm text-slate-600">送料負担、配送方法、発送元と発送目安を販売先に合わせて設定します。</p></div></div>
        <div class="grid gap-5 md:grid-cols-2">
            <label class="text-sm font-bold text-slate-700">送料負担<select name="shipping_payer" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"><option value="">販売先で選択</option>@foreach(\App\Models\Listing::SHIPPING_PAYERS as $shippingPayer)<option value="{{ $shippingPayer }}" @selected(old('shipping_payer', $listing->shipping_payer ?? '') === $shippingPayer)>{{ \App\Models\Listing::shippingPayerLabel($shippingPayer) }}</option>@endforeach</select></label>
            <label class="text-sm font-bold text-slate-700">配送方法<input name="shipping_method" value="{{ old('shipping_method', $listing->shipping_method ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">発送元地域<input name="sender_region" value="{{ old('sender_region', $listing->sender_region ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">発送までの日数<input min="0" max="30" type="number" name="dispatch_days" value="{{ old('dispatch_days', $listing->dispatch_days ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">配送サイズ<input name="shipping_size" value="{{ old('shipping_size', $listing->shipping_size ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">配送重量（g）<input min="0" max="1000000" type="number" name="shipping_weight_grams" value="{{ old('shipping_weight_grams', $listing->shipping_weight_grams ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700 md:col-span-2">返品方針<textarea name="return_policy" rows="3" maxlength="1000" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900">{{ old('return_policy', $listing->return_policy ?? '') }}</textarea></label>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-slate-50 p-5">
        <div class="mb-4 flex items-start gap-3"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-700 text-sm font-black text-white">5</span><div><h3 class="font-black text-slate-900">公開後の記録</h3><p class="mt-1 text-sm text-slate-600">実際に掲載した後で、URLと販売先の出品IDを保存できます。</p></div></div>
        <div class="grid gap-5 md:grid-cols-2">
            <label class="text-sm font-bold text-slate-700">出品URL<input type="url" name="external_listing_url" value="{{ old('external_listing_url', $listing->external_listing_url ?? '') }}" placeholder="https://" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
            <label class="text-sm font-bold text-slate-700">販売先の出品ID<input name="external_listing_id" value="{{ old('external_listing_id', $listing->external_listing_id ?? '') }}" class="mt-1 w-full rounded-lg border-slate-300 text-slate-900"></label>
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-slate-50 p-5" aria-live="polite"><h3 class="font-black text-slate-900">出品前確認</h3><ul id="marketplace-readiness" class="mt-3 grid gap-2 text-sm text-slate-700"><li>販売先を選択してください。</li></ul></section>
    @if (!empty($selectedProductId))
        <script>document.querySelector('select[name="product_id"]')?.value = @json($selectedProductId); document.querySelector('select[name="product_id"]')?.dispatchEvent(new Event('change'));</script>
    @endif
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const requirementsByMarketplace = @json($marketplaceRequirements);
        const marketplaceFeeRates = @json($marketplaceFeeRates);
        const otherMarketplaceIds = @json($marketplaces->where('code', 'other')->pluck('id')->values());
        const marketplaceSelect = document.querySelector('select[name="marketplace_id"]');
        const productSelect = document.querySelector('select[name="product_id"]');
        const otherMarketplaceNameField = document.getElementById('other-marketplace-name-field');
        const productPreview = document.getElementById('listing-product-preview');
        const productPreviewImage = document.getElementById('listing-product-preview-image');
        const productPreviewName = document.getElementById('listing-product-preview-name');
        const readiness = document.getElementById('marketplace-readiness');
        const estimate = document.getElementById('listing-profit-estimate');
        const applyProductInfo = document.getElementById('listing-apply-product-info');
        const copyListingText = document.getElementById('listing-copy-text');
        const copyStatus = document.getElementById('listing-copy-status');

        function valueIsPresent(field) {
            const input = document.querySelector(`[name="${field}"]`);

            return Boolean(input?.value?.trim());
        }

        function renderReadiness() {
            const requirements = requirementsByMarketplace[marketplaceSelect.value];
            readiness.replaceChildren();

            if (!requirements) {
                const message = document.createElement('li');
                message.textContent = '販売先を選択してください。';
                readiness.append(message);

                return;
            }

            Object.entries(requirements).forEach(function ([field, label]) {
                const item = document.createElement('li');
                const isPresent = valueIsPresent(field);
                item.className = isPresent ? 'font-bold text-emerald-800' : 'font-bold text-amber-800';
                item.textContent = `${isPresent ? '入力済み' : '要確認'}: ${label}`;
                readiness.append(item);
            });
        }

        function numberValue(field) {
            const input = document.querySelector(`[name="${field}"]`);
            if (!input || input.value.trim() === '') return null;

            const value = Number(input.value);

            return Number.isFinite(value) ? value : null;
        }

        function renderEstimate() {
            const price = numberValue('listing_price');
            const quantity = 1;
            const feeRate = numberValue('expected_fee_rate');
            const shippingFee = numberValue('shipping_fee');
            const selectedProduct = document.querySelector('select[name="product_id"] option:checked');
            const purchaseUnitCost = Number(selectedProduct?.dataset.purchaseUnitCost);

            if ([price, quantity, feeRate, shippingFee].some(value => value === null || value < 0) || !Number.isFinite(purchaseUnitCost) || quantity < 1) {
                estimate.textContent = '出品価格を入力してください。';

                return;
            }

            const salesFee = Math.floor(price * feeRate / 100);
            const profit = price - (purchaseUnitCost * quantity) - salesFee - shippingFee;
            const margin = price === 0 ? 0 : (profit / price) * 100;
            estimate.textContent = `¥${new Intl.NumberFormat('ja-JP').format(profit)} (${margin.toFixed(2)}%)`;
        }

        function renderProductPreview() {
            const selectedProduct = productSelect?.options[productSelect.selectedIndex];
            const hasProduct = Boolean(selectedProduct?.value);
            productPreview.classList.toggle('hidden', !hasProduct);
            productPreviewName.textContent = hasProduct ? selectedProduct.dataset.productName : '';
            productPreviewImage.classList.toggle('hidden', !selectedProduct?.dataset.productImageUrl);
            productPreviewImage.src = selectedProduct?.dataset.productImageUrl ?? '';
        }

        function selectedProduct() {
            return productSelect?.options[productSelect.selectedIndex];
        }

        function setCopyStatus(message, style = 'text-slate-700') {
            copyStatus.textContent = message;
            copyStatus.className = `text-sm font-bold ${style}`;
        }

        marketplaceSelect?.addEventListener('change', function () {
            const feeRateInput = document.querySelector('input[name="expected_fee_rate"]');
            if (feeRateInput && feeRateInput.dataset.userEdited !== 'true') feeRateInput.value = marketplaceFeeRates[marketplaceSelect.value] ?? 0;
            otherMarketplaceNameField.classList.toggle('hidden', !otherMarketplaceIds.includes(Number(marketplaceSelect.value)));
            renderReadiness();
            renderEstimate();
        });
        productSelect?.addEventListener('change', function () { renderProductPreview(); renderEstimate(); });
        applyProductInfo?.addEventListener('click', function () {
            const product = selectedProduct();
            if (!product?.value) { setCopyStatus('先に商品を選択してください。', 'text-red-800'); return; }
            const title = document.querySelector('[name="listing_title"]');
            const description = document.querySelector('[name="listing_description"]');
            const nextTitle = product.dataset.productName ?? '';
            const nextDescription = product.dataset.productDescription ?? '';
            if ((title.value.trim() || description.value.trim()) && !window.confirm('入力済みのタイトル・説明を、選択した商品情報で置き換えますか？')) return;
            title.value = nextTitle;
            description.value = nextDescription;
            title.dispatchEvent(new Event('input'));
            description.dispatchEvent(new Event('input'));
            setCopyStatus('商品情報を反映しました。内容を確認してください。', 'text-emerald-800');
        });
        copyListingText?.addEventListener('click', async function () {
            const title = document.querySelector('[name="listing_title"]')?.value.trim();
            const description = document.querySelector('[name="listing_description"]')?.value.trim();
            const text = [title, description].filter(Boolean).join('\n\n');
            if (!text) { setCopyStatus('コピーするタイトルまたは説明を入力してください。', 'text-red-800'); return; }
            if (!navigator.clipboard?.writeText) { setCopyStatus('このブラウザではコピー機能を利用できません。タイトルと説明を手動でコピーしてください。', 'text-red-800'); return; }
            try { await navigator.clipboard.writeText(text); setCopyStatus('タイトルと説明をコピーしました。出品サイトへ貼り付けてください。', 'text-emerald-800'); } catch { setCopyStatus('コピーできませんでした。ブラウザの権限を確認してください。', 'text-red-800'); }
        });
        document.querySelectorAll('input, select, textarea').forEach(function (input) {
            input.addEventListener('input', renderReadiness);
            input.addEventListener('change', renderReadiness);
            input.addEventListener('input', renderEstimate);
            input.addEventListener('change', renderEstimate);
        });
        document.querySelector('input[name="expected_fee_rate"]')?.addEventListener('input', function (event) { event.target.dataset.userEdited = 'true'; });
        renderReadiness();
        renderEstimate();
        renderProductPreview();
        otherMarketplaceNameField.classList.toggle('hidden', !otherMarketplaceIds.includes(Number(marketplaceSelect?.value)));
    });
</script>
