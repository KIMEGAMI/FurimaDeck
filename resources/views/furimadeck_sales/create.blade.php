<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">販売を記録</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-3xl px-4">
            <form method="POST" action="{{ route('furimadeck-sales.store') }}" class="rounded bg-white p-6 shadow">
                @csrf
                @if($errors->any())<p class="mb-4 rounded bg-red-50 p-3 font-bold text-red-800">{{ $errors->first() }}</p>@endif
                <p class="mb-5 rounded border border-cyan-200 bg-cyan-50 p-3 text-sm font-bold leading-6 text-cyan-950">商品一覧で選んだ商品の販売日・販売先・売価・販売送料を記録します。販売手数料は自動計算します。</p>
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2"><span class="block font-bold text-slate-800">商品</span><p class="mt-1 rounded border border-slate-300 bg-slate-100 px-3 py-2 text-slate-900">{{ $selectedProduct->internal_sku }} | {{ $selectedProduct->product_name }}</p><input type="hidden" name="product_id" value="{{ $selectedProduct->id }}"></div>
                    <label class="md:col-span-2 font-bold text-slate-800">販売先<select required name="marketplace_id" aria-describedby="marketplace-selection-help" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">選択してください</option>@foreach($marketplaces as $marketplace)<option value="{{ $marketplace->id }}" data-fee-rate="{{ $marketplaceFeeRates[(string) $marketplace->id] ?? 0 }}" @selected(old('marketplace_id', $selectedMarketplaceId) == $marketplace->id)>{{ $marketplace->name }}{{ (string) ($selectedSaleDefaults['marketplaceId'] ?? '') === (string) $marketplace->id ? '（出品情報から自動設定）' : '' }}</option>@endforeach</select><span id="marketplace-selection-help" class="mt-1 block text-xs font-normal text-slate-600">最新の出品情報を初期選択しています。販売先を変えると標準手数料率が反映されます。</span></label>
                    <label>売上（税込）<input required min="0" type="number" name="sold_price" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                    <label>販売手数料（自動計算）<input readonly min="0" type="number" name="sales_fee" value="0" class="mt-1 w-full rounded border-slate-300 bg-slate-100 text-slate-900"><span id="sales-fee-help" class="mt-1 block text-xs font-bold text-slate-600">出品情報と売上額から計算します。</span></label>
                    <label>販売手数料率（自動入力）<div class="mt-1 flex items-center gap-2"><input id="sales-fee-rate" name="sales_fee_rate" min="0" max="100" step="0.01" type="number" value="{{ $selectedSaleDefaults['feeRate'] ?? ($marketplaceFeeRates[(string) ($selectedMarketplaceId ?? '')] ?? 0) }}" readonly class="w-full rounded border-slate-300 bg-slate-100 text-slate-900"><span class="text-slate-700">%</span></div><span class="mt-1 block text-xs text-slate-600">販売先の標準率、または出品情報に保存した率を使って自動計算します。</span></label>
                    <label>販売送料<input min="0" type="number" name="shipping_fee" value="{{ old('shipping_fee', $selectedSaleDefaults['shippingFee'] ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"><span class="mt-1 block text-xs text-slate-600">出品情報に登録した送料を自動入力します。実額に合わせて変更できます。</span></label>
                    <label>販売日<input required type="date" name="sold_at" value="{{ old('sold_at', now()->toDateString()) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                </div>
                <button class="mt-6 rounded bg-cyan-600 px-5 py-3 font-black text-white">販売を確定する</button>
            </form>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const marketplace = document.querySelector('select[name="marketplace_id"]');
                    const price = document.querySelector('input[name="sold_price"]');
                    const fee = document.querySelector('input[name="sales_fee"]');
                    const feeRateDisplay = document.getElementById('sales-fee-rate');
                    const shippingFee = document.querySelector('input[name="shipping_fee"]');
                    const help = document.getElementById('sales-fee-help');
                    const currentDefaults = @json($selectedSaleDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    const marketplaceRate = function () {
                        const option = marketplace?.options[marketplace.selectedIndex];
                        const usesListingDefaults = currentDefaults.marketplaceId !== null
                            && String(currentDefaults.marketplaceId) === String(marketplace?.value ?? '');
                        return Number(usesListingDefaults
                            ? (currentDefaults.feeRate ?? option?.dataset.feeRate ?? 0)
                            : (option?.dataset.feeRate ?? 0));
                    };
                    const calculateFee = function () {
                        const rate = marketplaceRate();
                        const soldPrice = Math.max(0, Number(price?.value ?? 0));
                        const amount = Math.floor(soldPrice * Math.round(rate * 100) / 10000);
                        if (fee) fee.value = Number.isFinite(amount) ? amount : 0;
                        if (feeRateDisplay) feeRateDisplay.value = Number.isFinite(rate) ? rate : 0;
                        if (help) help.textContent = `販売先の手数料率 ${rate}% / 手数料 ¥${amount.toLocaleString('ja-JP')}`;
                    };
                    const applyMarketplaceDefaults = function () {
                        const selectedMarketplaceId = marketplace?.value ?? '';
                        const usesListingDefaults = currentDefaults.marketplaceId !== null
                            && String(currentDefaults.marketplaceId) === String(selectedMarketplaceId);

                        shippingFee.value = Number(usesListingDefaults ? (currentDefaults.shippingFee ?? 0) : 0);
                        calculateFee();
                    };
                    marketplace?.addEventListener('change', function () {
                        applyMarketplaceDefaults();
                    });
                    price?.addEventListener('input', calculateFee);
                    shippingFee?.addEventListener('input', calculateFee);
                    applyMarketplaceDefaults();
                    calculateFee();
                });
            </script>
        </div>
    </div>
</x-app-layout>
