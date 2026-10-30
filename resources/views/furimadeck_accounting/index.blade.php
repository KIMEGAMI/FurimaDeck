<x-app-layout>
    @php($accountingSectionLabels = ['overview' => '入力状況', 'purchase-info' => '商品別仕入情報', 'review' => '明細の確認・確定', 'send' => 'CSV取込状況', 'csv' => 'CSVで登録', 'connections' => '会計ソフト設定'])
    @php($sectionDescriptions = ['overview' => '売上・仕入・費用データの不足を確認する', 'purchase-info' => '商品ごとの仕入日・原価・仕入先を管理する', 'review' => '仕訳内容を原資料と照合する', 'send' => '会計ソフトでの登録確認を記録する', 'csv' => '取込用CSVを出力して手動登録', 'connections' => 'CSV用の勘定科目・税区分を設定する'])
    <x-slot name="header"><h2 class="font-semibold text-xl leading-tight text-white">会計データ</h2><p class="mt-1 text-sm text-slate-200">{{ $accountingSectionLabels[$accountingSection] ?? '準備状況' }}</p></x-slot>
    <div class="py-8"><div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        @if(session('success'))<p class="rounded bg-emerald-50 p-4 font-semibold text-emerald-800">{{ session('success') }}</p>@endif
        @if(session('error'))<p class="rounded bg-red-50 p-4 font-semibold text-red-800">{{ session('error') }}</p>@endif
        @if($errors->any())<p class="rounded bg-red-50 p-4 font-semibold text-red-800">{{ $errors->first() }}</p>@endif
        <div class="flex flex-wrap items-center justify-between gap-3 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
            <p>免税事業者等からの課税仕入れは、相手先ごとの課税期間内累計を確認します。未照合の取引は会計CSVを出力できません。</p>
            <a href="{{ route('furimadeck-accounting.purchase-caps.index') }}" class="shrink-0 font-bold text-blue-800 underline">仕入先の確認・年間上限</a>
        </div>
        @php($accountingSectionGroups = [['label' => '1. 取引データを確認', 'sections' => ['overview', 'purchase-info', 'review']], ['label' => '2. 会計サービスへ登録', 'sections' => ['send', 'csv']], ['label' => '3. サービス設定', 'sections' => ['connections']]])
        <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start">
            <aside class="lg:sticky lg:top-20">
                <details class="rounded border border-gray-300 bg-white lg:hidden">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-gray-900">
                        <span>会計データ: {{ $accountingSectionLabels[$accountingSection] ?? '準備状況' }}</span>
                        <span class="text-cyan-900">メニューを表示</span>
                    </summary>
                    <nav aria-label="会計データの作業メニュー" class="space-y-3 border-t border-gray-200 p-3">
                        @foreach($accountingSectionGroups as $group)
                            <section>
                                <h3 class="mb-1 text-xs font-black text-gray-800">{{ $group['label'] }}</h3>
                                <div class="grid gap-1">
                                    @foreach($group['sections'] as $section)
                                        <a href="{{ route('furimadeck-accounting.index', array_merge(request()->only(['year', 'from', 'to']), ['section' => $section])) }}" @class(['block border-l-4 px-3 py-2', 'border-cyan-800 bg-cyan-50 text-cyan-950' => $accountingSection === $section, 'border-transparent text-gray-900 hover:bg-gray-100' => $accountingSection !== $section]) aria-current="{{ $accountingSection === $section ? 'page' : 'false' }}"><span class="block text-sm font-bold">{{ $accountingSectionLabels[$section] }}</span><span class="mt-0.5 block text-xs text-gray-700">{{ $sectionDescriptions[$section] }}</span></a>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </nav>
                </details>
                <nav aria-label="会計データの作業メニュー" class="hidden rounded border border-gray-300 bg-white p-3 lg:block">
                    <h3 class="mb-3 border-b border-gray-200 pb-2 text-sm font-black text-gray-900">会計データ</h3>
                    <div class="space-y-4">
                        @foreach($accountingSectionGroups as $group)
                            <section>
                                <h4 class="mb-1 px-2 text-xs font-black text-gray-700">{{ $group['label'] }}</h4>
                                <div class="grid gap-1">
                                    @foreach($group['sections'] as $section)
                                        <a href="{{ route('furimadeck-accounting.index', array_merge(request()->only(['year', 'from', 'to']), ['section' => $section])) }}" @class(['block border-l-4 px-3 py-2', 'border-cyan-800 bg-cyan-50 text-cyan-950' => $accountingSection === $section, 'border-transparent text-gray-900 hover:bg-gray-100' => $accountingSection !== $section]) aria-current="{{ $accountingSection === $section ? 'page' : 'false' }}"><span class="block text-sm font-bold">{{ $accountingSectionLabels[$section] }}</span><span class="mt-0.5 block text-xs leading-5 text-gray-700">{{ $sectionDescriptions[$section] }}</span></a>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </nav>
            </aside>
            <div class="min-w-0 space-y-6">
        @if($accountingSection === 'purchase-info')
        <section class="border-l-4 border-blue-700 bg-blue-50 p-5 text-blue-950">
            <h3 class="font-semibold">商品別仕入情報</h3>
            <p class="mt-2 text-sm leading-6">仕入日・仕入単価・仕入送料・その他仕入費・仕入先は、商品登録や商品編集ではなく、ここで管理します。売却前の商品も登録できます。証憑・税区分・支払元などを確認する場合は、商品ごとの仕入会計データを開いてください。</p>
        </section>
        <section class="space-y-4 bg-white p-5 shadow-sm sm:rounded-lg">
            <form method="get" action="{{ route('furimadeck-accounting.index') }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="section" value="purchase-info">
                <label class="min-w-64 flex-1 text-sm font-semibold text-gray-800">商品名または管理ID<input type="search" name="product_query" value="{{ $purchaseInfoSearch }}" class="mt-1 block w-full rounded border-gray-400 text-gray-950"></label>
                <label class="min-w-52 text-sm font-semibold text-gray-800">仕入先<select name="supplier_id" class="mt-1 block w-full rounded border-gray-400 bg-white text-gray-950"><option value="">すべて</option>@foreach($purchaseInfoSuppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) $purchaseInfoSupplierId === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></label>
                <button class="rounded bg-gray-900 px-4 py-2 text-sm font-semibold text-white">検索</button>
                @if($purchaseInfoSearch !== '' || $purchaseInfoSupplierId !== null)<a href="{{ route('furimadeck-accounting.index', ['section' => 'purchase-info']) }}" class="px-2 py-2 text-sm font-semibold text-blue-800 underline">解除</a>@endif
            </form>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm text-gray-900">
                    <thead><tr class="border-b text-gray-700"><th class="py-2 pr-4">管理ID・商品</th><th class="py-2 pr-4">仕入日</th><th class="py-2 pr-4">原価</th><th class="py-2 pr-4">仕入費用</th><th class="py-2 pr-4">仕入先</th><th class="py-2">操作</th></tr></thead>
                    <tbody>
                    @forelse($purchaseInfoProducts as $product)
                        <tr class="border-b align-top">
                            <td class="py-3 pr-4"><span class="font-semibold">{{ $product->internal_sku }}</span><br>{{ $product->product_name }}</td>
                            <td class="py-3 pr-4 whitespace-nowrap">{{ $product->purchase_date?->format('Y/m/d') ?? '未設定' }}</td>
                            <td class="py-3 pr-4 whitespace-nowrap">{{ number_format((int) $product->purchase_unit_cost) }}円</td>
                            <td class="py-3 pr-4 whitespace-nowrap">送料 {{ number_format((int) $product->purchase_shipping_cost) }}円<br>その他 {{ number_format((int) $product->other_purchase_expense) }}円</td>
                            <td class="py-3 pr-4">{{ $product->supplier?->name ?? '未設定' }}</td>
                            <td class="py-3"><div class="flex flex-col items-start gap-1"><a class="font-semibold text-blue-800 underline" href="{{ route('furimadeck-accounting.products.purchase-info.edit', $product) }}">仕入情報を編集</a><a class="text-xs font-semibold text-blue-800 underline" href="{{ route('furimadeck-accounting.products.purchase.edit', $product) }}">証憑・仕入会計データ</a></div></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-700">該当する商品はありません。</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($purchaseInfoProducts instanceof \Illuminate\Pagination\LengthAwarePaginator)<div>{{ $purchaseInfoProducts->links() }}</div>@endif
        </section>
        @endif
        @if($accountingSection === 'overview')
        <section aria-labelledby="accounting-data-flow-title" class="rounded-md border-l-4 border-blue-700 bg-blue-50 p-5 text-blue-950">
            <h3 id="accounting-data-flow-title" class="font-semibold">会計データの流れ</h3>
            <p class="mt-1 text-sm leading-6">FurimaDeckは、売上・仕入・費用から会計サービスに登録する仕訳データを作り、会計CSVで渡すツールです。確定申告書の作成・提出は会計サービス側で行います。</p>
            <ol class="mt-3 list-inside list-decimal space-y-1 text-sm leading-6">
                <li>「入力状況」で不足を確認し、商品・売上・仕入・費用の情報を整えます。</li>
                <li>「明細の確認・確定」で、仕訳内容を領収書や販売履歴などの原資料と照合します。</li>
                <li>確認後、「CSVで登録」から会計ソフト用CSVをダウンロードし、会計サービス側で取り込んで登録します。</li>
                <li>登録後は会計サービスの仕訳帳で反映を確認します。確認済みにしただけでは登録されません。</li>
            </ol>
            <p class="mt-3 border-t border-blue-200 pt-3 text-sm leading-6">確定申告書の作成・提出と最終的な税務判断は会計サービス側で行います。元資料や区分を確認できない明細は、誤った登録を防ぐためCSV出力を止めます。</p>
        </section>
        @if($pendingSalesMissingPurchaseInfoCount > 0)
            <section class="border-l-4 border-amber-600 bg-amber-50 p-5 text-amber-950" aria-labelledby="pending-accounting-sales-title">
                <h3 id="pending-accounting-sales-title" class="font-semibold">会計確認待ちの販売記録: {{ number_format($pendingSalesMissingPurchaseInfoCount) }}件</h3>
                <p class="mt-1 text-sm leading-6">商品との照合または仕入情報が未確認の販売記録です。確認が終わるまで利益集計と会計データの対象にはなりません。</p>
                <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold">
                    <a href="{{ route('furimadeck-sales.index') }}" class="text-blue-800 underline">販売記録を確認</a>
                    <a href="{{ route('furimadeck-accounting.index', ['section' => 'purchase-info']) }}" class="text-blue-800 underline">商品別仕入情報を確認</a>
                </div>
            </section>
        @endif
        <section aria-hidden="true" class="hidden rounded-md border-l-4 border-blue-700 bg-blue-50 p-5 text-blue-950">
            <h3 id="accounting-guide-title" class="font-semibold">このページの使い方</h3>
            <p class="mt-1 text-sm leading-6">売上データを確認し、会計ソフトへ正しい内容を登録するための画面です。確定申告書の作成や提出は行いません。</p>
            <ol class="mt-3 list-inside list-decimal space-y-1 text-sm leading-6">
                <li>画面上部の期間を選ぶと、表示する売上を絞り込めます。</li>
                <li><strong>入力内容を確認する</strong：「準備状況」で不足項目を確認し、商品情報や売上記録を修正します。</li>
        <li><strong>会計データを更新する</strong>：売上記録からこの画面の確認用データを作り直します。この操作だけではCSV作成や会計ソフトへの登録はされません。</li>
        <li><strong>内容を確認して確定する</strong>：「確認待ちにする（Review）」の後、明細ごとに金額と原資料を確認して確定します。確定はCSV作成や会計ソフトへの登録を意味しません。</li>
                <li><strong>会計ソフトへ登録する</strong>：確認済みデータからCSVをダウンロードし、会計サービス側で取り込みます。</li>
            </ol>
            <p class="mt-3 text-sm leading-6">仕入日・仕入金額・仕入先を含む仕入情報、証憑・税区分・支払元・仕入照合などの会計入力は、この会計データ内で管理します。売上額など販売記録の内容を直す場合は販売記録を開いてください。会計ソフト用CSVは、対象明細を確認した後にダウンロードできます。</p>
            <p class="mt-3 border-t border-blue-200 pt-3 text-sm leading-6"><strong>登録の流れ：</strong>FurimaDeckでCSVを作成し、会計サービス側で読み込んで登録します。確認済みにしただけでは登録されません。申告前に会計ソフト側の内容と領収書などの原資料を確認してください。</p>
        </section>
        @endif
        @if(in_array($accountingSection, ['overview', 'send'], true) && $outOfSyncSentEntries->isNotEmpty())
            <section class="border-l-4 border-red-700 bg-red-50 p-5 text-red-950" aria-labelledby="accounting-reconciliation-title">
                <h3 id="accounting-reconciliation-title" class="font-semibold">会計ソフトへの登録確認が必要: {{ number_format($outOfSyncSentEntries->total()) }}件</h3>
                <p class="mt-1 text-sm">会計ソフトに登録した内容と現在の売上記録が一致しません。取消・返品・金額変更は会計ソフト側で確認してください。FurimaDeckから外部の記録を自動変更しません。</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="border-b border-red-200 text-left"><th class="py-2 pr-4">記録日</th><th class="py-2 pr-4">管理ID・商品</th><th class="py-2 pr-4">登録時の売上</th><th class="py-2 pr-4">現在の状態・売上</th><th class="py-2 pr-4">商品</th><th class="py-2 pr-4">freee登録状態</th><th class="py-2">Money Forward登録状態</th></tr></thead>
                        <tbody>
                        @foreach($outOfSyncSentEntries as $entry)
                            <tr class="border-b border-red-200">
                                <td class="py-2 pr-4">{{ $entry->transaction_date?->format('Y/m/d') }}</td>
                                <td class="py-2 pr-4">{{ $entry->management_id }}<br>{{ $entry->title }}</td>
                                <td class="py-2 pr-4">{{ number_format($entry->sale_amount) }}円</td>
                                <td class="py-2 pr-4">{{ $entry->sale ? \App\Models\Sale::statusLabel($entry->sale->status) : '売上記録なし' }}<br>@if($entry->sale){{ number_format($entry->sale->sold_price) }}円@endif</td>
                                <td class="py-2">@if($entry->sale?->product?->user_id === $entry->user_id)<a class="font-semibold underline" href="{{ route('products.edit', $entry->sale->product) }}">商品情報</a>@else<span>なし</span>@endif</td>
                                @foreach(['freee', 'money_forward'] as $provider)
                                    @php($sentProviderSync = $entry->providerSync($provider))
                                    <td class="py-2 pr-4">
                                        @if($directJournalSendEnabled && $sentProviderSync->status === 'synced' && is_array($sentProviderSync->request_payload) && filled($sentProviderSync->external_transaction_id) && $accountingConnections->get($provider)?->status === 'connected')
                                            @if(blank($sentProviderSync->provider_account_scope_hash))
                                                @include('furimadeck_accounting.partials.legacy-scope-verification-form', ['entry' => $entry, 'providerSync' => $sentProviderSync, 'provider' => $provider])
                                            @else
                                                <form method="post" action="{{ route('furimadeck-accounting.sync.verify', [$entry, $provider]) }}">@csrf<button class="text-xs font-semibold text-blue-800 underline">{{ $provider === 'freee' ? 'freee' : 'Money Forward' }}の仕訳を再照合</button></form>
                                            @endif
                                            @if($sentProviderSync->verified_at)<span class="text-xs text-gray-600">{{ $sentProviderSync->verified_at->format('Y/m/d H:i') }}時点で照合</span>@endif
                                        @elseif($sentProviderSync->status === 'csv_imported')
                                            <span class="text-xs text-gray-700">CSV取込済みを手動確認</span>
                                        @elseif($sentProviderSync->status === \App\Models\AccountingEntrySync::STATUS_MANUALLY_RECONCILED)
                                        @elseif($sentProviderSync->status === 'synced')
                                            <span class="text-xs text-amber-900">過去の会計サービス処理記録があります。登録内容を確認してください。</span>
                                        @else
                                            <span class="text-xs text-gray-700">{{ ['not_synced' => 'CSV未登録', 'queued' => '登録処理の記録あり・確認が必要', 'sending' => '登録状況の確認が必要', 'sync_unknown' => '登録結果の確認が必要', 'legacy_unknown' => '過去の登録記録の確認が必要', 'csv_pending' => 'CSV取込確認待ち'][$sentProviderSync->status] ?? '登録状態の確認が必要' }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $outOfSyncSentEntries->links() }}</div>
            </section>
        @endif
        @if(in_array($accountingSection, ['overview', 'send'], true) && $inactiveReconciliationEntries->isNotEmpty())
            <section class="border-l-4 border-amber-700 bg-amber-50 p-5 text-amber-950" aria-labelledby="inactive-accounting-reconciliation-title">
                <h3 id="inactive-accounting-reconciliation-title" class="font-semibold">取消・返品に伴う会計登録状況: {{ number_format($inactiveReconciliationEntries->total()) }}件</h3>
                <p class="mt-1 text-sm leading-6">取消・返品前後に作成された会計データの過去状態を表示します。外部に登録した内容は会計サービス側で確認してください。</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="border-b border-amber-300 text-left"><th class="py-2 pr-4">販売日</th><th class="py-2 pr-4">商品・状態</th><th class="py-2 pr-4">freee</th><th class="py-2">Money Forward</th></tr></thead>
                        <tbody>
                        @foreach($inactiveReconciliationEntries as $entry)
                            <tr class="border-b border-amber-200 align-top">
                                <td class="py-2 pr-4">{{ $entry->transaction_date?->format('Y/m/d') }}</td>
                                <td class="py-2 pr-4">{{ $entry->management_id }}<br>{{ $entry->title }}<br>{{ $entry->sale ? \App\Models\Sale::statusLabel($entry->sale->status) : '売上記録なし' }}</td>
                                @foreach(['freee', 'money_forward'] as $provider)
                                    @php($providerSync = $entry->providerSync($provider))
                                    <td class="py-2 pr-4">
                                        {{ $providerSync->status === 'not_synced' && $providerSync->last_error === \App\Models\AccountingEntrySync::VERIFIED_ABSENT_INACTIVE_SALE ? '登録なし確認済み・再出力なし' : (['queued' => '登録状況の確認が必要', 'sending' => '登録状況の確認が必要', 'sync_unknown' => '登録結果の確認が必要', 'legacy_unknown' => '過去の登録記録の確認が必要', 'csv_pending' => 'CSV取込結果の確認待ち', 'manually_reconciled' => '過去の登録確認記録あり', 'not_synced' => 'CSV未登録'][$providerSync->status] ?? '状態不明') }}
                                        @if($providerSync->status === 'csv_imported')
                                            <p class="mt-1 text-xs">CSV取込済みとして記録されています。</p>
                                        @endif
                                        @if($providerSync->status === 'queued')
                                            <p class="mt-1 text-xs">登録結果の確認が終わるまで、重複防止のためCSVの再出力を保留します。</p>
                                        @elseif($providerSync->status === 'sending')
                                            <p class="mt-1 text-xs">処理が開始された可能性があります。会計サービス側で登録有無を確認してください。</p>
                                        @elseif(in_array($providerSync->status, ['sync_unknown', 'legacy_unknown'], true))
                                            <p class="mt-1 text-xs text-amber-950">過去の登録結果を確認できないため、二重登録防止のためCSV再出力を停止しています。会計サービス側の仕訳帳・取込履歴を確認してください。</p>
                                        @elseif($providerSync->status === 'csv_pending')
                                            @if(blank($providerSync->provider_account_scope_hash))
                                                <p class="mt-2 text-xs text-amber-950">CSV出力時の会計事業者を特定する記録がないため、取込済み・未登録のどちらにも確定できません。会計サービス側の取込履歴を確認してください。</p>
                                            @else
                                            <form method="post" action="{{ route('furimadeck-accounting.sync.resolve', [$entry, $provider]) }}" class="mt-2 space-y-1">
                                                @csrf<input type="hidden" name="action" value="mark_csv_imported">
                                                <label class="block text-xs">取込履歴ID（任意）<input name="external_import_id" maxlength="255" pattern="[A-Za-z0-9][A-Za-z0-9._:-]*" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                                <p class="text-xs text-gray-700">会計サービス側の取込履歴IDが分からない場合は空欄で構いません。</p>
                                                <label class="flex items-start gap-1 text-xs"><input type="checkbox" name="import_confirmed" value="1" required class="mt-0.5">会計サービスの仕訳帳で登録を確認した</label>
                                                <button class="text-xs font-semibold text-emerald-900 underline">CSV取込済みを記録</button>
                                            </form>
                                            <form method="post" action="{{ route('furimadeck-accounting.sync.resolve', [$entry, $provider]) }}" class="mt-2">
                                                @csrf<input type="hidden" name="action" value="retry">
                                                <label class="flex items-start gap-1 text-xs"><input type="checkbox" name="verified_absent" value="1" required class="mt-0.5">会計サービスに仕訳がないことを確認した</label>
                                                <button class="mt-1 text-xs font-semibold text-amber-950 underline">登録なしを記録</button>
                                            </form>
                                            @endif
                                        @elseif($providerSync->status === 'not_synced' && $providerSync->last_error === \App\Models\AccountingEntrySync::VERIFIED_ABSENT_INACTIVE_SALE)
                                            <p class="mt-1 text-xs">会計サービスに登録がないことを確認済みです。取消・返品済みのため再出力しません。</p>
                                        @endif
                                        @if($directJournalSendEnabled && $providerSync->last_error)<p class="mt-1 text-xs">{{ $providerSync->last_error_label }}</p>@endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $inactiveReconciliationEntries->links() }}</div>
            </section>
        @endif
        @if(in_array($accountingSection, ['overview', 'review', 'send', 'csv'], true))
        <form method="get" action="{{ route('furimadeck-accounting.index') }}" class="bg-white p-4 shadow-sm sm:rounded-lg flex flex-wrap items-end gap-4">
            <input type="hidden" name="section" value="{{ $accountingSection }}">
            <label class="text-sm text-gray-700">対象年<input type="number" name="year" min="2000" max="2100" value="{{ $year }}" class="mt-1 block rounded-md border-gray-300"></label>
            <span class="text-sm text-gray-500">または任意期間</span>
            <label class="text-sm text-gray-700">開始日<input type="date" name="from" value="{{ $fromInput }}" class="mt-1 block rounded-md border-gray-300"></label>
            <label class="text-sm text-gray-700">終了日<input type="date" name="to" value="{{ $toInput }}" class="mt-1 block rounded-md border-gray-300"></label>
            <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">確認</button>
        </form>
        @if($accountingSection === 'csv')
        <section class="border-l-4 border-amber-700 bg-amber-50 p-5 text-amber-950"><h3 class="font-semibold">Money Forward CSVの注意</h3><p class="mt-2 text-sm leading-6">CSVはUTF-8（BOM付き）です。取込画面では文字コード「その他（UTF-8）」を選び、「Excel用（Shift-JIS）」は選ばないでください。課税状態が確認済みの期間は、Money Forwardの勘定科目税区分と、借方・貸方ごとに保存したインボイス区分を出力します。CSVで表せない区分や取引日と合わない控除率がある場合は、誤登録防止のため出力を停止します。Money Forwardサポート回答（2026/10/26）では、免税事業者は消費税関連機能を利用できず、仕訳CSVの税区分やインボイス欄に値を入力した場合、その内容は仕訳メモとして取り込まれる想定です。FurimaDeckの最新CSVは両欄を空欄にしますが、空欄の実際の表示と仕訳内容は登録前にプレビューで確認してください。課税・免税状態が未確認の期間は出力しません。Money Forward上の設定表示だけで自分の税務上の状態を判断せず、CSV取込前に原資料と照合してください。</p></section>
        @endif
        @endif
        @if($accountingSection === 'review')
        @if($purchaseCandidates->isNotEmpty())
            <section class="border-l-4 border-amber-600 bg-amber-50 p-5 text-amber-950" aria-labelledby="purchase-candidates-title">
                <h3 id="purchase-candidates-title" class="font-semibold">既存商品の仕入情報・照合待ち</h3>
                <p class="mt-2 text-sm leading-6">既存商品の仕入日・金額だけでは、会計ソフトの期首在庫や既存仕訳との重複を判定できません。この一覧から過去の仕入仕訳は作成しません。仕入れ・期首在庫が会計サービス側の記録と照合されるまで、関連する売上仕訳全体を会計CSVの対象から保留します。</p>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm text-gray-900">
                        <thead><tr class="border-b border-amber-300 text-left"><th class="py-2 pr-3">仕入日</th><th class="py-2 pr-3">管理ID・商品</th><th class="py-2 pr-3">仕入額</th><th class="py-2">仕入送料・その他</th></tr></thead>
                        <tbody>
                        @foreach($purchaseCandidates as $product)
                            <tr class="border-b border-amber-200">
                                <td class="py-2 pr-3 whitespace-nowrap">{{ $product->purchase_date->format('Y/m/d') }}</td>
                                <td class="py-2 pr-3">{{ $product->internal_sku }}<br>{{ $product->product_name }}<br><a href="{{ route('furimadeck-accounting.products.purchase.edit', $product) }}" class="text-xs font-semibold text-blue-800 underline">会計データを確認・照合</a></td>
                                <td class="py-2 pr-3 whitespace-nowrap">{{ number_format($product->purchase_unit_cost * $product->purchase_quantity) }}円</td>
                                <td class="py-2 whitespace-nowrap">送料 {{ number_format($product->purchase_shipping_cost) }}円 / その他 {{ number_format($product->other_purchase_expense) }}円</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($purchaseCandidates->count() === 100)<p class="mt-2 text-xs">表示上限100件です。期間を絞ってください。</p>@endif
            </section>
        @endif
        @if($provisionalCancellationCount > 0)
            <section class="border-l-4 border-sky-700 bg-sky-50 p-4 text-sky-950">
                <h3 class="font-semibold">未照合取込の取消: {{ number_format($provisionalCancellationCount) }}件</h3>
                <p class="mt-1 text-sm">売上確定前に取り消された記録です。通常の売上CSVと取消・返品の照合用CSVには含めていません。販売記録から確認できます。</p>
            </section>
        @endif
        @if($adjustmentSales->isNotEmpty())
            <section class="border-l-4 border-amber-700 bg-amber-50 p-5 text-amber-950" aria-labelledby="accounting-adjustments-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 id="accounting-adjustments-title" class="font-semibold">取消・返品の照合: {{ number_format($adjustmentSales->total()) }}件</h3>
                        <p class="mt-1 text-sm leading-6">この期間に取消・返品された取引です。日時が未記録の旧データは、元の販売日の期間に表示します。過去の取消は、取消前に売上が確定していたか自動判定できない場合があります。通常の売上CSVからは除外されます。照合用CSVは会計ソフトへ取り込む仕訳ではありません。まず会計ソフトの仕訳帳で元の販売仕訳を探してください。見つかった仕訳は削除・上書きせず、返金額や返送送料など実際の記録を確認して訂正方法を決めてください。処理方法が分からない場合は、ここから登録せず税理士等に確認してください。元の仕訳がないと確認できた場合も、取消・返品済みの売上は再登録しません。税務上の調整額はここで自動計算しません。</p>
                    </div>
                    <a href="{{ route('furimadeck-accounting.adjustments.csv', ['year' => $year, 'from' => request('from'), 'to' => request('to')]) }}" class="border border-amber-700 px-3 py-2 text-sm font-semibold text-amber-950">照合用CSV</a>
                </div>
                <div class="mt-3 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="border-b border-amber-300 text-left"><th class="py-2 pr-4">取消・返品日</th><th class="py-2 pr-4">元の販売日</th><th class="py-2 pr-4">商品</th><th class="py-2 pr-4">状態</th><th class="py-2 pr-4">元の売価</th><th class="py-2 pr-4">返金額</th><th class="py-2">返送送料</th></tr></thead><tbody>
                    @foreach($adjustmentSales as $sale)
                        @php($eventAt = $sale->status === 'cancelled' ? $sale->cancelled_at : $sale->returned_at)
                        <tr class="border-b border-amber-200">
                            <td class="py-2 pr-4">{{ $eventAt?->format('Y/m/d') ?? '未記録' }}</td>
                            <td class="py-2 pr-4">{{ $sale->sold_at?->format('Y/m/d') }}</td>
                            <td class="py-2 pr-4">{{ $sale->internal_sku_snapshot }}<br>{{ $sale->product_name_snapshot }}</td>
                            <td class="py-2 pr-4">{{ \App\Models\Sale::statusLabel($sale->status) }}</td>
                            <td class="py-2 pr-4">{{ number_format($sale->sold_price) }}円</td>
                            <td class="py-2 pr-4">{{ $sale->refund_amount === null ? '未記録' : number_format($sale->refund_amount).'円' }}</td>
                            <td class="py-2">{{ $sale->return_shipping_fee === null ? '未記録' : number_format($sale->return_shipping_fee).'円' }}</td>
                        </tr>
                    @endforeach
                </tbody></table></div>
                <div class="mt-3">{{ $adjustmentSales->links() }}</div>
            </section>
        @endif
        @endif
        @if($accountingSection === 'overview')
        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">対象の販売</p><p class="mt-2 text-2xl font-bold">{{ number_format($report['sales_count']) }}件</p></div><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">値あり（0円含む）</p><p class="mt-2 text-2xl font-bold text-emerald-700">{{ number_format($report['confirmed_count']) }}件</p></div><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">値が不足</p><p class="mt-2 text-2xl font-bold text-amber-700">{{ number_format($report['confirmation_target_count']) }}件</p></div><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">売上</p><p class="mt-2 text-2xl font-bold">{{ number_format($report['sales_amount']) }}円</p></div><div class="bg-white p-5 shadow-sm sm:rounded-lg"><p class="text-sm text-gray-500">実利益（管理用）</p><p class="mt-2 text-2xl font-bold">{{ number_format($report['profit']) }}円</p></div></section>
        <section class="bg-white p-6 shadow-sm sm:rounded-lg"><h3 class="font-semibold text-gray-900">{{ $report['period_label'] }} 準備状況</h3><p class="mt-2 text-sm text-gray-600">対象期間: {{ $report['period']['from']->format('Y/m/d') }} ～ {{ $report['period']['to']->format('Y/m/d') }}</p><p class="mt-1 text-sm leading-6 text-gray-600">ここでは値の有無だけを確認します。0円は初期値の場合もあるため、入力済み・税務上正しいことの証明にはなりません。領収書と販売履歴を別途照合してください。</p><div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="border-b text-left"><th class="py-2 pr-4">確認項目</th><th class="py-2 pr-4">対象</th><th class="py-2 pr-4">値あり</th><th class="py-2">状態</th></tr></thead><tbody>@foreach ($report['checks'] as $key => $check)<tr class="border-b"><td class="py-2 pr-4">{{ ['sold_at' => '売上日時', 'sold_price' => '売価', 'cost_basis' => '原価', 'sales_fee' => '販売手数料', 'shipping_fee' => '送料', 'other_expense' => 'その他費用', 'purchase_date' => '仕入日', 'supplier' => '仕入先', 'management_id' => '管理ID', 'sale_status' => 'キャンセル・返品状態'][$key] ?? $key }}</td><td class="py-2 pr-4">{{ $check['total'] }}件</td><td class="py-2 pr-4">{{ $check['complete'] }}件</td><td class="py-2">{{ $check['status'] === 'ready' ? '値あり' : '値を確認' }}</td></tr>@endforeach</tbody></table></div></section>
        <section class="border-l-4 border-amber-700 bg-amber-50 p-5 text-amber-950"><h3 class="font-semibold">利用上の注意</h3><p class="mt-2 text-sm leading-6">FurimaDeckの実利益は経営管理用の指標です。税務上の所得・必要経費・仕訳・税額を確定するものではありません。会計ソフトに登録した後も、領収書などの原資料と照合してください。</p></section>
        @if($report['accounting_sync']['status'] === 'not_available')
            <section class="bg-white p-5 shadow-sm sm:rounded-lg"><h3 class="font-semibold text-gray-900">次にすること</h3><p class="mt-2 text-sm text-gray-700">freeeまたはMoney Forwardの勘定科目・税区分を設定すると、会計ソフト用CSVを作成できます。仕訳の登録はCSVを会計サービス側で取り込んで行います。</p><a href="{{ route('furimadeck-accounting.index', ['section' => 'connections']) }}" class="mt-3 inline-block rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">会計ソフト設定を開く</a></section>
        @endif
        @endif
        @if($accountingSection === 'csv')
        <section class="bg-white p-5 shadow-sm sm:rounded-lg">
            <h3 class="font-semibold text-gray-900">CSV出力と登録</h3>
            <p class="mt-2 text-sm leading-6 text-gray-700">新しい売上記録は、未送信の確認用データとして自動で追加されます。「確認用データを更新」は既存の売上や過去分を反映し直す時に使います。共通CSVは照合用、freee CSVとMoney Forward CSVは各サービスの仕訳取込用です。ダウンロード後は会計サービスの取込プレビューで内容を確認してください。</p>
            <div class="mt-4 flex flex-wrap gap-3">
                <form method="post" action="{{ route('furimadeck-accounting.sync') }}">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">確認用データを更新</button></form>
                <a href="{{ route('furimadeck-accounting.csv', ['year' => $year, 'from' => request('from'), 'to' => request('to')]) }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">共通CSVをダウンロード</a>
                @if($accountingConnections->get('freee')?->status === 'connected')
                    @if($freeeCsvBulkExportStatus === 'ready')
                        <form method="post" action="{{ route('furimadeck-accounting.freee') }}">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><label class="mb-2 flex max-w-xl items-start gap-2 text-sm text-gray-800"><input type="checkbox" name="confirm_all_amounts_tax_inclusive" value="1" required class="mt-1 rounded border-gray-400 text-blue-700 focus:ring-blue-600"><span>出力対象のすべての金額が、領収書などの原資料どおり税込であることを確認しました。</span></label><button class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">freee CSV一括出力</button></form>
                    @elseif($freeeCsvBulkExportStatus === 'empty')
                        <p class="text-sm text-gray-700">対象期間に会計対象の販売データがありません。先に「確認用データを更新」してください。</p>
                    @elseif($freeeCsvBulkExportStatus === 'blocked')
                        <div class="text-sm text-amber-950">
                            <p>期間内にCSV出力できない販売データがあるため、一括CSVを出力できません。</p>
                            <p class="mt-1 text-xs">{{ $freeeCsvBulkExportIssue }}</p>
                        </div>
                    @else
                        <div class="text-sm text-amber-950">
                            <p>freeeの仕訳設定が不足しているため、一括CSVを出力できません。</p>
                            <ul class="mt-1 list-disc pl-5 text-xs">@foreach($freeeSyncMissingConfigurationLabels as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                            <a href="{{ route('furimadeck-accounting.freee.setup') }}" class="font-semibold text-blue-800 underline">freee仕訳設定を確認</a>
                        </div>
                    @endif
                @else
                    <div class="text-sm text-amber-950">
                        <p>freee連携が未接続または期限切れのため、一括CSVを出力できません。</p>
                        <a href="{{ route('furimadeck-accounting.index', ['section' => 'connections']) }}" class="font-semibold text-blue-800 underline">会計サービス連携を確認</a>
                    </div>
                @endif
                @if($accountingConnections->get('money_forward')?->status === 'connected')
                    @if($moneyForwardCsvBulkExportStatus === 'ready')
                        <form method="post" action="{{ route('furimadeck-accounting.money-forward') }}">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><button class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">Money Forward CSV一括出力</button></form>
                    @elseif($moneyForwardCsvBulkExportStatus === 'empty')
                        <p class="text-sm text-gray-700">対象期間に会計対象の販売データがありません。先に「確認用データを更新」してください。</p>
                    @elseif($moneyForwardCsvBulkExportStatus === 'exempt')
                        <p class="text-sm font-semibold text-amber-950">免税確認済みの日付は税区分・インボイス欄を空欄にして出力します。空欄の実際の表示と仕訳内容を、登録確定前にプレビューで確認してください。</p>
                    @elseif($moneyForwardCsvBulkExportStatus === 'unconfirmed')
                        <p class="text-sm font-semibold text-amber-950">対象期間の販売データに課税・免税状態が未確認の日付があります。一括CSVを出力できません。</p>
                    @elseif($moneyForwardCsvBulkExportStatus === 'configuration')
                        <div class="text-sm text-amber-950">
                            <p>対象期間のMoney Forward仕訳設定が不足しているため、一括CSVを出力できません。</p>
                            <ul class="mt-1 list-disc pl-5 text-xs">@foreach($moneyForwardCsvBulkExportMissingConfigurationLabels as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                            <a href="{{ route('furimadeck-accounting.money-forward.setup') }}" class="font-semibold text-blue-800 underline">Money Forward仕訳設定を確認</a>
                        </div>
                    @elseif($moneyForwardCsvBulkExportStatus === 'blocked')
                        <div class="text-sm text-amber-950">
                            <p>期間内にCSV出力できない販売データがあるため、一括CSVを出力できません。</p>
                            <p class="mt-1 text-xs">{{ $moneyForwardCsvBulkExportIssue }}</p>
                        </div>
                    @else
                        <p class="text-sm text-gray-700">Money Forward一括CSVの出力可否を確認できません。設定画面を確認してください。</p>
                    @endif
                @else
                    <div class="text-sm text-amber-950">
                        <p>Money Forward連携が未接続または期限切れのため、一括CSVを出力できません。</p>
                        <a href="{{ route('furimadeck-accounting.index', ['section' => 'connections']) }}" class="font-semibold text-blue-800 underline">会計サービス連携を確認</a>
                    </div>
                @endif
            </div>
            <p class="mt-3 text-sm leading-6 text-gray-800">会計ソフト用の一括CSVは、対応範囲内の確認済み販売データだけを出力します。仕入明細そのもののCSVはFurimaDeck 1.0では対応していません。売上CSVの原価行は、仕入・在庫との照合が済んだ場合だけ含まれます。</p>
            <div class="mt-3 grid gap-3 text-sm leading-6 md:grid-cols-2">
                <div class="border border-gray-200 p-4">
                    <h4 class="font-semibold text-gray-900">freee CSVの登録</h4>
                    <p class="mt-1 text-gray-700">freeeの「取引入力」→「振替伝票」→「インポート」→「他社会計ソフト仕訳のインポート」でCSVを選びます。税込・税抜などの金額の扱いを事業所の設定に合わせ、プレビューで日付・金額・勘定科目・税区分を確認してから登録してください。</p>
                    <p class="mt-2 border-l-4 border-blue-700 bg-blue-50 p-3 text-sm font-semibold leading-6 text-blue-950">「読み込み項目の設定」では、CSVの「伝票番号」をfreeeの「伝票番号」に割り当ててください。この設定がないと、同じ伝票にまとめる売上行と原価行が別々の伝票として取り込まれることがあります。</p>
                    <h5 class="mt-3 font-semibold text-gray-900">freeeで出力対象になる取引</h5>
                    <p class="mt-1 text-gray-800">確認済みの販売記録が対象です。原価は仕入・期首在庫との照合が済んだ場合だけ含みます。仕入CSVと別途支払った費用を含む販売CSVは、FurimaDeck 1.0では出力しません。</p>
                    <p class="mt-2 border-l-4 border-amber-600 bg-amber-50 p-3 text-sm font-semibold leading-6 text-amber-950">販売手数料を含む明細は実取込の検証が未完了のため出力しません。販売送料が0円でない明細、または梱包費・修理費・清掃費・その他費用がある明細も対象外です。仕入・在庫の照合や科目・税区分が未確認の場合も停止します。対象外の仕入や費用はfreee側で別途登録してください。</p>
                    <p class="mt-2 border-l-4 border-amber-600 bg-amber-50 p-3 text-sm font-semibold leading-6 text-amber-950">このCSVには税額列がありません。ダウンロード時に、出力対象のすべての金額が原資料どおり税込であることを確認してください。freeeの取込設定でも「全ての金額が内税（税込）※税額自動計算あり」を選びます。税抜金額が混在する、または税込か判断できない場合はチェックを入れず、登録しないでください。設定を誤ると、税額を0円として取り込む危険があります。</p>
                    <p class="mt-1 text-gray-700">登録後、下の明細でfreee側に登録されたことを確認し、「CSV取込済みを記録」を押します。</p>
                </div>
                <div class="border border-gray-200 p-4">
                    <h4 class="font-semibold text-gray-900">Money Forward CSVの登録</h4>
                    <p class="mt-1 text-gray-700">Money Forwardの「会計帳簿」→「仕訳帳」→「インポート」→「仕訳帳」でCSVを選びます。プレビューで日付・勘定科目・税区分・インボイス区分を原資料と照合し、正しい内容だと確認してから保存してください。</p>
                    <p class="mt-2 border-l-4 border-amber-600 bg-amber-50 p-3 text-sm font-semibold leading-6 text-amber-950">Money Forwardが取り込めるのは、現在選択中の会計年度内の日付だけです。年度をまたぐ場合は、会計年度に合わせてFurimaDeckの期間を分けてCSVを出力してください。インポートは新規追加のみで、登録済み仕訳は更新されません。同じCSVを再取込すると重複登録になるため、再取込前に仕訳帳と取込履歴を確認してください。</p>
                    <h5 class="mt-3 font-semibold text-gray-900">Money Forwardで出力対象になる取引</h5>
                    <p class="mt-1 text-gray-800">課税・免税いずれかの状態が取引日に確認できる期間にある、確認済みの販売記録が対象です。課税期間は勘定科目・税区分・借方と貸方それぞれのインボイス区分が必要です。免税確認済みの期間は税区分・インボイス欄を空欄にして出力します。いずれも仕入・在庫との照合が必要です。</p>
                    <p class="mt-2 border-l-4 border-amber-600 bg-amber-50 p-3 text-sm font-semibold leading-6 text-amber-950">販売手数料を含む明細は実取込の検証が未完了のため出力しません。FurimaDeck 1.0では仕入CSVと別途支払った費用を含む販売CSVも対象外です。販売送料が0円でない場合、または課税・免税状態が未確認の期間も出力を停止します。免税期間のCSVは、登録確定前にMoney Forwardのプレビューで仕訳として読み込まれることを確認してください。</p>
                    <p class="mt-1 text-gray-700">取込後、会計サービス側に登録されたことを確認してから、下の明細で「CSV取込済みを記録」を押します。</p>
                </div>
            </div>
            <p class="mt-3 text-sm font-semibold text-amber-950">CSVを会計サービスに取り込むと仕訳が登録されます。CSV出力後は重複防止のため同じ明細の再出力を停止します。再出力する場合は、会計ソフトに登録されていないことを先に確認してください。取込済みチェックは、会計ソフト側で登録を確認した後にだけ入れてください。</p>
        </section>
        @endif
        @if($accountingSection === 'review')
        <section class="bg-white p-5 shadow-sm sm:rounded-lg">
            <h3 class="font-semibold text-gray-900">会計CSV登録前の確認</h3>
            <p class="mt-2 text-sm text-gray-600">対象期間の会計データを確認待ちにし、明細ごとに金額・費用と原資料を照合してください。確認済みの明細から、出力条件を満たす会計ソフト用CSVを作成できます。ここで確定しただけではCSV作成や会計ソフトへの登録はされません。</p>
            <div class="mt-4"><form method="post" action="{{ route('furimadeck-accounting.review') }}">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><button class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">確認待ちにする（Review）</button></form></div>
        </section>
        @if($report['source_review_items']->isNotEmpty())
            <section class="bg-white p-5 shadow-sm sm:rounded-lg" aria-labelledby="source-review-title">
                <h3 id="source-review-title" class="font-semibold text-gray-900">原資料との照合が必要な取引: {{ number_format($report['source_review_items']->count()) }}件</h3>
                <p class="mt-2 text-sm text-gray-700">「未記録」は値がありません。「0円」は実際に0円だった場合と初期値を区別できないため、販売履歴・領収書などで確認してください。ここで税務上の処理を判定するものではありません。</p>
                <details class="mt-3">
                    <summary class="cursor-pointer text-sm font-semibold text-blue-800">取引ごとの確認項目を見る</summary>
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-sm text-gray-800">
                            <thead><tr class="border-b text-left"><th class="py-2 pr-4">販売日</th><th class="py-2 pr-4">管理ID・商品</th><th class="py-2 pr-4">未記録</th><th class="py-2 pr-4">0円の確認</th><th class="py-2">商品</th></tr></thead>
                            <tbody>
                            @foreach($report['source_review_items'] as $item)
                                <tr class="border-b">
                                    <td class="py-2 pr-4">{{ $item['sold_at']?->format('Y/m/d') }}</td>
                                    <td class="py-2 pr-4">{{ $item['management_id'] }}<br>{{ $item['title'] }}</td>
                                    <td class="py-2 pr-4">{{ $item['missing'] === [] ? 'なし' : implode('、', $item['missing']) }}</td>
                                    <td class="py-2 pr-4">{{ $item['zero'] === [] ? 'なし' : implode('、', $item['zero']) }}</td>
                                    <td class="py-2">@if($item['product_id'] && (in_array('仕入日', $item['missing'], true) || in_array('仕入先', $item['missing'], true)))<a class="font-semibold text-blue-800 underline" href="{{ route('furimadeck-accounting.products.purchase-info.edit', $item['product_id']) }}">会計データの仕入情報を修正</a>@else<span>確認のみ</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            </section>
        @endif
        <section class="bg-white p-5 shadow-sm sm:rounded-lg">
            <h3 class="font-semibold text-gray-900">明細を確認して確定</h3>
            <p class="mt-2 text-sm leading-6 text-gray-700">CSVを作る前に、販売記録・領収書などの原資料と金額を照合してください。確認済みデータから出力条件を満たす明細だけ会計ソフト用CSVを作成し、会計サービス側で取り込みます。</p>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm text-gray-800">
                    <thead><tr class="border-b text-left"><th class="py-2 pr-4">日付</th><th class="py-2 pr-4">商品</th><th class="py-2 pr-4">取引額</th><th class="py-2 pr-4">費用・内訳</th><th class="py-2 pr-4">確認状態</th><th class="py-2">操作</th></tr></thead>
                    <tbody>
                    @forelse($accountingEntries as $entry)
                        <tr class="border-b">
                            <td class="py-2 pr-4 whitespace-nowrap">{{ $entry->transaction_date?->format('Y/m/d') }}</td>
                            <td class="py-2 pr-4">{{ $entry->title }}<br>@if($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE && $entry->product)<a href="{{ route('furimadeck-accounting.products.purchase.edit', $entry->product) }}" class="text-xs font-semibold text-blue-800 underline">仕入会計データを確認</a>@elseif($entry->sale)<a href="{{ route('furimadeck-accounting.sales.expenses.edit', $entry->sale) }}" class="text-xs font-semibold text-blue-800 underline">販売会計データを確認</a>@endif</td>
                            <td class="py-2 pr-4 whitespace-nowrap"><span class="font-semibold">{{ $entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE ? '仕入支出' : '売上' }} {{ number_format($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE ? $entry->purchase_cost + $entry->other_cost : $entry->sale_amount) }}円</span></td>
                            <td class="py-2 pr-4 whitespace-nowrap">
                                @if($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE)
                                    商品仕入 {{ number_format($entry->purchase_cost) }}円 / 仕入送料 {{ number_format($entry->expense_breakdown['purchase_shipping_cost'] ?? 0) }}円<br>
                                    その他仕入費 {{ number_format($entry->expense_breakdown['other_purchase_expense'] ?? 0) }}円
                                    <p class="mt-1 max-w-xs whitespace-normal text-xs font-semibold text-amber-900">各費用の証憑区分は会計データ画面で確認してください。会計サービスごとの支払元・税区分が未設定または一致しない場合、そのサービス向けCSVは停止します。</p>
                                @else
                                    原価 {{ number_format($entry->purchase_cost) }}円 / 手数料 {{ number_format($entry->selling_fee) }}円<br>
                                    送料 {{ number_format($entry->shipping_cost) }}円 / その他 {{ number_format($entry->other_cost) }}円<br>
                                    <span class="font-semibold">実利益 {{ number_format($entry->furimadeck_actual_profit) }}円</span>
                                <details class="mt-1"><summary class="cursor-pointer text-blue-800">その他費用の内訳</summary>
                                    @if($entry->expense_breakdown === null)
                                        <p class="text-amber-900">旧データのため内訳は未記録です。合計額は上に表示しています。</p>
                                    @else
                                        <dl class="mt-1 grid grid-cols-2 gap-x-3">
                                            <dt>仕入送料</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['purchase_shipping_cost'] ?? 0) }}円</dd>
                                            <dt>梱包費</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['packing_cost'] ?? 0) }}円</dd>
                                            <dt>修理費</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['repair_cost'] ?? 0) }}円</dd>
                                            <dt>クリーニング費</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['cleaning_cost'] ?? 0) }}円</dd>
                                            <dt>その他経費</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['other_expense'] ?? 0) }}円</dd>
                                        </dl>
                                    @endif
                                </details>
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                @if($entry->source_status === 'confirmed' && $entry->confirmed_at !== null)
                                    <span class="font-semibold text-emerald-800">確認済み</span><br><span class="text-xs text-gray-600">{{ $entry->confirmed_at->format('Y/m/d H:i') }}</span>
                                @elseif($entry->source_status === 'confirmed')
                                    <span class="font-semibold text-amber-900">旧データ・再確認が必要</span>
                                @elseif($entry->source_status === 'review')
                                    <span class="font-semibold text-amber-900">確認待ち</span>
                                @else
                                    未確認
                                @endif
                            </td>
                            <td class="py-2">
                                @if($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE)
                                <p class="mb-2 max-w-xs text-xs leading-5 text-amber-950">領収書などと金額・税区分・発行者区分を照合してから確定してください。この仕入明細はFurimaDeck 1.0の会計ソフト用CSV出力対象外です。仕入仕訳はfreeeまたはMoney Forward側で別途登録し、会計データの仕入画面で手動照合してください。商品情報・明細が一致し、照合が完了するまで関連する売上CSVの原価行は保留されます。</p>
                                @endif
                                @if($entry->source_status === 'review' && $entry->sync_status === 'not_synced')
                                    <form method="post" action="{{ route('furimadeck-accounting.confirm') }}" class="space-y-2">
                                        @csrf
                                        <input type="hidden" name="accounting_entry_id" value="{{ $entry->id }}">
                                        <input type="hidden" name="year" value="{{ $year }}">
                                        <input type="hidden" name="from" value="{{ request('from') }}">
                                        <input type="hidden" name="to" value="{{ request('to') }}">
                                        <label class="flex items-start gap-2 text-xs text-gray-800"><input type="checkbox" name="reviewed_source" value="1" required class="mt-0.5">金額・費用を原資料と照合した</label>
                                        <button class="rounded bg-gray-900 px-2 py-1 text-xs font-semibold text-white">この明細を確定</button>
                                    </form>
                                @elseif($entry->source_status === 'confirmed' && $entry->confirmed_at === null)
                                    <span class="text-xs text-amber-900">確認待ちに戻して再確認してください。</span>
                                @else
                                    <span class="text-xs text-gray-600">操作なし</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-gray-600">対象期間の会計データはありません。</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $accountingEntries->links() }}</div>
        </section>
        @endif
        @if($accountingSection === 'send')
        <section class="bg-white p-5 shadow-sm sm:rounded-lg">
            <h3 class="font-semibold text-gray-900">CSV取込状況</h3>
            <p class="mt-2 text-sm text-gray-600">会計ソフトでCSVを取り込んだ後、仕訳帳で登録結果を確認し、この画面に記録します。</p>
            @if($directJournalSendEnabled)
            <div class="mt-4 flex flex-wrap items-center gap-3">
                    @if($accountingConnections->get('freee')?->status === 'connected' && $freeeSyncMissingConfiguration === [] && $freeeBatchCount > 0)
                        <form method="post" action="{{ route('furimadeck-accounting.send.freee.batch') }}" data-confirm="この期間のConfirm済み{{ number_format($freeeBatchCount) }}件をfreeeへ一括送信します。よろしいですか？">
                            @csrf
                            <input type="hidden" name="year" value="{{ $year }}">
                            <input type="hidden" name="from" value="{{ request('from') }}">
                            <input type="hidden" name="to" value="{{ request('to') }}">
                            <button class="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white">freeeへ一括送信（{{ number_format($freeeBatchCount) }}件）</button>
                        </form>
                    @elseif($freeeBatchCount === 0)
                        <span class="text-sm text-gray-600">freee: 一括送信できるConfirm済み未送信データはありません。</span>
                    @elseif($freeeSyncMissingConfiguration !== [])
                        <div class="text-sm text-amber-950">
                            <a href="{{ route('furimadeck-accounting.freee.setup') }}" class="font-semibold underline">freeeの不足設定を確認</a>
                            <ul class="mt-2 list-disc space-y-1 pl-5">@foreach($freeeSyncMissingConfigurationLabels as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                        </div>
                    @else
                        <span class="text-sm text-gray-600">freeeと連携すると一括送信できます。</span>
                    @endif
                    @if($accountingConnections->get('money_forward')?->status === 'connected' && $moneyForwardSyncMissingConfiguration === [] && $moneyForwardBatchCount > 0)
                        <form method="post" action="{{ route('furimadeck-accounting.send.money-forward.batch') }}" data-confirm="この期間のConfirm済み{{ number_format($moneyForwardBatchCount) }}件をMoney Forwardへ一括送信します。よろしいですか？">
                            @csrf
                            <input type="hidden" name="year" value="{{ $year }}">
                            <input type="hidden" name="from" value="{{ request('from') }}">
                            <input type="hidden" name="to" value="{{ request('to') }}">
                            <button class="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white">Money Forwardへ一括送信（{{ number_format($moneyForwardBatchCount) }}件）</button>
                        </form>
                    @elseif($moneyForwardBatchCount === 0)
                        <span class="text-sm text-gray-600">Money Forward: 一括送信できるConfirm済み未送信データはありません。</span>
                    @elseif($moneyForwardSyncMissingConfiguration !== [])
                        <div class="text-sm text-amber-950">
                            <a href="{{ route('furimadeck-accounting.money-forward.setup') }}" class="font-semibold underline">Money Forwardの不足設定を確認</a>
                            <ul class="mt-2 list-disc space-y-1 pl-5">@foreach($moneyForwardSyncMissingConfigurationLabels as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                        </div>
                    @else
                        <span class="text-sm text-gray-600">Money Forwardと連携すると一括送信できます。</span>
                    @endif
            </div>
            @endif
            @if($directJournalSendEnabled && $recentAccountingBatches->isNotEmpty())
                <div class="mt-5 border-t border-gray-200 pt-4">
                    <h4 class="font-semibold text-gray-900">一括送信の履歴</h4>
                    <div class="mt-2 space-y-3">
                        @foreach($recentAccountingBatches as $batch)
                            @php($batchProviderName = $batch->provider === 'freee' ? 'freee' : 'Money Forward')
                            <div class="rounded border border-gray-200 p-3">
                                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span class="font-semibold text-gray-900">{{ $batchProviderName }} / {{ $batch->period_from->format('Y/m/d') }}～{{ $batch->period_to->format('Y/m/d') }}</span>
                                    <span class="text-gray-700">{{ ['queued' => '待機中', 'running' => '処理中', 'completed' => '完了', 'completed_with_errors' => '一部失敗'][$batch->status] ?? $batch->status }}</span>
                                </div>
                                <p class="mt-1 text-sm text-gray-700">{{ number_format($batch->processed_count) }} / {{ number_format($batch->total_count) }}件処理、成功 {{ number_format($batch->succeeded_count) }}件、失敗 {{ number_format($batch->failed_count) }}件</p>
                                @if($batch->items->isNotEmpty())
                                    <ul class="mt-2 space-y-1 text-sm text-red-800">
                                        @foreach($batch->items as $item)
                                            <li>{{ $item->management_id }} {{ $item->title }}: {{ $item->status === 'uncertain' ? $batchProviderName.'側を確認してください。' : $item->diagnostic }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-[70rem] text-sm">
                    <thead><tr class="border-b text-left whitespace-nowrap"><th class="py-2 pr-4">日付</th><th class="py-2 pr-4">商品</th><th class="py-2 pr-4">売上・費用</th><th class="py-2 pr-4">確認状態</th><th class="py-2 pr-4">freee</th><th class="py-2 pr-4">freee操作</th><th class="py-2 pr-4">Money Forward</th><th class="py-2">Money Forward操作</th></tr></thead>
                    <tbody>
                    @forelse($accountingEntries as $entry)
                        @php($freeeProviderSync = $entry->providerSync('freee'))
                        @php($freeeCsvScopeIssue = $providerCsvScopeIssues[$entry->id]['freee'] ?? null)
                        @php($moneyForwardCsvScopeIssue = $providerCsvScopeIssues[$entry->id]['money_forward'] ?? null)
                        @php($freeePurchaseIssue = $purchaseReconciliationIssues['freee'][$entry->id] ?? null)
                        @php($freeeAccountingReview = $freeeProviderSync->last_error === \App\Models\AccountingEntrySync::EXTERNAL_VOUCHER_DELETED_TAX_REVIEW)
                        @php($freeeLegacyScopePending = $freeeProviderSync->status === 'synced' && $freeeProviderSync->request_payload !== null && filled($freeeProviderSync->external_transaction_id) && blank($freeeProviderSync->provider_account_scope_hash))
                        @php($moneyForwardProviderSync = $entry->providerSync('money_forward'))
                        @php($moneyForwardPurchaseIssue = $purchaseReconciliationIssues['money_forward'][$entry->id] ?? null)
                        @php($moneyForwardLegacyScopePending = $moneyForwardProviderSync->status === 'synced' && $moneyForwardProviderSync->request_payload !== null && filled($moneyForwardProviderSync->external_transaction_id) && blank($moneyForwardProviderSync->provider_account_scope_hash))
                                @php($providerStatusLabels = ['synced' => '以前の登録記録あり・確認が必要', 'queued' => '登録状況の確認が必要', 'sending' => '登録状況の確認が必要', 'failed' => '以前の処理記録あり・確認が必要', 'sync_unknown' => '登録結果の確認が必要', 'legacy_unknown' => '過去の登録記録の確認が必要', 'csv_pending' => 'CSV取込結果の確認待ち', 'csv_imported' => 'CSV取込済み・手動確認', 'manually_reconciled' => '過去の登録確認記録あり', 'not_synced' => 'CSV未登録'])
                        @php($freeeDisabledApiStatusLabel = $freeeProviderSync->last_error === 'provider_api_disabled' ? 'CSV未登録' : null)
                        @php($moneyForwardDisabledApiStatusLabel = $moneyForwardProviderSync->last_error === 'provider_api_disabled' ? 'CSV未登録' : null)
                        <tr class="border-b align-top">
                            <td class="py-2 pr-4">{{ $entry->transaction_date?->format('Y/m/d') }}</td>
                            <td class="py-2 pr-4">{{ $entry->title }}<br>@if($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE && $entry->product)<a href="{{ route('furimadeck-accounting.products.purchase.edit', $entry->product) }}" class="text-xs font-semibold text-blue-800 underline">仕入会計データを確認</a>@elseif($entry->sale)<a href="{{ route('furimadeck-accounting.sales.expenses.edit', $entry->sale) }}" class="text-xs font-semibold text-blue-800 underline">販売会計データを確認</a>@endif</td>
                            <td class="py-2 pr-4 whitespace-nowrap">
                                @if($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE)
                                    <span class="font-semibold">仕入支出 {{ number_format($entry->purchase_cost + $entry->other_cost) }}円</span>
                                @else
                                <span class="font-semibold">売上 {{ number_format($entry->sale_amount) }}円</span><br>
                                原価 {{ number_format($entry->purchase_cost) }}円 / 手数料 {{ number_format($entry->selling_fee) }}円<br>
                                送料 {{ number_format($entry->shipping_cost) }}円 / その他 {{ number_format($entry->other_cost) }}円<br>
                                <span class="font-semibold">実利益 {{ number_format($entry->furimadeck_actual_profit) }}円</span>
                                <details class="mt-1"><summary class="cursor-pointer text-blue-800">その他費用の内訳</summary>
                                    @if($entry->expense_breakdown === null)
                                        <p class="text-amber-900">旧データのため内訳は未記録です。合計額は上に表示しています。</p>
                                    @else
                                        <dl class="mt-1 grid grid-cols-2 gap-x-3">
                                            <dt>仕入送料</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['purchase_shipping_cost'] ?? 0) }}円</dd>
                                            <dt>梱包費</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['packing_cost'] ?? 0) }}円</dd>
                                            <dt>修理費</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['repair_cost'] ?? 0) }}円</dd>
                                            <dt>クリーニング費</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['cleaning_cost'] ?? 0) }}円</dd>
                                            <dt>その他経費</dt><dd class="text-right">{{ number_format($entry->expense_breakdown['other_expense'] ?? 0) }}円</dd>
                                        </dl>
                                    @endif
                                </details>
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                @if($entry->source_status === 'confirmed' && $entry->confirmed_at !== null)
                                    確認済み<br><span class="text-xs text-gray-600">{{ $entry->confirmed_at->format('Y/m/d H:i') }}</span>
                                @elseif($entry->source_status === 'confirmed')
                                    <span class="font-semibold text-amber-900">旧データ・再確認が必要</span>
                                @elseif($entry->source_status === 'review')
                                    確認待ち
                                @else
                                    未確定
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                {{ $freeeAccountingReview ? '削除済み・会計確認待ち' : ($directJournalSendEnabled && $freeeLegacyScopePending ? '登録先の確認が必要' : ($freeeDisabledApiStatusLabel ?? ($directJournalSendEnabled && $freeeProviderSync->verified_at ? '登録内容の確認済み' : ($providerStatusLabels[$freeeProviderSync->status] ?? '状態不明')))) }}
                                @if($directJournalSendEnabled && $freeeProviderSync->verified_at)
                                    <br><span class="text-xs text-gray-600">@if($freeeLegacyScopePending)過去の照合日時（事業者未紐付け）: @endif{{ $freeeProviderSync->verified_at->format('Y/m/d H:i') }}時点</span>
                                @endif
                                @if($freeeProviderSync->status === \App\Models\AccountingEntrySync::STATUS_CSV_IMPORTED)
                                    <br><span class="text-xs text-gray-700">取込履歴ID: {{ $freeeProviderSync->external_import_id }}</span>
                                @endif
                            </td>
                            <td class="py-2 pr-4">
                                @if($freeeAccountingReview)
                                    <span class="text-xs text-amber-900">税区分が誤っていたテスト仕訳はfreee側で削除済みです。会計処理の確認が終わるまで再送しません。</span>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE && in_array($freeeProviderSync->status, ['not_synced', 'failed'], true))
                                    <span class="text-xs leading-5 text-amber-900">freee用仕入CSVはFurimaDeck 1.0の対応対象外です。仕入仕訳はfreee側で別途登録してください。</span>
                                @elseif($freeePurchaseIssue !== null && in_array($freeeProviderSync->status, ['not_synced', 'failed'], true))
                                    <span class="text-xs font-semibold text-amber-900">仕入・期首在庫の確認待ちで保留中です。{{ $freeePurchaseIssue }}</span>
                                    @if($entry->sale?->product?->user_id === $entry->user_id)<br><a href="{{ route('furimadeck-accounting.products.purchase.edit', $entry->sale->product) }}" class="text-xs font-semibold text-blue-800 underline">会計データで仕入・期首在庫を確認</a>@endif
                                @elseif($directJournalSendEnabled && $entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($freeeProviderSync->status, ['not_synced', 'failed'], true) && $accountingConnections->get('freee')?->status === 'connected' && $freeeSyncMissingConfiguration === [] && ($purchasePaymentSourceReady[$entry->id]['freee'] ?? false))
                                    <form method="post" action="{{ route('furimadeck-accounting.send.freee', $entry) }}">@csrf<button class="rounded-md bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white">freeeへ送信</button></form>
                                @elseif($freeeProviderSync->status === 'synced' && $directJournalSendEnabled && $freeeProviderSync->request_payload !== null && $accountingConnections->get('freee')?->status === 'connected')
                                    @if(blank($freeeProviderSync->provider_account_scope_hash))
                                        @include('furimadeck_accounting.partials.legacy-scope-verification-form', ['entry' => $entry, 'providerSync' => $freeeProviderSync, 'provider' => 'freee'])
                                    @else
                                        <form method="post" action="{{ route('furimadeck-accounting.sync.verify', [$entry, 'freee']) }}">@csrf<button class="text-xs font-semibold text-blue-800 underline">freeeの内容を照合</button></form>
                                    @endif
                                @elseif($freeeProviderSync->status === 'synced')
                                    <span class="text-xs text-amber-900">過去の会計サービス処理記録があります。freee側で登録内容を確認してください。</span>
                                @elseif($freeeProviderSync->status === 'csv_pending')
                                    @if(blank($freeeProviderSync->provider_account_scope_hash))
                                        <p class="text-xs leading-5 text-amber-950">CSV出力時の会計事業者を特定する記録がないため、取込済み・未登録のどちらにも確定できません。会計サービス側の取込履歴を確認してください。</p>
                                    @else
                                    <form method="post" action="{{ route('furimadeck-accounting.sync.resolve', [$entry, 'freee']) }}" class="space-y-1">
                                        @csrf
                                        <input type="hidden" name="action" value="mark_csv_imported">
                                        <label class="block text-xs">取込履歴ID（任意）<input name="external_import_id" maxlength="255" pattern="[A-Za-z0-9][A-Za-z0-9._:-]*" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                        <p class="text-xs text-gray-700">会計サービス側の取込履歴IDが分からない場合は空欄で構いません。</p>
                                        <label class="flex items-start gap-1 text-xs text-amber-900"><input type="checkbox" name="import_confirmed" value="1" required class="mt-0.5">freeeの仕訳帳で登録内容を確認した</label>
                                        <button class="text-xs font-semibold text-emerald-800 underline">CSV取込済みを記録</button>
                                    </form>
                                    @if(blank($freeeProviderSync->external_transaction_id))
                                        <form method="post" action="{{ route('furimadeck-accounting.sync.resolve', [$entry, 'freee']) }}" class="mt-2">
                                            @csrf
                                            <input type="hidden" name="action" value="retry">
                                            <p class="text-xs leading-5 text-gray-800">CSVを受け取れたか、取込が完了したか不明でも、すぐ再出力しないでください。まずCSV出力時と同じ会計事業者の仕訳帳・取込履歴を確認します。登録済みなら「CSV取込済みを記録」、未登録と確認できた場合だけ下のボタンを押してください。判断できない場合は何も押さず、二重登録を避けてください。</p>
                                            <label class="flex items-start gap-1 text-xs text-amber-900"><input type="checkbox" name="verified_absent" value="1" required class="mt-0.5">freeeに該当仕訳がないことを確認</label>
                                            <button class="mt-1 text-xs font-semibold text-amber-900 underline">登録なしを記録</button>
                                        </form>
                                    @endif
                                    @endif
                                @elseif(in_array($freeeProviderSync->status, ['sync_unknown', 'legacy_unknown'], true))
                                    <p class="text-xs leading-5 text-amber-900">過去の登録結果を確認できないため、二重登録防止のためCSV再出力を停止しています。freeeの仕訳帳・取込履歴を確認してください。</p>
                                @elseif(in_array($freeeProviderSync->status, ['queued', 'sending'], true))
                                    @if(! $directJournalSendEnabled)
                                        <span class="text-xs text-amber-900">登録状況が未確定の記録があります。freee側で登録有無を確認してください。</span>
                                    @else
                                        <span class="text-xs text-gray-600">一括送信の処理中です。</span>
                                    @endif
                                @else<span class="text-xs text-gray-600">操作なし</span>@endif
                                @if($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && $freeeCsvScopeIssue === null && ($providerCsvCurrentIssues[$entry->id] ?? null) === null && $freeePurchaseIssue === null && $entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($freeeProviderSync->status, ['not_synced', 'failed'], true) && blank($freeeProviderSync->external_transaction_id) && blank($freeeProviderSync->external_import_id) && $freeeCsvExportReady)
                                    <form method="post" action="{{ route('furimadeck-accounting.freee') }}" class="mt-2">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><input type="hidden" name="accounting_entry_id" value="{{ $entry->id }}"><label class="mb-1 flex items-start gap-1 text-xs text-gray-800"><input type="checkbox" name="confirm_all_amounts_tax_inclusive" value="1" required class="mt-0.5 rounded border-gray-400 text-blue-700 focus:ring-blue-600"><span>この明細の金額が原資料どおり税込</span></label><button class="text-xs font-semibold text-blue-800 underline">freee 1件CSV</button></form>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE && in_array($freeeProviderSync->status, ['not_synced', 'failed'], true))
                                    <p class="mt-2 text-xs leading-5 text-amber-900">freee用仕入CSVはFurimaDeck 1.0の対応対象外です。freee側で別途登録してください。</p>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && $freeeCsvScopeIssue !== null && in_array($freeeProviderSync->status, ['not_synced', 'failed'], true))
                                    <p class="mt-2 text-xs leading-5 text-amber-900">{{ $freeeCsvScopeIssue }}</p>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && ($providerCsvCurrentIssues[$entry->id] ?? null) !== null && in_array($freeeProviderSync->status, ['not_synced', 'failed'], true))
                                    <p class="mt-2 text-xs leading-5 text-amber-900">確認後に販売データが変更されたため、freee CSVを出力できません。<a class="font-semibold text-blue-800 underline" href="{{ route('furimadeck-accounting.index', ['year' => $year, 'from' => request('from'), 'to' => request('to'), 'section' => 'csv']) }}">CSVで登録へ移動して「確認用データを更新」</a>を実行し、その後「明細の確認・確定」で原資料と照合し直してください。{{ $providerCsvCurrentIssues[$entry->id] }}</p>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && $freeePurchaseIssue === null && $entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($freeeProviderSync->status, ['not_synced', 'failed'], true) && blank($freeeProviderSync->external_transaction_id) && blank($freeeProviderSync->external_import_id) && ! $freeeCsvExportReady)
                                    @if($accountingConnections->get('freee')?->status !== 'connected')
                                        <p class="mt-2 text-xs leading-5 text-amber-900">freee連携が未接続または期限切れのため、CSVを出力できません。</p>
                                        <a href="{{ route('furimadeck-accounting.index', ['section' => 'connections']) }}" class="text-xs font-semibold text-blue-800 underline">会計サービス連携を確認</a>
                                    @else
                                        <p class="mt-2 text-xs leading-5 text-amber-900">freeeの仕訳設定が不足しているため、CSVを出力できません。</p>
                                        <ul class="mt-1 list-disc pl-4 text-xs text-amber-900">@foreach($freeeSyncMissingConfigurationLabels as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                                        <a href="{{ route('furimadeck-accounting.freee.setup') }}" class="text-xs font-semibold text-blue-800 underline">freee仕訳設定を確認</a>
                                    @endif
                                @endif
                                @if($directJournalSendEnabled && $freeeProviderSync->last_error && ! $freeeAccountingReview)<p class="mt-1 text-xs text-red-800">診断: {{ $freeeProviderSync->last_error_label }}</p>@endif
                            </td>
                            <td class="py-2 pr-4">
                                {{ $directJournalSendEnabled && $moneyForwardProviderSync->hasManuallyConfirmedProviderInvoiceKindDefault() ? 'MF既定のインボイス区分を手動確認済み' : ($directJournalSendEnabled && $moneyForwardProviderSync->hasUnverifiedProviderInvoiceKindDefault() ? ($moneyForwardLegacyScopePending ? '登録先未確認・インボイス区分未照合' : '登録済み仕訳・インボイス区分未照合') : ($directJournalSendEnabled && $moneyForwardLegacyScopePending ? '登録先の確認が必要' : ($moneyForwardDisabledApiStatusLabel ?? ($moneyForwardProviderSync->verified_at && $directJournalSendEnabled ? '登録内容の確認済み' : ($providerStatusLabels[$moneyForwardProviderSync->status] ?? '状態不明'))))) }}
                                @if($directJournalSendEnabled && $moneyForwardProviderSync->hasManuallyConfirmedProviderInvoiceKindDefault())
                                    <br><span class="text-xs text-gray-700">利用者確認: {{ $moneyForwardProviderSync->manual_invoice_kind_confirmed_at->format('Y/m/d H:i') }}（会計ソフトの最新値を読取確認）</span>
                                @endif
                                @if($directJournalSendEnabled && $moneyForwardProviderSync->verified_at)
                                    <br><span class="text-xs text-gray-600">@if($moneyForwardLegacyScopePending)過去の照合日時（事業者未紐付け）: @endif{{ $moneyForwardProviderSync->verified_at->format('Y/m/d H:i') }}時点</span>
                                @endif
                                @if($moneyForwardProviderSync->status === \App\Models\AccountingEntrySync::STATUS_CSV_IMPORTED)
                                    <br><span class="text-xs text-gray-700">取込履歴ID: {{ $moneyForwardProviderSync->external_import_id }}</span>
                                @endif
                            </td>
                            <td class="py-2">
                                @if($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true))
                                    <span class="text-xs leading-5 text-amber-900">Money Forward用仕入CSVはFurimaDeck 1.0の対応対象外です。仕入仕訳はMoney Forward側で別途登録してください。</span>
                                @elseif($moneyForwardPurchaseIssue !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true))
                                    <span class="text-xs font-semibold text-amber-900">仕入・期首在庫の確認待ちで保留中です。{{ $moneyForwardPurchaseIssue }}</span>
                                    @if($entry->sale?->product?->user_id === $entry->user_id)<br><a href="{{ route('furimadeck-accounting.products.purchase.edit', $entry->sale->product) }}" class="text-xs font-semibold text-blue-800 underline">会計データで仕入・期首在庫を確認</a>@endif
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && $entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true) && ($moneyForwardEntryCsvExportStatus[$entry->id] ?? 'connection') === 'exempt')
                                    <span class="text-xs leading-5 text-amber-900">免税確認済みの期間です。税区分・インボイス欄は空欄で出力します。Money Forwardサポート回答では、これらの欄に値を入力した場合は仕訳メモに取り込まれる想定とのことです。登録確定前にプレビューを確認してください。</span>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && $entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true) && ($moneyForwardEntryCsvExportStatus[$entry->id] ?? 'connection') === 'unconfirmed')
                                    <span class="text-xs leading-5 text-amber-900">この取引日に適用するMoney Forward事業者の課税・免税状態が未確認のため、CSVを出力していません。</span>
                                    <a href="{{ route('furimadeck-accounting.money-forward.setup') }}" class="text-xs font-semibold text-blue-800 underline">課税・免税状態と仕訳設定を確認</a>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && $entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true) && ($moneyForwardEntryCsvExportStatus[$entry->id] ?? 'connection') === 'connection')
                                    <span class="text-xs leading-5 text-amber-900">Money Forward連携が未接続または期限切れのため、CSVを出力できません。</span>
                                    <a href="{{ route('furimadeck-accounting.index', ['section' => 'connections']) }}" class="text-xs font-semibold text-blue-800 underline">会計サービス連携を確認</a>
                                @elseif($entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true) && $accountingConnections->get('money_forward')?->status === 'connected' && ($moneyForwardEntryMissingConfiguration[$entry->id] ?? []) !== [])
                                    <span class="text-xs leading-5 text-amber-900">この取引日に適用するMoney Forward設定が不足しています。期間と設定を確認するまで送信を停止しています。</span>
                                    <ul class="mt-1 list-disc pl-4 text-xs text-amber-900">@foreach($moneyForwardEntryMissingConfigurationLabels[$entry->id] ?? [] as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                                    <a href="{{ route('furimadeck-accounting.money-forward.setup') }}" class="text-xs font-semibold text-blue-800 underline">Money Forward仕訳設定を確認</a>
                                @elseif($directJournalSendEnabled && $entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true) && $accountingConnections->get('money_forward')?->status === 'connected' && ($moneyForwardEntryMissingConfiguration[$entry->id] ?? []) === [] && ($purchasePaymentSourceReady[$entry->id]['money_forward'] ?? false))
                                    <form method="post" action="{{ route('furimadeck-accounting.send.money-forward', $entry) }}">@csrf<button class="rounded-md bg-blue-700 px-3 py-1.5 text-xs font-semibold text-white">Money Forwardへ送信</button></form>
                                @elseif($moneyForwardProviderSync->status === 'synced' && $directJournalSendEnabled && $moneyForwardProviderSync->request_payload !== null && $accountingConnections->get('money_forward')?->status === 'connected')
                                    @if(blank($moneyForwardProviderSync->provider_account_scope_hash))
                                        @include('furimadeck_accounting.partials.legacy-scope-verification-form', ['entry' => $entry, 'providerSync' => $moneyForwardProviderSync, 'provider' => 'money_forward'])
                                    @else
                                        @if($moneyForwardProviderSync->hasUnverifiedProviderInvoiceKindDefault() && ! $moneyForwardProviderSync->hasManuallyConfirmedProviderInvoiceKindDefault())
                                            @if($moneyForwardBusinessTaxStatusConfirmedByEntry[$entry->id] ?? false)
                                                <form method="post" action="{{ route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']) }}" class="mb-2 space-y-1 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-950">
                                                    @csrf
                                                    <input type="hidden" name="action" value="confirm_mf_default_invoice_kind">
                                                    <p>Money Forwardの仕訳帳を開き、この仕訳のインボイス区分が事業者の状態に照らして正しいと確認した場合だけ記録してください。送信・変更は行いません。</p>
                                                    <label class="flex items-start gap-1"><input type="checkbox" name="confirm_mf_invoice_kind_correct" value="1" required class="mt-0.5">MF側の区分が正しいことを確認した</label>
                                                    <label class="flex items-start gap-1"><input type="checkbox" name="confirm_mf_no_resend" value="1" required class="mt-0.5">仕訳の再送・変更を行わないことを確認した</label>
                                                    <button class="font-semibold text-amber-950 underline">最新値を再読込して手動確認を記録</button>
                                                </form>
                                            @else
                                                <p class="mb-2 text-xs leading-5 text-amber-950">課税・免税状態が未確認のため、手動確認操作はありません。先に会計サービス側の状態を確認し、仕訳設定を更新してください。</p>
                                            @endif
                                        @elseif($moneyForwardProviderSync->hasManuallyConfirmedProviderInvoiceKindDefault())
                                            <p class="mb-2 text-xs leading-5 text-gray-700">手動確認の証拠と履歴を保存しています。登録後は再送・変更できません。</p>
                                        @endif
                                        <form method="post" action="{{ route('furimadeck-accounting.sync.verify', [$entry, 'money_forward']) }}">@csrf<button class="text-xs font-semibold text-blue-800 underline">Money Forwardの内容を照合</button></form>
                                    @endif
                                @elseif($moneyForwardProviderSync->status === 'synced')
                                    <span class="text-xs text-amber-900">過去の会計サービス処理記録があります。Money Forward側で登録内容を確認してください。</span>
                                @elseif($moneyForwardProviderSync->status === 'csv_pending')
                                    @if(blank($moneyForwardProviderSync->provider_account_scope_hash))
                                        <p class="text-xs leading-5 text-amber-950">CSV出力時の会計事業者を特定する記録がないため、取込済み・未登録のどちらにも確定できません。会計サービス側の取込履歴を確認してください。</p>
                                    @else
                                    <form method="post" action="{{ route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']) }}" class="space-y-1">
                                        @csrf
                                        <input type="hidden" name="action" value="mark_csv_imported">
                                        <label class="block text-xs">取込履歴ID（任意）<input name="external_import_id" maxlength="255" pattern="[A-Za-z0-9][A-Za-z0-9._:-]*" class="mt-1 w-full rounded border-gray-300 text-xs"></label>
                                        <p class="text-xs text-gray-700">会計サービス側の取込履歴IDが分からない場合は空欄で構いません。</p>
                                        <label class="flex items-start gap-1 text-xs text-amber-900"><input type="checkbox" name="import_confirmed" value="1" required class="mt-0.5">Money Forwardの仕訳帳で登録内容を確認した</label>
                                        <button class="text-xs font-semibold text-emerald-800 underline">CSV取込済みを記録</button>
                                    </form>
                                    @if(blank($moneyForwardProviderSync->external_transaction_id))
                                        <form method="post" action="{{ route('furimadeck-accounting.sync.resolve', [$entry, 'money_forward']) }}" class="mt-2">
                                            @csrf
                                            <input type="hidden" name="action" value="retry">
                                            <p class="text-xs leading-5 text-gray-800">CSVを受け取れたか、取込が完了したか不明でも、すぐ再出力しないでください。まずCSV出力時と同じ会計事業者の仕訳帳・取込履歴を確認します。登録済みなら「CSV取込済みを記録」、未登録と確認できた場合だけ下のボタンを押してください。判断できない場合は何も押さず、二重登録を避けてください。</p>
                                            <label class="flex items-start gap-1 text-xs text-amber-900"><input type="checkbox" name="verified_absent" value="1" required class="mt-0.5">Money Forwardに該当仕訳がないことを確認</label>
                                            <button class="mt-1 text-xs font-semibold text-amber-900 underline">登録なしを記録</button>
                                        </form>
                                    @endif
                                    @endif
                                @elseif(in_array($moneyForwardProviderSync->status, ['sync_unknown', 'legacy_unknown'], true))
                                    <p class="text-xs leading-5 text-amber-900">過去の登録結果を確認できないため、二重登録防止のためCSV再出力を停止しています。Money Forwardの仕訳帳・取込履歴を確認してください。</p>
                                @elseif($moneyForwardProviderSync->status === 'queued' || $moneyForwardProviderSync->status === 'sending')
                                    <span class="text-xs text-amber-900">登録状況が未確定の記録があります。Money Forward側で登録有無を確認してください。</span>
                                @else<span class="text-xs text-gray-600">操作なし</span>@endif
                                @if($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && $moneyForwardCsvScopeIssue === null && ($providerCsvCurrentIssues[$entry->id] ?? null) === null && $moneyForwardPurchaseIssue === null && $entry->source_status === 'confirmed' && $entry->confirmed_at !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true) && blank($moneyForwardProviderSync->external_transaction_id) && blank($moneyForwardProviderSync->external_import_id) && in_array(($moneyForwardEntryCsvExportStatus[$entry->id] ?? 'connection'), ['ready', 'exempt'], true))
                                    <form method="post" action="{{ route('furimadeck-accounting.money-forward') }}" class="mt-2">@csrf<input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="from" value="{{ request('from') }}"><input type="hidden" name="to" value="{{ request('to') }}"><input type="hidden" name="accounting_entry_id" value="{{ $entry->id }}"><button class="text-xs font-semibold text-blue-800 underline">Money Forward 1件CSV</button></form>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && $moneyForwardCsvScopeIssue !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true))
                                    <p class="mt-2 text-xs leading-5 text-amber-900">{{ $moneyForwardCsvScopeIssue }}</p>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_SALE && ($providerCsvCurrentIssues[$entry->id] ?? null) !== null && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true))
                                    <p class="mt-2 text-xs leading-5 text-amber-900">確認後に販売データが変更されたため、Money Forward CSVを出力できません。<a class="font-semibold text-blue-800 underline" href="{{ route('furimadeck-accounting.index', ['year' => $year, 'from' => request('from'), 'to' => request('to'), 'section' => 'csv']) }}">CSVで登録へ移動して「確認用データを更新」</a>を実行し、その後「明細の確認・確定」で原資料と照合し直してください。{{ $providerCsvCurrentIssues[$entry->id] }}</p>
                                @elseif($entry->entry_type === \App\Models\AccountingEntry::TYPE_PURCHASE && in_array($moneyForwardProviderSync->status, ['not_synced', 'failed'], true))
                                    <p class="mt-2 text-xs leading-5 text-amber-900">Money Forward用仕入CSVはFurimaDeck 1.0の対応対象外です。Money Forward側で別途登録してください。</p>
                                @endif
                                @if($directJournalSendEnabled && $moneyForwardProviderSync->last_error)
                                    @if($moneyForwardProviderSync->hasUnverifiedProviderInvoiceKindDefault())
                                        <p class="mt-1 text-xs font-semibold leading-5 text-amber-950">送信していないインボイス区分に「対象外」が返りました。正しい区分か確認できるまで未照合です。</p>
                                    @elseif($moneyForwardProviderSync->hasInvoiceKindMismatch())
                                        <p class="mt-1 text-xs font-semibold leading-5 text-amber-950">インボイス区分不一致。詳しい案内はこの行の下にあります。</p>
                                    @else
                                        <p class="mt-1 text-xs leading-5 text-red-800">{{ $moneyForwardProviderSync->last_error_label }}</p>
                                    @endif
                                @endif
                            </td>
                        </tr>
                        @if($directJournalSendEnabled && $moneyForwardProviderSync->hasInvoiceKindMismatch())
                            <tr class="border-b bg-amber-50">
                                <td colspan="8" class="px-3 py-3 text-sm leading-6 text-amber-950">
                                    <div class="max-w-3xl">
                                        <p>送信時とMoney Forward側のインボイス区分が一致しません。登録済み仕訳は自動修正・再送しません。</p>
                                        <p class="mt-1">Money Forwardの該当仕訳と事業者設定を確認してください。税務上の課税・免税や登録状況が不明なら、状態未確認のままにして区分変更・再送を止めてください。</p>
                                        <a href="{{ route('furimadeck-accounting.money-forward.setup') }}" class="font-semibold text-blue-800 underline">Money Forwardの仕訳設定を確認</a>
                                    </div>
                                </td>
                            </tr>
                        @elseif($directJournalSendEnabled && $moneyForwardProviderSync->hasUnverifiedProviderInvoiceKindDefault())
                            <tr class="border-b bg-amber-50">
                                <td colspan="8" class="px-3 py-3 text-sm leading-6 text-amber-950">
                                    <div class="max-w-3xl">
                                        <p>日付・金額・勘定科目など、FurimaDeckから送信した項目は一致しました。ただし、送信していないインボイス区分に「対象外」が返りました。正しい区分か確認できないため、インボイス区分は照合済みとしていません。</p>
                                        <p class="mt-1">Money Forwardの仕訳と事業者設定を確認してください。税務上の区分は推測して変更せず、登録済み仕訳の自動修正・再送も行いません。</p>
                                        @if($moneyForwardProviderSync->hasManuallyConfirmedProviderInvoiceKindDefault())
                                            <p class="mt-1">手動確認の日時・確認者・読戻し証拠を監査記録に保存しています。後日の再読込で内容が変わった場合は、この確認状態を解除します。</p>
                                        @elseif($moneyForwardBusinessTaxStatusConfirmedByEntry[$entry->id] ?? false)
                                            <p class="mt-1">MFの仕訳帳で既定区分が正しいと確認できる場合は、右欄の「最新値を再読込して手動確認を記録」を使用できます。</p>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="8" class="py-4 text-gray-500">対象期間の会計データはありません。</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $accountingEntries->links() }}</div>
        </section>
        @endif
        @if($accountingSection === 'connections')
        <section class="border-l-4 border-blue-700 bg-blue-50 p-5 text-blue-950"><h3 class="font-semibold">会計サービスをCSV用に接続する</h3><p class="mt-2 text-sm leading-6">freee・Money Forwardの勘定科目・税区分候補を取得し、CSVへ正しく対応付けるために接続します。この接続から仕訳を送信することはありません。確認済みCSVを会計サービス側で取り込んでください。</p></section>
        <section class="bg-white p-5 shadow-sm sm:rounded-lg">
    <h3 class="font-semibold text-gray-900">会計サービス連携</h3>
    <p class="mt-2 text-sm text-gray-600">接続情報は暗号化して保存します。接続はCSV用の勘定科目・税区分候補取得に使用します。連携解除しても設定と過去の取込履歴は保持します。</p>
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        @foreach(['freee' => 'freee', 'money_forward' => 'Money Forward'] as $provider => $label)
            @php($connection = $accountingConnections->get($provider))
            @php($connectionState = match ($connection?->status) { 'connected' => ['label' => '連携済み', 'class' => 'text-emerald-700'], 'expired' => ['label' => '再連携が必要', 'class' => 'text-red-800'], default => ['label' => '未連携', 'class' => 'text-gray-600'] })
            <div class="rounded-md border border-gray-200 p-4">
                <div class="flex items-center justify-between gap-3">
                    <h4 class="font-semibold text-gray-900">{{ $label }}</h4>
                    <span class="text-sm font-semibold {{ $connectionState['class'] }}">{{ $connectionState['label'] }}</span>
                </div>
                @if($connection?->status === 'connected')
                    @if($provider === 'money_forward')
                        @if($moneyForwardSyncMissingConfiguration === [])
                            @if($moneyForwardTaxableBusinessTaxPeriodsCount > 0 || $moneyForwardExemptBusinessTaxPeriodsCount > 0)
                                <p class="mt-2 text-xs text-emerald-800">CSV用の仕訳設定済みです。課税・免税を確認済みとした期間は、明細条件も満たせばCSVを作成できます。</p>
                            @else
                                <p class="mt-2 text-xs font-semibold text-amber-950">課税・免税の状態を確認済みとした期間がないため、現在Money Forward用CSVを作成できる期間はありません。</p>
                            @endif
                            @if($moneyForwardExemptBusinessTaxPeriodsCount > 0)<p class="mt-2 text-xs font-semibold text-amber-950">免税確認済みの期間が{{ $moneyForwardExemptBusinessTaxPeriodsCount }}件あります。CSVの税区分・インボイス欄は空欄になります。空欄の実際の表示と仕訳内容は、登録確定前にプレビューで確認してください。</p>@endif
                            @if($moneyForwardUnconfirmedBusinessTaxPeriodsCount > 0)<p class="mt-2 text-xs font-semibold text-amber-950">状態未確認の対象期間が{{ $moneyForwardUnconfirmedBusinessTaxPeriodsCount }}件あります。その期間の日付に該当する明細はMoney Forward CSVを出力できません。</p>@endif
                        @else
                            <p class="mt-2 text-xs font-semibold text-amber-950">CSV出力前に次の設定が必要です。</p>
                            <ul class="mt-1 list-disc space-y-1 pl-5 text-xs text-amber-950">@foreach($moneyForwardSyncMissingConfigurationLabels as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                        @endif
                        <p class="mt-2 text-xs text-gray-700">仕入支払元候補は保存済み会計データのため保持されます。FurimaDeck 1.0では仕入CSVを出力しないため、この候補設定は売上CSVに不要です。</p>
                        <a href="{{ route('furimadeck-accounting.money-forward.setup') }}" class="mt-2 inline-block rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-800 hover:bg-gray-100">Money Forward仕訳設定を開く</a>
                        @if(filled($connection->last_error))
                            <p class="mt-2 break-all text-xs font-semibold text-red-800">直近のエラー診断情報: {{ $connection->last_error }}</p>
                        @endif
                    @else
                        @if($freeeSyncMissingConfiguration === [])
                            <p class="mt-2 text-xs text-gray-600">freee連携済みです。勘定科目・税区分を確認して、freee用CSVを作成できます。</p>
                        @else
                            <p class="mt-2 text-xs font-semibold text-amber-950">CSV出力前に次の設定が必要です。</p>
                            <ul class="mt-1 list-disc space-y-1 pl-5 text-xs text-amber-950">@foreach($freeeSyncMissingConfigurationLabels as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                            <a href="{{ route('furimadeck-accounting.freee.setup') }}" class="mt-2 inline-block rounded-md border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">freee仕訳設定を開く</a>
                        @endif
                        <p class="mt-2 text-xs text-gray-700">仕入支払元候補は保存済み会計データのため保持されます。FurimaDeck 1.0では仕入CSVを出力しないため、この候補設定は売上CSVに不要です。</p>
                    @endif
                    <p class="mt-2 text-xs text-gray-600">最終接続確認: {{ $connection->last_synced_at?->format('Y/m/d H:i') ?? '未確認' }}</p>
                    <form method="post" action="{{ route('furimadeck-accounting.disconnect', $provider) }}" class="mt-3">@csrf @method('DELETE')<button class="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700">連携解除</button></form>
                @else
                    @if($connection?->status === 'expired')
                        <p class="mt-2 rounded border border-red-200 bg-red-50 p-3 text-sm leading-6 text-red-900">認証更新を完了できず、自動再試行を停止しました。会計サービスと再連携してください。保存済みの仕訳設定と送信履歴は保持されます。</p>
                    @endif
                    <a href="{{ route('furimadeck-accounting.connect', $provider) }}" class="mt-3 inline-block rounded-md bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white">{{ $connection?->status === 'expired' ? '再連携する' : '連携する' }}</a>
                @endif
            </div>
        @endforeach
    </div>
</section>
        @endif
            </div>
        </div>
    </div></div>
</x-app-layout>

@if($recentAccountingBatches->contains(fn ($batch) => in_array($batch->status, ['queued', 'running'], true)))
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        window.setTimeout(() => window.location.reload(), 5000);
    </script>
@endif










