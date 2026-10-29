<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-black text-slate-900">会計データ・仕入情報</h2></x-slot>
    <main class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-3xl px-4">
            @if(session('accounting_success'))<p class="mb-4 rounded border border-emerald-700 bg-emerald-50 p-3 font-bold text-emerald-950">{{ session('accounting_success') }}</p>@endif
            @if($errors->any())<p class="mb-4 rounded border border-red-700 bg-red-50 p-3 font-bold text-red-950">{{ $errors->first() }}</p>@endif
            <nav class="mb-4 flex flex-wrap gap-4 text-sm font-bold">
                <a href="{{ route('furimadeck-accounting.index', ['section' => 'purchase-info']) }}" class="text-blue-900 underline">商品別仕入情報一覧へ</a>
                <a href="{{ route('products.index') }}" class="text-blue-900 underline">商品一覧へ戻る</a>
                <a href="{{ route('products.edit', $product) }}" class="text-blue-900 underline">商品情報へ戻る</a>
                @if($canManageAccountingEvidence)
                    <a href="{{ route('furimadeck-accounting.products.purchase.edit', $product) }}" class="text-blue-900 underline">仕入会計データ・証憑確認へ</a>
                @endif
            </nav>

            <section class="rounded-lg border border-slate-300 bg-white p-5 shadow-sm" aria-labelledby="purchase-info-title">
                <h1 id="purchase-info-title" class="text-lg font-black text-slate-950">{{ $product->product_name }}</h1>
                <p class="mt-1 text-sm font-medium text-slate-800">商品コード: {{ $product->internal_sku }}</p>
                <p class="mt-4 text-sm leading-6 text-slate-800">この画面では仕入日・仕入金額・仕入先を管理します。ここで保存した仕入金額は商品原価と見込み利益の計算に使われます。@if($canManageAccountingEvidence) 証憑・税区分などの会計明細は、上の「仕入会計データ・証憑確認」から別に確認します。 @endif</p>

                @if($canEditPurchaseInfo)
                    <form method="POST" action="{{ route('furimadeck-accounting.products.purchase-info.update', $product) }}" class="mt-5 grid gap-4 md:grid-cols-2">
                        @csrf
                        @method('PUT')
                        <label class="block text-sm font-bold text-slate-900">仕入日（分かる場合）
                            <input type="date" name="purchase_date" value="{{ old('purchase_date', $product->purchase_date?->format('Y-m-d')) }}" class="mt-1 w-full rounded border-slate-500 bg-white text-slate-950">
                        </label>
                        <label class="block text-sm font-bold text-slate-900">仕入単価
                            <input required min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="purchase_unit_cost" value="{{ old('purchase_unit_cost', $product->purchase_unit_cost) }}" class="mt-1 w-full rounded border-slate-500 bg-white text-slate-950">
                        </label>
                        <label class="block text-sm font-bold text-slate-900">仕入送料（任意）
                            <input min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="purchase_shipping_cost" value="{{ old('purchase_shipping_cost', $product->purchase_shipping_cost) }}" class="mt-1 w-full rounded border-slate-500 bg-white text-slate-950">
                        </label>
                        <label class="block text-sm font-bold text-slate-900">その他仕入費（任意）
                            <input min="0" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="other_purchase_expense" value="{{ old('other_purchase_expense', $product->other_purchase_expense) }}" class="mt-1 w-full rounded border-slate-500 bg-white text-slate-950">
                        </label>
                        <label class="block text-sm font-bold text-slate-900 md:col-span-2">仕入先（任意）
                            <select name="supplier_id" class="mt-1 w-full rounded border-slate-500 bg-white text-slate-950"><option value="">未設定</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) old('supplier_id', $product->supplier_id ?? '') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select>
                        </label>
                        <button class="justify-self-start rounded bg-cyan-800 px-4 py-2.5 text-sm font-black text-white hover:bg-cyan-950">仕入情報を保存</button>
                    </form>
                @else
                    <dl class="mt-5 grid gap-3 rounded border border-slate-300 bg-slate-50 p-4 text-sm text-slate-900 sm:grid-cols-2">
                        <div><dt class="font-bold">仕入日</dt><dd>{{ $product->purchase_date?->format('Y/m/d') ?? '未設定' }}</dd></div>
                        <div><dt class="font-bold">仕入単価</dt><dd>{{ number_format((int) $product->purchase_unit_cost) }}円</dd></div>
                        <div><dt class="font-bold">仕入送料</dt><dd>{{ number_format((int) $product->purchase_shipping_cost) }}円</dd></div>
                        <div><dt class="font-bold">その他仕入費</dt><dd>{{ number_format((int) $product->other_purchase_expense) }}円</dd></div>
                        <div><dt class="font-bold">仕入先</dt><dd>{{ $product->supplier?->name ?? '未設定' }}</dd></div>
                    </dl>
                    <p class="mt-3 rounded border border-amber-400 bg-amber-50 p-3 text-sm font-bold text-amber-950">販売・会計記録を保護するため、この仕入情報は変更できません。</p>
                @endif
            </section>
        </div>
    </main>
</x-app-layout>
