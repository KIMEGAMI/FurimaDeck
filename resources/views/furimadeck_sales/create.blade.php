<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">販売を記録</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-3xl px-4">
            <form method="POST" action="{{ route('furimadeck-sales.store') }}" class="rounded bg-white p-6 shadow">
                @csrf
                @if($errors->any())<p class="mb-4 rounded bg-red-50 p-3 font-bold text-red-800">{{ $errors->first() }}</p>@endif
                <p class="mb-5 rounded border border-cyan-200 bg-cyan-50 p-3 text-sm font-bold leading-6 text-cyan-950">販売先は出品登録時の情報を使います。先に商品一覧または出品管理で、販売先を選んだ出品情報を登録してください。</p>
                <div class="grid gap-4 md:grid-cols-2">
                    <label>商品<select required name="product_id" class="mt-1 w-full rounded border-slate-300 text-slate-900">@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->internal_sku }} | {{ $product->product_name }}</option>@endforeach</select></label>
                    <label>出品情報（販売先を含む）<select required name="listing_id" aria-describedby="listing-selection-help" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">選択してください</option>@foreach($listings as $listing)<option value="{{ $listing->id }}" data-product-id="{{ $listing->product_id }}" data-marketplace-id="{{ $listing->marketplace_id }}" data-fee-rate="{{ $marketplaceFeeRates[$listing->marketplace_id] ?? 0 }}">{{ $listing->product->product_name }} | {{ $listing->marketplace->name }}</option>@endforeach</select></label>
                    <p id="listing-selection-help" class="md:col-span-2 -mt-2 text-sm text-slate-600">出品を選ぶと、商品と販売先を出品内容に合わせます。売れた出品情報を選ぶと、手数料率も自動で確定します。</p>
                    <label>販売数<input required min="1" type="number" name="quantity" value="1" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                    <label>売上（税込）<input required min="0" type="number" name="sold_price" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                    <label>原価<input required min="0" type="number" name="cost_basis" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                    <label>販売手数料（自動計算）<input readonly min="0" type="number" name="sales_fee" value="0" class="mt-1 w-full rounded border-slate-300 bg-slate-100 text-slate-900"><span id="sales-fee-help" class="mt-1 block text-xs font-bold text-slate-600">出品情報と売上額から計算します。</span></label>
                    <label>販売送料<input min="0" type="number" name="shipping_fee" value="0" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                    <label>販売日<input required type="date" name="sold_at" value="{{ now()->toDateString() }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                </div>
                <button class="mt-6 rounded bg-cyan-600 px-5 py-3 font-black text-white">販売を確定する</button>
            </form>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const listing = document.querySelector('select[name="listing_id"]');
                    const product = document.querySelector('select[name="product_id"]');
                    const price = document.querySelector('input[name="sold_price"]');
                    const fee = document.querySelector('input[name="sales_fee"]');
                    const help = document.getElementById('sales-fee-help');
                    const selectedProductId = @json($selectedProductId);
                    const calculateFee = function () {
                        const option = listing?.options[listing.selectedIndex];
                        const rate = Number(option?.dataset.feeRate ?? 0);
                        const soldPrice = Math.max(0, Number(price?.value ?? 0));
                        const amount = Math.floor(soldPrice * rate / 100);
                        if (fee) fee.value = Number.isFinite(amount) ? amount : 0;
                        if (help) help.textContent = `販売先の手数料率 ${rate}% / 手数料 ¥${amount.toLocaleString('ja-JP')}`;
                    };
                    listing?.addEventListener('change', function () {
                        const option = listing.options[listing.selectedIndex];
                        if (option?.dataset.productId) product.value = option.dataset.productId;
                        calculateFee();
                    });
                    price?.addEventListener('input', calculateFee);
                    if (selectedProductId && product) product.value = selectedProductId;
                    calculateFee();
                });
            </script>
        </div>
    </div>
</x-app-layout>
