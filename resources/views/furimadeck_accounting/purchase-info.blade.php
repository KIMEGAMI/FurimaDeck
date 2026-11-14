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
                <p class="mt-4 text-sm leading-6 text-slate-800">仕入情報の登録・編集は商品管理画面に集約しています。会計データでは、この商品の原価を使った会計明細と証憑を確認できます。@if($canManageAccountingEvidence) 証憑・税区分などの会計明細は、上の「仕入会計データ・証憑確認」から確認します。 @endif</p>
                <dl class="mt-5 grid gap-3 rounded border border-slate-300 bg-slate-50 p-4 text-sm text-slate-900 sm:grid-cols-2">
                    <div><dt class="font-bold">仕入日</dt><dd>{{ $product->purchase_date?->format('Y/m/d') ?? '未設定' }}</dd></div>
                    <div><dt class="font-bold">仕入単価</dt><dd>{{ number_format((int) $product->purchase_unit_cost) }}円</dd></div>
                    <div><dt class="font-bold">仕入送料</dt><dd>{{ number_format((int) $product->purchase_shipping_cost) }}円</dd></div>
                    <div><dt class="font-bold">その他仕入費</dt><dd>{{ number_format((int) $product->other_purchase_expense) }}円</dd></div>
                    <div><dt class="font-bold">仕入先</dt><dd>{{ $product->supplier?->name ?? '未設定' }}</dd></div>
                </dl>
                <a href="{{ route('products.edit', $product) }}#purchase-info" class="mt-4 inline-flex rounded bg-cyan-800 px-4 py-2.5 text-sm font-black text-white hover:bg-cyan-950">商品管理で仕入情報を編集</a>
            </section>
        </div>
    </main>
</x-app-layout>
