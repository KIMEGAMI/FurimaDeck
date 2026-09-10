<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">CSV取込</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8"><div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <section class="rounded bg-white p-6 shadow">
            <form method="POST" action="{{ route('products.imports.preview') }}" enctype="multipart/form-data" class="space-y-5">
                @csrf
                <label class="block text-sm font-bold text-slate-800">商品CSV
                    <input required type="file" name="csv_file" accept=".csv,text/csv" class="mt-2 block w-full rounded border-slate-300 text-slate-900">
                </label>
                @error('csv_file')<p class="text-sm font-bold text-red-700">{{ $message }}</p>@enderror
                <button class="rounded bg-cyan-500 px-4 py-2 text-sm font-black text-slate-950 hover:bg-cyan-400">検証してプレビュー</button>
            </form>
        </section>
        <section class="mt-6 rounded bg-white p-6 shadow">
            <h3 class="text-lg font-black text-slate-900">CSV出力</h3>
            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('furimadeck-export.products') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">商品CSV</a>
                <a href="{{ route('furimadeck-export.listings') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">出品CSV</a>
                <a href="{{ route('furimadeck-export.sales') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-bold text-slate-800 hover:bg-slate-50">販売CSV</a>
            </div>
        </section>
    </div></div>
</x-app-layout>
