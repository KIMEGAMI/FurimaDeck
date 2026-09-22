@php($sale = $product->validSales->first())
<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">商品編集</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-4xl px-4">
            @if(session('success'))<p class="mb-4 rounded bg-emerald-50 p-3 font-bold text-emerald-800">{{ session('success') }}</p>@endif
            @if(session('error'))<p class="mb-4 rounded bg-red-50 p-3 font-bold text-red-800">{{ session('error') }}</p>@endif
            <form method="POST" enctype="multipart/form-data" action="{{ route('products.update', $product) }}" class="rounded-lg bg-white p-6 shadow">
                @csrf @method('PUT')
                @include('products.partials.form')
                <section class="mt-6 rounded-xl border border-cyan-200 bg-cyan-50 p-5">
                    <h3 class="text-lg font-black text-slate-900">販売情報</h3>
                    <p class="mt-1 text-sm font-semibold text-slate-700">売れた時だけ入力してください。売値を入力するとSOLDになり、仕入れ値・手数料・送料を引いた利益を計算します。</p>
                    <div class="mt-4 grid gap-5 md:grid-cols-2">
                        <label class="text-sm font-bold text-slate-700">売れた価格<input name="sale[sold_price]" type="number" min="0" value="{{ old('sale.sold_price', $sale?->sold_price ?? '') }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                        <label class="text-sm font-bold text-slate-700">出品先<select id="sale-marketplace" name="sale[marketplace_id]" class="mt-1 w-full rounded border-slate-300 text-slate-900"><option value="">選択してください</option>@foreach($marketplaces as $marketplace)<option value="{{ $marketplace->id }}" data-fee-rate="{{ $marketplaceFeeRates[(string) $marketplace->id] ?? $marketplace->default_fee_rate }}" @selected(old('sale.marketplace_id', $sale?->marketplace_id ?? '') == $marketplace->id)>{{ $marketplace->name }}</option>@endforeach</select></label>
                        <label class="text-sm font-bold text-slate-700">販売手数料率（%）<input id="sale-fee-rate" name="sale[sales_fee_rate]" type="number" min="0" max="100" step="0.01" value="{{ old('sale.sales_fee_rate', $sale?->sales_fee_rate ?? '') }}" readonly class="mt-1 w-full rounded border-slate-300 bg-slate-100 text-slate-900"></label>
                        <label class="text-sm font-bold text-slate-700">送料（任意）<input name="sale[shipping_fee]" type="number" min="0" value="{{ old('sale.shipping_fee', $sale?->shipping_fee ?? 0) }}" class="mt-1 w-full rounded border-slate-300 text-slate-900"></label>
                    </div>
                </section>
                <button class="mt-6 rounded bg-cyan-600 px-5 py-3 font-black text-white">更新する</button>
            </form>
            <script>
                document.querySelectorAll('[data-delete-image-url]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        if (!window.confirm('この画像を削除しますか？')) return;
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = button.dataset.deleteImageUrl;
                        const csrfInput = document.createElement('input');
                        csrfInput.type = 'hidden';
                        csrfInput.name = '_token';
                        csrfInput.value = document.querySelector('input[name="_token"]').value;
                        form.append(csrfInput);
                        const methodInput = document.createElement('input');
                        methodInput.type = 'hidden';
                        methodInput.name = '_method';
                        methodInput.value = 'DELETE';
                        form.append(methodInput);
                        document.body.append(form);
                        form.submit();
                    });
                });
                const saleMarketplace = document.getElementById('sale-marketplace');
                const saleFeeRate = document.getElementById('sale-fee-rate');
                saleMarketplace?.addEventListener('change', function () {
                    saleFeeRate.value = this.options[this.selectedIndex]?.dataset.feeRate ?? '';
                });
                if (saleMarketplace?.value && !saleFeeRate.value) saleMarketplace.dispatchEvent(new Event('change'));
            </script>
            <form method="POST" action="{{ route('products.destroy', $product) }}" class="mt-6 border-t border-red-200 pt-6">
                @csrf @method('DELETE')
                <label class="flex items-start gap-2 text-sm font-bold text-red-900"><input name="confirm_deletion" value="1" type="checkbox" required class="mt-1">この商品と登録済み画像を削除することを確認しました。</label>
                @error('confirm_deletion')<p class="mt-2 text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                <button class="mt-4 rounded bg-red-700 px-4 py-2 font-bold text-white hover:bg-red-800">商品を削除</button>
            </form>
        </div>
    </div>
</x-app-layout>
