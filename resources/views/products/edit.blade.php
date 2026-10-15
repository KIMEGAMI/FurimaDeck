@php($sale = $product->validSales->first())
<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">商品編集</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-4xl px-4">
            @if(session('success'))<p class="mb-4 rounded bg-emerald-50 p-3 font-bold text-emerald-800">{{ session('success') }}</p>@endif
            @if(session('error'))<p class="mb-4 rounded bg-red-50 p-3 font-bold text-red-800">{{ session('error') }}</p>@endif
            <form method="POST" enctype="multipart/form-data" action="{{ route('products.update', $product) }}" class="rounded-lg bg-white p-6 shadow">
                @csrf @method('PUT')
                <div class="grid items-start gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
                    <aside class="rounded-xl border border-slate-200 bg-slate-50 p-4" aria-labelledby="marketplace-shortcuts-heading">
                        <h3 id="marketplace-shortcuts-heading" class="text-base font-black text-slate-900">出品先</h3>
                        <p class="mt-1 text-sm font-semibold text-slate-700">フリマの出品画面を新しいタブで開きます。拡張機能と連携し、対象サイトの任意権限を許可すると、登録済みの情報を識別できた空欄へ入力します。</p>
                        <nav class="mt-4 grid gap-2" aria-label="フリマ出品画面">
                            @foreach (config('chrome_extension.platforms') as $platformId => $platform)
                                @if (($platform['enabled'] ?? false) && !empty($platform['listing_url']))
                                    <a href="{{ $platform['listing_url'] }}" target="_blank" rel="noopener noreferrer" class="block rounded-lg border border-cyan-800 bg-cyan-800 px-3 py-2.5 text-center text-sm font-black text-white hover:bg-cyan-900 focus:outline-none focus:ring-2 focus:ring-cyan-700" data-marketplace-shortcut="{{ $platformId }}" data-product-id="{{ $product->id }}">{{ $platform['name'] }}の出品画面を開く ↗</a>
                                @endif
                            @endforeach
                        </nav>
                        <p class="mt-3 text-xs font-semibold leading-5 text-slate-700">既入力・曖昧な項目は変更しません。実画面の項目対応と各社の許可は未確認です。ラクマは公式サポートからツール入力を控えるよう案内されています。入力後の確認・出品確定はご自身で行ってください。</p>
                    </aside>
                    <div class="min-w-0">
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
                    </div>
                </div>
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
