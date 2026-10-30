<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">販売記録の修正</h2></x-slot>
    <div class="mx-auto max-w-3xl space-y-5 px-4 py-8 sm:px-6">
        @if($errors->any())<p role="alert" class="border-l-4 border-red-700 bg-red-50 p-3 text-sm text-red-950">{{ $errors->first() }}</p>@endif
        @if($amountCorrectionBlockReason)
            <section role="alert" class="space-y-3 border-l-4 border-red-700 bg-red-50 p-4 text-sm leading-6 text-red-950">
                <p class="font-semibold">{{ $sale->product_name_snapshot }} / {{ $sale->internal_sku_snapshot }} / {{ $sale->marketplace?->name ?? '販売先未設定' }}</p>
                <p>この販売記録は外部へ反映済み、処理中、または結果未確定のため、この画面では金額を変更できません。</p>
                <div class="flex flex-wrap gap-x-5 gap-y-2 font-semibold">
                    <a class="text-blue-900 underline" href="{{ route('furimadeck-sales.index') }}">販売記録一覧へ戻る</a>
                </div>
            </section>
        @else
            <div class="border-l-4 border-amber-600 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                <p class="font-semibold">{{ $sale->product_name_snapshot }} / {{ $sale->internal_sku_snapshot }} / {{ $sale->marketplace?->name ?? '販売先未設定' }}</p>
                <p>販売日・販売先・売価・販売送料を修正できます。販売手数料は販売先の設定率から自動計算します。</p>
            </div>
        <form method="post" action="{{ route('furimadeck-sales.update', $sale) }}" class="space-y-5 bg-white p-5 shadow-sm sm:p-6">
            @csrf @method('PUT')
            <input type="hidden" name="record_version" value="{{ $sale->recordVersionToken() }}">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block text-sm font-semibold text-gray-800">販売日<input required type="datetime-local" name="sold_at" value="{{ old('sold_at', $sale->sold_at?->format('Y-m-d\TH:i')) }}" class="mt-1 block w-full rounded border-gray-300 text-gray-900"></label>
                <label class="block text-sm font-semibold text-gray-800">販売先<select required name="marketplace_id" id="sale-marketplace" class="mt-1 block w-full rounded border-gray-300 bg-white text-gray-900"><option value="">選択してください</option>@foreach($marketplaces as $marketplace)<option value="{{ $marketplace->id }}" data-fee-rate="{{ $marketplaceFeeRates[(string) $marketplace->id] ?? 0 }}" @selected(old('marketplace_id', $sale->marketplace_id) == $marketplace->id)>{{ $marketplace->name }}{{ !$marketplace->is_active ? '（現在選択中・利用停止）' : '' }}</option>@endforeach</select></label>
                <label class="block text-sm font-semibold text-gray-800">売上（税込）<input required type="number" min="0" name="sold_price" value="{{ old('sold_price', $sale->sold_price) }}" class="mt-1 block w-full rounded border-gray-300 text-gray-900"></label>
                <label class="block text-sm font-semibold text-gray-800">販売手数料率<div class="mt-1 flex items-center gap-2"><input id="sales-fee-rate" readonly type="number" value="{{ old('sales_fee_rate', $marketplaceFeeRates[(string) $sale->marketplace_id] ?? 0) }}" class="block w-full rounded border-gray-300 bg-gray-100 text-gray-900"><span>%</span></div></label>
                <label class="block text-sm font-semibold text-gray-800">販売手数料（自動計算）<input required readonly type="number" min="0" name="sales_fee" value="{{ old('sales_fee', $sale->sales_fee) }}" class="mt-1 block w-full rounded border-gray-300 bg-gray-100 text-gray-900"></label>
                <label class="block text-sm font-semibold text-gray-800">販売送料<input required type="number" min="0" name="shipping_fee" value="{{ old('shipping_fee', $sale->shipping_fee) }}" class="mt-1 block w-full rounded border-gray-300 text-gray-900"></label>
            </div>
            <div class="flex flex-wrap items-center gap-4 border-t border-gray-200 pt-4">
                <button class="rounded bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white">修正を保存</button>
                <a href="{{ route('furimadeck-sales.index') }}" class="text-sm font-semibold text-blue-800 underline">販売記録一覧へ戻る</a>
            </div>
        </form>
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            document.addEventListener('DOMContentLoaded', function () {
                const marketplace = document.getElementById('sale-marketplace');
                const price = document.querySelector('input[name="sold_price"]');
                const feeRate = document.getElementById('sales-fee-rate');
                const fee = document.querySelector('input[name="sales_fee"]');
                const calculateFee = function () {
                    const rate = Number(marketplace?.options[marketplace.selectedIndex]?.dataset.feeRate ?? 0);
                    const soldPrice = Math.max(0, Number(price?.value ?? 0));
                    const amount = Math.floor(soldPrice * Math.round(rate * 100) / 10000);
                    if (feeRate) feeRate.value = Number.isFinite(rate) ? rate : 0;
                    if (fee) fee.value = Number.isFinite(amount) ? amount : 0;
                };
                marketplace?.addEventListener('change', calculateFee);
                price?.addEventListener('input', calculateFee);
                calculateFee();
            });
        </script>
        @endif
    </div>
</x-app-layout>
