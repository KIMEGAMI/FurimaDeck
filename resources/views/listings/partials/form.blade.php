@if($errors->any())<p class="mb-4 rounded bg-red-50 p-3 font-bold text-red-800">{{ $errors->first() }}</p>@endif
<div class="grid gap-5 md:grid-cols-2">
<label class="text-sm font-bold text-slate-700">商品<select required name="product_id" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">選択してください</option>@foreach($products as $product)<option value="{{ $product->id }}" data-purchase-unit-cost="{{ $product->purchase_unit_cost }}" @selected(old('product_id', $listing->product_id ?? '') == $product->id)>{{ $product->internal_sku }} | {{ $product->product_name }}</option>@endforeach</select></label>
<label class="text-sm font-bold text-slate-700">販売先<select required name="marketplace_id" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">選択してください</option>@foreach($marketplaces as $marketplace)<option value="{{ $marketplace->id }}" @selected(old('marketplace_id', $listing->marketplace_id ?? '') == $marketplace->id)>{{ $marketplace->name }}</option>@endforeach</select></label>
<label id="other-marketplace-name-field" class="hidden text-sm font-bold text-slate-700">販売先名<input name="marketplace_other_name" value="{{ old('marketplace_other_name', $listing->marketplace_other_name ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700 md:col-span-2">出品タイトル<input required name="listing_title" value="{{ old('listing_title', $listing->listing_title ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700 md:col-span-2">出品説明<textarea name="listing_description" rows="6" class="mt-1 w-full rounded border-slate-300 text-slate-900">{{ old('listing_description', $listing->listing_description ?? '') }}</textarea></label>
<label class="text-sm font-bold text-slate-700">出品価格<input min="0" type="number" name="listing_price" value="{{ old('listing_price', $listing->listing_price ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">出品数量<input required min="1" max="1000000" type="number" name="listing_quantity" value="{{ old('listing_quantity', $listing->listing_quantity ?? 1) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">想定手数料率（%）<input required min="0" max="100" step="0.01" type="number" name="expected_fee_rate" value="{{ old('expected_fee_rate', $listing->expected_fee_rate ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">出品状態<select name="status" class="mt-1 w-full rounded border-slate-300 text-slate-900">@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('status', $listing->status ?? 'draft') === $status)>{{ \App\Models\Listing::statusLabel($status) }}</option>@endforeach</select></label>
<label class="text-sm font-bold text-slate-700">送料<input min="0" type="number" name="shipping_fee" value="{{ old('shipping_fee', $listing->shipping_fee ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">販売先カテゴリ<input name="marketplace_category" value="{{ old('marketplace_category', $listing->marketplace_category ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">販売先の商品状態<input name="marketplace_condition" value="{{ old('marketplace_condition', $listing->marketplace_condition ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">販売形式<select name="sale_format" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">販売先で選択</option>@foreach(\App\Models\Listing::SALE_FORMATS as $saleFormat)<option value="{{ $saleFormat }}" @selected(old('sale_format', $listing->sale_format ?? '') === $saleFormat)>{{ \App\Models\Listing::saleFormatLabel($saleFormat) }}</option>@endforeach</select></label>
<label class="text-sm font-bold text-slate-700">掲載期間（日）<input min="1" max="365" type="number" name="listing_period_days" value="{{ old('listing_period_days', $listing->listing_period_days ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">送料負担<select name="shipping_payer" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">販売先で選択</option>@foreach(\App\Models\Listing::SHIPPING_PAYERS as $shippingPayer)<option value="{{ $shippingPayer }}" @selected(old('shipping_payer', $listing->shipping_payer ?? '') === $shippingPayer)>{{ \App\Models\Listing::shippingPayerLabel($shippingPayer) }}</option>@endforeach</select></label>
<label class="text-sm font-bold text-slate-700">配送方法<input name="shipping_method" value="{{ old('shipping_method', $listing->shipping_method ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">発送元地域<input name="sender_region" value="{{ old('sender_region', $listing->sender_region ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">発送までの日数<input min="0" max="30" type="number" name="dispatch_days" value="{{ old('dispatch_days', $listing->dispatch_days ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">配送サイズ<input name="shipping_size" value="{{ old('shipping_size', $listing->shipping_size ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">配送重量（g）<input min="0" max="1000000" type="number" name="shipping_weight_grams" value="{{ old('shipping_weight_grams', $listing->shipping_weight_grams ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">出品URL<input type="url" name="external_listing_url" value="{{ old('external_listing_url', $listing->external_listing_url ?? '') }}" placeholder="https://" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">販売先の出品ID<input name="external_listing_id" value="{{ old('external_listing_id', $listing->external_listing_id ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
<label class="text-sm font-bold text-slate-700">購入申請<select name="purchase_application" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">販売先で選択</option>@foreach(\App\Models\Listing::PURCHASE_APPLICATIONS as $purchaseApplication)<option value="{{ $purchaseApplication }}" @selected(old('purchase_application', $listing->purchase_application ?? '') === $purchaseApplication)>{{ \App\Models\Listing::purchaseApplicationLabel($purchaseApplication) }}</option>@endforeach</select></label>
</div>
<label class="mt-5 block text-sm font-bold text-slate-700">返品方針<textarea name="return_policy" rows="3" class="mt-1 w-full rounded border-slate-300 text-slate-900">{{ old('return_policy', $listing->return_policy ?? '') }}</textarea></label>
<section class="mt-5 rounded border border-cyan-200 bg-cyan-50 p-4"><h3 class="font-black text-slate-800">見込み利益</h3><p id="listing-profit-estimate" class="mt-2 text-lg font-black text-slate-900">出品価格を入力してください。</p><p class="mt-1 text-sm font-bold text-slate-700">商品原価、想定手数料、送料を含みます。</p></section>
<section class="mt-5 rounded border border-slate-200 bg-slate-50 p-4" aria-live="polite"><h3 class="font-black text-slate-800">出品前確認</h3><ul id="marketplace-readiness" class="mt-3 grid gap-2 text-sm text-slate-700"><li>販売先を選択してください。</li></ul></section>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const requirementsByMarketplace = @json($marketplaceRequirements);
        const marketplaceFeeRates = @json($marketplaceFeeRates);
        const otherMarketplaceIds = @json($marketplaces->where('code', 'other')->pluck('id')->values());
        const marketplaceSelect = document.querySelector('select[name="marketplace_id"]');
        const otherMarketplaceNameField = document.getElementById('other-marketplace-name-field');
        const readiness = document.getElementById('marketplace-readiness');
        const estimate = document.getElementById('listing-profit-estimate');

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
            const value = Number(document.querySelector(`[name="${field}"]`)?.value);

            return Number.isFinite(value) ? value : null;
        }

        function renderEstimate() {
            const price = numberValue('listing_price');
            const quantity = numberValue('listing_quantity');
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

        marketplaceSelect?.addEventListener('change', function () {
            const feeRateInput = document.querySelector('input[name="expected_fee_rate"]');
            if (feeRateInput && feeRateInput.dataset.userEdited !== 'true') feeRateInput.value = marketplaceFeeRates[marketplaceSelect.value] ?? 0;
            otherMarketplaceNameField.classList.toggle('hidden', !otherMarketplaceIds.includes(Number(marketplaceSelect.value)));
            renderReadiness();
            renderEstimate();
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
        otherMarketplaceNameField.classList.toggle('hidden', !otherMarketplaceIds.includes(Number(marketplaceSelect?.value)));
    });
</script>
