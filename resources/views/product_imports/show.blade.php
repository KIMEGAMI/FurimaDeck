<x-app-layout>
    <x-slot name="header"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-2xl font-black text-cyan-200">CSV取込プレビュー</h2><a href="{{ route('products.index') }}" class="inline-flex items-center rounded bg-slate-700 px-4 py-2 text-sm font-black text-white hover:bg-slate-600">商品一覧へ戻る</a></div></x-slot>
    <div class="min-h-screen bg-slate-100 py-8"><div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <section class="rounded bg-white p-5 shadow">
            <div class="grid gap-3 sm:grid-cols-4">
                <p class="font-bold text-slate-800">全{{ number_format($batch->total_rows) }}行</p>
                <p class="font-bold text-emerald-700">有効 {{ number_format($batch->success_rows) }}行</p>
                <p class="font-bold text-amber-700">スキップ {{ number_format($batch->skipped_rows ?? 0) }}行</p>
                <p class="font-bold text-red-700">エラー {{ number_format($batch->failed_rows) }}行</p>
            </div>
        </section>
        @if($batch->status === 'preview')
            <section class="mt-6 rounded border-2 border-amber-300 bg-amber-50 p-5 shadow">
                <p class="font-black text-amber-950">この段階では商品一覧に登録されていません。</p>
                <p class="mt-2 text-sm font-bold leading-6 text-amber-900">内容を確認したあと、全行が有効な場合は下の「確定登録」ボタンを押してください。確定登録が完了すると商品一覧に反映されます。</p>
            </section>
        @endif        @if($batch->status === 'preview' && $batch->failed_rows === 0)
            <section class="mt-6 rounded bg-white p-5 shadow">
                <form method="POST" action="{{ route('products.imports.commit', $batch) }}">
                    @csrf
                    <button class="rounded bg-cyan-500 px-4 py-2 text-sm font-black text-slate-950 hover:bg-cyan-400" onclick="return confirm('このCSVの{{ $batch->success_rows }}件を確定登録しますか？')">{{ $batch->success_rows }}件を確定登録</button>
                </form>
            </section>
        @endif
        @error('csv_file')<p class="mt-4 font-bold text-red-700">{{ $message }}</p>@enderror
        <div class="mt-6 flex flex-col gap-3 rounded bg-white p-4 shadow sm:flex-row sm:items-center sm:justify-between"><p class="text-sm font-bold text-slate-700">{{ $rows->total() > 0 ? number_format($rows->firstItem()) . '〜' . number_format($rows->lastItem()) . '件を表示 / 全' . number_format($rows->total()) . '件' : '表示する行はありません' }}</p><div>{{ $rows->onEachSide(2)->links() }}</div></div>
        <section class="mt-4 overflow-x-auto rounded bg-white shadow">
            <table class="w-full text-left text-sm text-slate-800">
                <thead class="bg-slate-100"><tr><th class="p-3">行</th><th class="p-3">管理ID</th><th class="p-3">タイトル</th><th class="p-3">在庫状態</th><th class="p-3">販売先</th><th class="p-3">出品日</th><th class="p-3">出品価格</th><th class="p-3">販売区分</th><th class="p-3">売却日</th><th class="p-3">売価</th><th class="p-3">検証結果</th></tr></thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="border-t">
                            <td class="p-3">{{ $row->row_number }}</td>
                            <td class="p-3 font-mono">{{ $row->row_json['internal_sku'] ?? $row->row_json['management_id'] ?? '-' }}</td>
                            <td class="p-3">{{ $row->row_json['product_name'] ?? $row->row_json['title'] ?? '-' }}</td>
                            <td class="p-3">{{ $row->row_json['inventory_status'] ?? '-' }}</td>
                            <td class="p-3">{{ $row->row_json['marketplace_code'] ?? '-' }}</td>
                            <td class="p-3">{{ $row->row_json['listed_at'] ?? '-' }}</td>
                            <td class="p-3">{{ ($row->row_json['listing_price'] ?? '') !== '' ? '¥'.number_format((int) $row->row_json['listing_price']) : '-' }}</td>
                            <td class="p-3">{{ $row->row_json['sale_state'] ?? 'stock' }}</td>
                            <td class="p-3">{{ $row->row_json['sold_at'] ?? '-' }}</td>
                            <td class="p-3">{{ ($row->row_json['sold_price'] ?? '') !== '' ? '¥'.number_format((int) $row->row_json['sold_price']) : '-' }}</td>
                            <td class="p-3">
                                @if($row->is_valid)<span class="font-bold text-emerald-700">有効</span>@else<ul class="list-disc ps-4 font-bold text-red-700">@foreach($row->errors_json ?? [] as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        <div class="mt-5 flex flex-col gap-3 rounded bg-white p-4 shadow sm:flex-row sm:items-center sm:justify-between"><p class="text-sm font-bold text-slate-700">ページ {{ $rows->currentPage() }} / {{ $rows->lastPage() }}</p><div>{{ $rows->onEachSide(2)->links() }}</div></div>
    </div></div>
</x-app-layout>
