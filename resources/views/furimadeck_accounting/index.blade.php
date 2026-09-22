<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">確定申告・会計連携</h2></x-slot>
    <div class="py-8"><div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @if(session('success'))<p class="rounded bg-emerald-50 p-4 font-semibold text-emerald-800">{{ session('success') }}</p>@endif
                @if(session('error'))<p class="rounded bg-red-50 p-4 font-semibold text-red-800">{{ session('error') }}</p>@endif        @if($errors->any())<p class="rounded bg-red-50 p-4 font-semibold text-red-800">{{ $errors->first() }}</p>@endif
        <form method="get" action="{{ route('furimadeck-accounting.index') }}" class="bg-white p-4 shadow-sm sm:rounded-lg flex flex-wrap items-end gap-4">
            <label class="text-sm text-gray-700">対象年<input type="number" name="year" min="2000" max="2100" value="{{ $year }}" class="mt-1 block rounded-md border-gray-300"></label>
            <span class="text-sm text-gray-500">または任意期間</span>
            <label class="text-sm text-gray-700">開始日<input type="date" name="from" value="{{ $fromInput }}" class="mt-1 block rounded-md border-gray-300"></label>
            <label class="text-sm text-gray-700">終了日<input type="date" name="to" value="{{ $toInput }}" class="mt-1 block rounded-md border-gray-300"></label>
            <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">確認</button>
        </form>
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">対象取引</p><p class="mt-2 text-2xl font-bold">{{ number_format($report['sales_count']) }}件</p></div><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">確認済み</p><p class="mt-2 text-2xl font-bold text-emerald-700">{{ number_format($report['confirmed_count']) }}件</p></div><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">確認対象</p><p class="mt-2 text-2xl font-bold text-amber-700">{{ number_format($report['confirmation_target_count']) }}件</p></div><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">売上</p><p class="mt-2 text-2xl font-bold">{{ number_format($report['sales_amount']) }}円</p></div><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">実利益（管理用）</p><p class="mt-2 text-2xl font-bold">{{ number_format($report['profit']) }}円</p></div></section>
        <section class="bg-white p-6 shadow-sm sm:rounded-lg"><h3 class="font-semibold text-gray-900">{{ $report['period_label'] }} 準備状況</h3><p class="mt-2 text-sm text-gray-600">対象期間: {{ $report['period']['from']->format('Y/m/d') }} ～ {{ $report['period']['to']->format('Y/m/d') }}</p><table class="mt-5 min-w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2 pr-4">確認項目</th><th class="py-2 pr-4">対象</th><th class="py-2 pr-4">確認済み</th><th class="py-2">状態</th></tr></thead><tbody>@foreach ($report['checks'] as $key => $check)<tr class="border-b"><td class="py-2 pr-4">{{ ['sold_at' => '売上日時', 'sold_price' => '売価', 'cost_basis' => '原価', 'sales_fee' => '販売手数料', 'shipping_fee' => '送料', 'other_expense' => 'その他費用', 'purchase_date' => '仕入日', 'supplier' => '仕入先', 'management_id' => '管理ID', 'sale_status' => 'キャンセル・返品状態'][$key] ?? $key }}</td><td class="py-2 pr-4">{{ $check['total'] }}件</td><td class="py-2 pr-4">{{ $check['complete'] }}件</td><td class="py-2">{{ $check['status'] === 'ready' ? '準備済み' : '確認対象' }}</td></tr>@endforeach</tbody></table></section>
        <section class="bg-white p-5 shadow-sm sm:rounded-lg"><h3 class="font-semibold text-gray-900">会計データ</h3><p class="mt-2 text-sm text-gray-600">共通会計データを更新し、内容を確認してからCSVを出力します。勘定科目・税区分は自動判定しません。</p><div class="mt-4 flex flex-wrap gap-3"><form method="post" action="{{ route('furimadeck-accounting.sync') }}">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">共通データを更新</button></form><a href="{{ route('furimadeck-accounting.csv', ['year' => $year, 'from' => request('from'), 'to' => request('to')]) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">共通CSVをダウンロード</a><a href="{{ route('furimadeck-accounting.freee', ['year' => $year, 'from' => request('from'), 'to' => request('to')]) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">freee CSV</a><a href="{{ route('furimadeck-accounting.money-forward', ['year' => $year, 'from' => request('from'), 'to' => request('to')]) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">Money Forward CSV</a></div></section>        <section class="bg-white p-5 shadow-sm sm:rounded-lg"><h3 class="font-semibold text-gray-900">送信前ワークフロー</h3><p class="mt-2 text-sm text-gray-600">共通会計データをReviewし、内容を確認してからConfirmに進めます。</p><div class="mt-4 flex flex-wrap gap-3"><form method="post" action="{{ route('furimadeck-accounting.review') }}">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><button class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">Reviewへ</button></form><form method="post" action="{{ route('furimadeck-accounting.confirm') }}">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Confirm</button></form></div></section>
        <section class="bg-white p-5 shadow-sm sm:rounded-lg">
            <h3 class="font-semibold text-gray-900">会計データ送信</h3>
            <p class="mt-2 text-sm text-gray-600">Confirm済みの未送信データだけ外部サービスへ送信できます。送信後は外部IDを保存し、同じデータを再送信しません。</p>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="border-b text-left"><th class="py-2 pr-4">日付</th><th class="py-2 pr-4">商品</th><th class="py-2 pr-4">売上</th><th class="py-2 pr-4">状態</th><th class="py-2">操作</th></tr></thead>
                    <tbody>
                    @forelse($accountingEntries as $entry)
                        <tr class="border-b">
                            <td class="py-2 pr-4">{{ $entry->transaction_date?->format('Y/m/d') }}</td>
                            <td class="py-2 pr-4">{{ $entry->title }}</td>
                            <td class="py-2 pr-4">{{ number_format($entry->sale_amount) }}円</td>
                            <td class="py-2 pr-4">{{ $entry->sync_status === 'synced' ? '送信済み' : ($entry->source_status === 'confirmed' ? 'Confirm済み' : '未確認') }}</td>
                            <td class="py-2">
                                @if($entry->source_status === 'confirmed' && $entry->sync_status === 'not_synced')
                                    @if($accountingConnections->get('freee')?->status === 'connected' && $freeeSyncMissingConfiguration === [])<form method="post" action="{{ route('furimadeck-accounting.send.freee', $entry) }}">@csrf<button class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white">freeeへ送信</button></form>@endif
                                    @if($accountingConnections->get('money_forward')?->status === 'connected' && filled($accountingConnections->get('money_forward')?->settings['tax_id'] ?? null))<form method="post" action="{{ route('furimadeck-accounting.send.money-forward', $entry) }}" class="mt-1">@csrf<button class="rounded-md bg-blue-700 px-3 py-1.5 text-xs font-semibold text-white">Money Forwardへ送信</button></form>@endif
                                    @if(!($accountingConnections->get('freee')?->status === 'connected' && $freeeSyncMissingConfiguration === []) && !($accountingConnections->get('money_forward')?->status === 'connected' && filled($accountingConnections->get('money_forward')?->settings['tax_id'] ?? null)))<span class="text-gray-500">送信設定が必要</span>@endif
                                @else<span class="text-gray-500">送信不可</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-gray-500">対象期間の会計データはありません。</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="bg-white p-5 shadow-sm sm:rounded-lg">
    <h3 class="font-semibold text-gray-900">会計サービス連携</h3>
    <p class="mt-2 text-sm text-gray-600">OAuthトークンは暗号化して保存します。連携解除すると保存済みトークンを削除します。</p>
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        @foreach(['freee' => 'freee', 'money_forward' => 'Money Forward'] as $provider => $label)
            @php($connection = $accountingConnections->get($provider))
            <div class="rounded-md border border-gray-200 p-4">
                <div class="flex items-center justify-between gap-3">
                    <h4 class="font-semibold text-gray-900">{{ $label }}</h4>
                    <span class="text-sm {{ $connection?->status === 'connected' ? 'text-emerald-700' : 'text-gray-500' }}">{{ $connection?->status === 'connected' ? '連携済み' : '未連携' }}</span>
                </div>
                @if($connection?->status === 'connected')
                    @if($provider === 'money_forward')
                        <p class="mt-2 text-xs text-gray-600">Money Forwardの仕訳APIへ、Confirm済みデータを送信できます。初回は勘定科目と税区分を設定してください。</p>
                        <a href="{{ route('furimadeck-accounting.money-forward.setup') }}" class="mt-2 inline-block rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-800 hover:bg-gray-100">Money Forward仕訳設定を開く</a>
                    @else
                        @if($freeeSyncMissingConfiguration === [])
                            <p class="mt-2 text-xs text-gray-600">freeeの仕訳送信が利用できます。勘定科目・税区分の設定を確認してから送信してください。</p>
                        @else
                            <p class="mt-2 text-xs text-amber-700">送信設定が不足しています: freeeから取得した事業所・勘定科目・税区分を選択してください。</p>
                            <a href="{{ route('furimadeck-accounting.freee.setup') }}" class="mt-2 inline-block rounded-md border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">freee仕訳設定を開く</a>
                        @endif
                    @endif
                    <p class="mt-2 text-xs text-gray-600">最終同期: {{ $connection->last_synced_at?->format('Y/m/d H:i') ?? '未同期' }}</p>
                    <form method="post" action="{{ route('furimadeck-accounting.disconnect', $provider) }}" class="mt-3">@csrf @method('DELETE')<button class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700">連携解除</button></form>
                @else
                    <a href="{{ route('furimadeck-accounting.connect', $provider) }}" class="mt-3 inline-block rounded-md bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white">連携する</a>
                @endif
            </div>
        @endforeach
    </div>
</section><section class="bg-amber-50 p-5 shadow-sm sm:rounded-lg"><h3 class="font-semibold text-amber-900">税務上の注意</h3><p class="mt-2 text-sm leading-6 text-amber-900">FurimaDeckの実利益は経営管理用の指標です。税務上の所得、必要経費、仕訳、税額を確定するものではありません。{{ $report['accounting_sync']['status'] === 'not_available' ? '会計連携は現在未設定です' : '会計連携: '.$report['accounting_sync']['label'] }}。</p></section>
    </div></div>
</x-app-layout>










