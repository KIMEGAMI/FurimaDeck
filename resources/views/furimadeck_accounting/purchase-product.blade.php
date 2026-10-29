<x-app-layout>
    <x-slot name="header"><h2 class="text-2xl font-black text-cyan-200">会計データ・仕入管理</h2></x-slot>
    <div class="min-h-screen bg-slate-100 py-8">
        <div class="mx-auto max-w-4xl px-4">
            @if(session('success'))<p class="mb-4 rounded bg-emerald-50 p-3 font-bold text-emerald-800">{{ session('success') }}</p>@endif
            @if(session('error'))<p class="mb-4 rounded bg-red-50 p-3 font-bold text-red-800">{{ session('error') }}</p>@endif
            @if($errors->any())<p class="mb-4 rounded border border-red-300 bg-red-50 p-3 font-bold text-red-900">{{ $errors->first() }}</p>@endif
            <nav class="mb-4 flex flex-wrap gap-4 text-sm font-bold">
                <a href="{{ route('furimadeck-accounting.index', ['section' => 'purchase-info']) }}" class="text-blue-800 underline">商品別仕入情報一覧へ</a>
                <a href="{{ route('furimadeck-accounting.index', ['section' => 'review']) }}" class="text-blue-800 underline">会計データ一覧へ戻る</a>
            </nav>
            @if($accountingContext ?? false)
                <section class="mb-6 rounded-lg border border-cyan-300 bg-white p-6 shadow" aria-labelledby="purchase-info-heading">
                    <h3 id="purchase-info-heading" class="text-lg font-black text-slate-900">仕入情報</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-800">仕入日・金額・仕入先は仕入情報画面で管理します。保存した金額は商品原価や見込み利益の計算に使われます。</p>
                    <dl class="mt-3 grid gap-2 rounded bg-slate-50 p-4 text-sm text-slate-800 sm:grid-cols-2">
                        <div><dt class="font-bold">仕入日・仕入総額</dt><dd>{{ $product->purchase_date?->format('Y/m/d') ?? '未設定' }} / {{ number_format((int) $product->purchase_unit_cost * (int) $product->purchase_quantity + (int) $product->purchase_shipping_cost + (int) $product->other_purchase_expense) }}円</dd></div>
                        <div><dt class="font-bold">仕入先</dt><dd>{{ $product->supplier?->name ?? '未設定' }}</dd></div>
                    </dl>
                    <a href="{{ route('furimadeck-accounting.products.purchase-info.edit', $product) }}" class="mt-3 inline-flex rounded border border-cyan-800 bg-white px-3 py-2 text-sm font-bold text-cyan-950 hover:bg-cyan-50">仕入情報を確認・編集</a>
                </section>
                <section class="mb-6 rounded-lg border border-cyan-300 bg-white p-6 shadow" aria-labelledby="accounting-purchase-entry-heading">
                    <h3 id="accounting-purchase-entry-heading" class="text-lg font-black text-slate-900">仕入会計データ</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-800">商品情報と分けて、会計データはこの画面で確認・保存します。仕入情報を会計明細にする場合は、証憑と一致する内容だけ入力してください。保存しても会計サービスへは送信されません。</p>
                    @if($product->purchase_accounting_enabled)
                        <div class="mt-4 rounded border-2 border-red-700 bg-red-50 p-4 text-sm font-semibold leading-6 text-red-950" role="alert">
                            <h4 class="font-black">重要：この仕入は会計サービスへ登録されていません</h4>
                            <p class="mt-1">FurimaDeck 1.0ではfreee・Money Forward向けの仕入CSVを出力しません。この画面の仕入会計データはFurimaDeck内だけに保存されています。会計サービス側に仕入を別途登録した後、この画面下部の「仕入記録の手動照合」で仕訳ID・日付・金額・勘定科目・費用ごとの税区分を照合してください。確認できるまでは、この商品を含む売上CSVは保留されます。照合は記録のみで、会計サービスへの送信・作成・更新は行いません。</p>
                        </div>
                    @else
                        <div class="mt-4 rounded border-2 border-amber-700 bg-amber-50 p-4 text-sm font-semibold leading-6 text-amber-950">
                            <h4 class="font-black">仕入CSVは1.0の対応対象外です</h4>
                            <p class="mt-1">ここで仕入会計データを保存しても、freee・Money Forwardには登録されません。新しい仕入の場合は、まずここに仕入情報と証憑を保存し、会計サービス側にも仕入を別途登録した後、この画面下部の「仕入記録の手動照合」で確認してください。商品情報と会計明細が一致し、照合が完了するまで売上CSVは保留されます。</p>
                        </div>
                    @endif
                    <dl class="mt-3 grid gap-2 text-sm text-slate-800 sm:grid-cols-2"><div><dt class="font-bold">商品</dt><dd>{{ $product->internal_sku }} / {{ $product->product_name }}</dd></div><div><dt class="font-bold">仕入日・仕入総額</dt><dd>{{ $product->purchase_date?->format('Y/m/d') ?? '未設定' }} / {{ number_format((int) $product->purchase_unit_cost * (int) $product->purchase_quantity + (int) $product->purchase_shipping_cost + (int) $product->other_purchase_expense) }}円</dd></div></dl>
                    @if($product->purchase_accounting_enabled)
                        <p class="mt-3 rounded border border-emerald-300 bg-emerald-50 p-3 text-sm font-bold text-emerald-900">この仕入は会計明細として管理されています。</p>
                    @endif
                    @if($canCreatePurchaseEntry)
                    <form method="POST" action="{{ route('furimadeck-accounting.products.purchase.update', $product) }}" class="mt-4 space-y-5">
                        @csrf
                        @if(!$product->purchase_accounting_enabled)
                            <label class="flex items-start gap-2 text-sm font-bold text-slate-900"><input required type="checkbox" name="confirm_new_purchase" value="1" class="mt-1"><span>これは新しい仕入で、過去の仕入・期首在庫ではなく、会計サービス側にもまだ登録していないことを確認しました。</span></label>
                        @endif
                        <div class="grid gap-4 xl:grid-cols-3">
                            @foreach(['purchase_unit_cost' => '商品代', 'purchase_shipping_cost' => '仕入送料', 'other_purchase_expense' => 'その他仕入費'] as $expenseKey => $expenseLabel)
                                @php($expenseEvidence = (array) old('purchase_evidence.'.$expenseKey, (array) ($product->purchase_evidence[$expenseKey] ?? [])))
                                <fieldset class="rounded border border-slate-300 bg-slate-50 p-3">
                                    <legend class="px-1 text-sm font-black text-slate-900">{{ $expenseLabel }}</legend>
                                    <div class="grid gap-3">
                                        <label class="block text-sm font-bold text-slate-800">証憑の種類<select name="purchase_evidence[{{ $expenseKey }}][document_type]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900"><option value="">選択してください</option><option value="receipt" @selected(($expenseEvidence['document_type'] ?? '') === 'receipt')>領収書</option><option value="invoice" @selected(($expenseEvidence['document_type'] ?? '') === 'invoice')>請求書</option><option value="other" @selected(($expenseEvidence['document_type'] ?? '') === 'other')>その他</option></select></label>
                                        <label class="block text-sm font-bold text-slate-800">証憑の税区分<select name="purchase_evidence[{{ $expenseKey }}][tax_category]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900"><option value="">選択してください</option>@foreach(['standard_10' => '標準税率 10%', 'reduced_8' => '軽減税率 8%', 'exempt' => '非課税', 'non_taxable' => '不課税', 'out_of_scope' => '対象外', 'unknown' => '判別できない'] as $value => $label)<option value="{{ $value }}" @selected(($expenseEvidence['tax_category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
                                        <label class="block text-sm font-bold text-slate-800">証憑の発行者区分<select name="purchase_evidence[{{ $expenseKey }}][invoice_issuer]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900"><option value="">選択してください</option>@foreach(['qualified' => '適格請求書発行事業者', 'unqualified' => '適格請求書発行事業者ではない', 'not_applicable' => '対象外', 'unknown' => '判別できない'] as $value => $label)<option value="{{ $value }}" @selected(($expenseEvidence['invoice_issuer'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
                                        <label class="block text-sm font-bold text-slate-800">仕入先の年間上限確認<select name="purchase_evidence[{{ $expenseKey }}][cap_review_id]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900"><option value="">該当しない／未設定</option>@foreach($purchaseCapReviews as $review)<option value="{{ $review->id }}" @selected((string) ($expenseEvidence['cap_review_id'] ?? '') === (string) $review->id)>{{ $review->counterparty_name }} / {{ $review->period_from->format('Y/m/d') }}～{{ $review->period_to->format('Y/m/d') }}</option>@endforeach</select></label>
                                        <label class="flex items-start gap-2 text-sm font-semibold text-slate-900"><input type="checkbox" name="purchase_evidence[{{ $expenseKey }}][reviewed]" value="1" @checked(old('purchase_evidence.'.$expenseKey.'.reviewed')) class="mt-1"><span>金額・税区分・発行者と証憑日が仕入日と一致することを確認しました。</span></label>
                                    </div>
                                </fieldset>
                            @endforeach
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                            @foreach(['freee' => 'freee', 'money_forward' => 'Money Forward'] as $provider => $providerLabel)
                                @if(($purchasePaymentAccounts[$provider] ?? []) !== [])
                                    <label class="block text-sm font-bold text-slate-800">{{ $providerLabel }}で実際に使った支払元<select name="purchase_payment_sources[{{ $provider }}]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900"><option value="">選択してください</option>@foreach($purchasePaymentAccounts[$provider] as $sourceId => $sourceName)<option value="{{ $sourceId }}" @selected((string) old('purchase_payment_sources.'.$provider, $product->purchase_payment_sources[$provider] ?? '') === (string) $sourceId)>{{ $sourceName }}</option>@endforeach</select></label>
                                @endif
                            @endforeach
                        </div>
                        @include('products.partials.purchase-special-case', ['purchaseSpecialCaseEnabled' => (bool) old('purchase_special_case.enabled', !empty($product->purchase_special_case_data))])
                        <button class="rounded bg-cyan-800 px-4 py-2.5 text-sm font-black text-white hover:bg-cyan-900">仕入会計データを保存</button>
                    </form>
                    @elseif(!$product->purchase_accounting_enabled)
                        <p class="mt-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-950">この商品には既存の会計記録または手動照合があります。重複を防ぐため、新しい仕入会計データは作成できません。下の既存記録を確認してください。</p>
                    @endif
                    <p class="mt-3 text-sm font-semibold text-slate-700">仕入先の年間上限確認は<a href="{{ route('furimadeck-accounting.purchase-caps.index') }}" class="text-blue-800 underline">会計データの仕入先確認</a>で管理します。</p>
                </section>
            @endif
            @if(($accountingContext ?? false) && $manualPurchaseReconciliationEligible)
                @php($manualExpectedAmounts = [
                    'purchase_unit_cost' => (int) $product->purchase_unit_cost * (int) $product->purchase_quantity,
                    'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
                    'other_purchase_expense' => (int) $product->other_purchase_expense,
                ])
                @php($manualExpectedTotal = array_sum($manualExpectedAmounts))
                <section class="mt-6 rounded-lg border border-amber-300 bg-white p-6 shadow" aria-labelledby="manual-purchase-reconciliation-heading">
                    <h3 id="manual-purchase-reconciliation-heading" class="text-lg font-black text-slate-900">仕入記録の手動照合</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-800">FurimaDeck 1.0では仕入CSVを出力しません。仕入はfreeeまたはMoney Forward側で別途登録し、その登録済み仕訳をここで照合してください。仕訳ID・日付・金額・勘定科目・費用ごとの税区分と、仕入時に在庫計上したか費用計上したかを記録します。この操作は会計サービスへの仕訳作成・更新・再送を行いません。FurimaDeck内に仕入会計明細がある場合も、商品情報と明細が一致し、該当サービスの送信・取込・結果不明の記録がないことを確認できれば、手動照合を記録できます。照合できたサービス向けの売上CSVだけ保留が解除されます。</p>
                    <p class="mt-2 text-sm font-semibold leading-6 text-amber-950">在庫計上した金額だけを、この商品が売れた時の原価として扱います。仕入時に費用計上した金額は、二重計上を避けるため売却時の原価に含めません。帳簿で扱いを確認できない費用がある場合は保存せず、売上仕訳を保留してください。登録後も「手動照合」です。借方・貸方には仕訳全体の同じ合計額を入力し、この商品への配分額は {{ number_format($manualExpectedTotal) }}円として記録します。複数商品を同じ仕訳IDで照合できますが、配分合計が仕訳全体と一致するまで関連商品の売上はCSV出力できません。仕入日が登録済みなら仕訳日が完全一致する必要があります。商品名、SKU、仕入日、数量、原価、送料、その他費用、仕入先または古物商特例情報が変わると証跡は無効になり、再確認が必要です。</p>
                    @if($errors->has('purchase_reconciliation'))
                        <p class="mt-3 rounded border border-red-300 bg-red-50 p-3 text-sm font-bold text-red-900">{{ $errors->first('purchase_reconciliation') }}</p>
                    @endif
                    <div class="mt-4 space-y-5">
                        @foreach(['freee' => 'freee', 'money_forward' => 'Money Forward'] as $provider => $providerName)
                            @php($reconciliationState = $manualPurchaseReconciliations->get($provider))
                            @php($existingEvidence = (array) ($reconciliationState['evidence'] ?? []))
                            @php($allocation = (array) ($reconciliationState['allocation'] ?? []))
                            <article class="rounded border border-slate-300 bg-slate-50 p-4">
                                <h4 class="font-black text-slate-900">{{ $providerName }}</h4>
                                @if($reconciliationState && ($reconciliationState['is_current'] ?? false))
                                    <p class="mt-2 text-sm font-bold text-emerald-900">現在の商品情報と手動照合済み（{{ $reconciliationState['record']->reviewed_at?->format('Y/m/d H:i') }}）</p>
                                    <p class="mt-1 text-sm font-semibold text-slate-800">仕訳全体 {{ number_format((int) ($allocation['transaction_total'] ?? 0)) }}円 / {{ (int) ($allocation['product_count'] ?? 0) }}商品に合計 {{ number_format((int) ($allocation['allocated_total'] ?? 0)) }}円を配分</p>
                                    <dl class="mt-2 grid gap-2 text-sm text-slate-800 sm:grid-cols-2">
                                        <div><dt class="font-bold">外部仕訳ID</dt><dd class="break-all">{{ $existingEvidence['external_transaction_id'] ?? '記録なし' }}</dd></div>
                                        <div><dt class="font-bold">取引日・照合額</dt><dd>{{ $existingEvidence['transaction_date'] ?? '不明' }} / {{ number_format((int) ($existingEvidence['purchase_amount'] ?? 0)) }}円</dd></div>
                                        <div><dt class="font-bold">借方勘定科目</dt><dd class="whitespace-pre-wrap">{{ $existingEvidence['debit_accounts'] ?? '記録なし' }}</dd></div>
                                        <div><dt class="font-bold">貸方勘定科目</dt><dd class="whitespace-pre-wrap">{{ $existingEvidence['credit_accounts'] ?? '記録なし' }}</dd></div>
                                    </dl>
                                    <ul class="mt-3 space-y-1 text-sm text-slate-800">
                                        @foreach(\App\Services\FurimaDeckAccountingPurchaseReconciliationService::EXPENSES as $expenseKey => $expenseLabel)
                                            @php($recordedExpenseEvidence = (array) data_get($existingEvidence, 'expense_evidence.'.$expenseKey, []))
                                            @if((int) ($manualExpectedAmounts[$expenseKey] ?? 0) > 0)
                                                <li><strong>{{ $expenseLabel }}:</strong> {{ $recordedExpenseEvidence['external_tax_classification'] ?? '記録なし' }} / {{ \App\Services\FurimaDeckAccountingPurchaseReconciliationService::ACCOUNTING_TREATMENTS[$recordedExpenseEvidence['accounting_treatment'] ?? ''] ?? '会計上の扱いを再確認' }}</li>
                                            @endif
                                        @endforeach
                                    </ul>
                                    <p class="mt-2 text-xs font-semibold text-slate-700">勘定科目・仕訳IDなどの証跡は暗号化して保存しています。仕入記録の登録内容は会計サービス側でも確認してください。</p>
                                @else
                                    @if($reconciliationState && (int) ($allocation['product_count'] ?? 0) > 0 && (int) ($allocation['transaction_total'] ?? 0) > (int) ($allocation['allocated_total'] ?? 0))
                                        <p class="mt-2 rounded border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-950">配分待ち：この商品の照合額 {{ number_format((int) ($existingEvidence['purchase_amount'] ?? 0)) }}円を含め、現在 {{ number_format((int) ($allocation['allocated_total'] ?? 0)) }}円 / 仕訳全体 {{ number_format((int) ($allocation['transaction_total'] ?? 0)) }}円です。あと {{ number_format((int) $allocation['transaction_total'] - (int) $allocation['allocated_total']) }}円を別商品の仕入額として配分してください。合計一致までは関連商品の売上を送信・CSV出力できません。</p>
                                    @elseif($reconciliationState)
                                        <p class="mt-2 rounded border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-950">商品情報または接続先事業者が変わったか、照合に必要な証跡が不足しているため、この証跡は現在の売上会計の解除条件を満たしません。現在接続中の事業者の仕訳をもう一度確認し、商品情報と会計サービス側の税区分表示を記録してください。過去の証跡は監査履歴として保持します。</p>
                                    @else
                                        <p class="mt-2 text-sm text-slate-700">この会計サービスについて、まだ手動照合の証跡はありません。</p>
                                    @endif
                                    <form method="POST" action="{{ route('products.accounting-purchase-reconciliation.store', [$product, $provider]) }}" class="mt-4 space-y-4">
                                        @csrf
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <label class="block text-sm font-bold text-slate-900">会計サービスの仕訳ID
                                                <input required maxlength="255" name="external_transaction_id" value="{{ old('external_transaction_id', $existingEvidence['external_transaction_id'] ?? '') }}" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                            </label>
                                            <label class="block text-sm font-bold text-slate-900">会計サービス側の取引日
                                                <input required type="date" name="transaction_date" value="{{ old('transaction_date', $existingEvidence['transaction_date'] ?? $product->purchase_date?->format('Y-m-d')) }}" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                            </label>
                                            <label class="block text-sm font-bold text-slate-900">借方勘定科目（複数は改行して記録）
                                                <textarea required maxlength="2000" name="debit_accounts" rows="2" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">{{ old('debit_accounts', $existingEvidence['debit_accounts'] ?? '') }}</textarea>
                                            </label>
                                            <label class="block text-sm font-bold text-slate-900">貸方勘定科目（複数は改行して記録）
                                                <textarea required maxlength="2000" name="credit_accounts" rows="2" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">{{ old('credit_accounts', $existingEvidence['credit_accounts'] ?? '') }}</textarea>
                                            </label>
                                            <label class="block text-sm font-bold text-slate-900">仕訳帳の借方合計（仕訳全体・税込・円）
                                                <input required min="1" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="debit_total" value="{{ old('debit_total', $existingEvidence['debit_total'] ?? '') }}" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                            </label>
                                            <label class="block text-sm font-bold text-slate-900">仕訳帳の貸方合計（仕訳全体・税込・円）
                                                <input required min="1" max="{{ \App\Services\SaleProfitCalculator::MAX_UNSIGNED_AMOUNT }}" type="number" name="credit_total" value="{{ old('credit_total', $existingEvidence['credit_total'] ?? '') }}" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                            </label>
                                        </div>
                                        <fieldset class="space-y-3">
                                            <legend class="text-sm font-black text-slate-900">この商品への配分 {{ number_format($manualExpectedTotal) }}円の証憑情報</legend>
                                            @foreach(\App\Services\FurimaDeckAccountingPurchaseReconciliationService::EXPENSES as $expenseKey => $expenseLabel)
                                                @php($expenseAmount = $manualExpectedAmounts[$expenseKey])
                                                @php($expenseValues = (array) old('expense_evidence.'.$expenseKey, $existingEvidence['expense_evidence'][$expenseKey] ?? []))
                                                @if($expenseAmount > 0)
                                                    <div class="rounded border border-slate-300 bg-white p-3">
                                                        <p class="text-sm font-black text-slate-900">{{ $expenseLabel }}：{{ number_format($expenseAmount) }}円</p>
                                                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                                                            <label class="block text-sm font-bold text-slate-800">会計サービスの税区分表示（画面の文言をそのまま入力）
                                                                <input required maxlength="255" name="expense_evidence[{{ $expenseKey }}][external_tax_classification]" value="{{ $expenseValues['external_tax_classification'] ?? '' }}" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                                            </label>
                                                            <label class="block text-sm font-bold text-slate-800">証憑の種類
                                                                <select required name="expense_evidence[{{ $expenseKey }}][document_type]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                                                    <option value="">選択してください</option><option value="receipt" @selected(($expenseValues['document_type'] ?? '') === 'receipt')>領収書</option><option value="invoice" @selected(($expenseValues['document_type'] ?? '') === 'invoice')>請求書</option><option value="other" @selected(($expenseValues['document_type'] ?? '') === 'other')>その他の証憑</option>
                                                                </select>
                                                            </label>
                                                            <label class="block text-sm font-bold text-slate-800">証憑の税区分
                                                                <select required name="expense_evidence[{{ $expenseKey }}][tax_category]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                                                    <option value="">選択してください</option><option value="standard_10" @selected(($expenseValues['tax_category'] ?? '') === 'standard_10')>標準税率 10%</option><option value="reduced_8" @selected(($expenseValues['tax_category'] ?? '') === 'reduced_8')>軽減税率 8%</option><option value="exempt" @selected(($expenseValues['tax_category'] ?? '') === 'exempt')>非課税</option><option value="non_taxable" @selected(($expenseValues['tax_category'] ?? '') === 'non_taxable')>不課税</option><option value="out_of_scope" @selected(($expenseValues['tax_category'] ?? '') === 'out_of_scope')>対象外</option>
                                                                </select>
                                                            </label>
                                                            <label class="block text-sm font-bold text-slate-800">証憑の発行者区分
                                                                <select required name="expense_evidence[{{ $expenseKey }}][invoice_issuer]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                                                    <option value="">選択してください</option><option value="qualified" @selected(($expenseValues['invoice_issuer'] ?? '') === 'qualified')>適格請求書発行事業者</option><option value="unqualified" @selected(($expenseValues['invoice_issuer'] ?? '') === 'unqualified')>適格請求書発行事業者ではない</option><option value="not_applicable" @selected(($expenseValues['invoice_issuer'] ?? '') === 'not_applicable')>対象外</option>
                                                                </select>
                                                            </label>
                                                            <label class="block text-sm font-bold text-slate-800">会計サービスでの仕入時の扱い
                                                                <select required name="expense_evidence[{{ $expenseKey }}][accounting_treatment]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                                                    <option value="">仕訳帳で確認して選択</option><option value="inventory" @selected(($expenseValues['accounting_treatment'] ?? '') === 'inventory')>在庫として計上（売れた時に原価へ振替）</option><option value="expense" @selected(($expenseValues['accounting_treatment'] ?? '') === 'expense')>仕入時に費用計上（売れた時の原価に含めない）</option>
                                                                </select>
                                                            </label>
                                                            <label class="block text-sm font-bold text-slate-800">仕入先別の上限確認記録（該当する場合）
                                                                <select name="expense_evidence[{{ $expenseKey }}][cap_review_id]" class="mt-1 w-full rounded border-slate-400 bg-white text-slate-900">
                                                                    <option value="">選択してください</option>
                                                                    @foreach($purchaseCapReviews as $review)
                                                                        <option value="{{ $review->id }}" @selected((string) ($expenseValues['cap_review_id'] ?? '') === (string) $review->id)>{{ $review->counterparty_name }} / {{ $review->period_from->format('Y/m/d') }}～{{ $review->period_to->format('Y/m/d') }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </label>
                                                        </div>
                                                        <label class="mt-3 flex items-start gap-2 text-sm font-semibold text-slate-900"><input required type="checkbox" name="expense_evidence[{{ $expenseKey }}][date_matches_entry_confirmed]" value="1" @checked(($expenseValues['date_matches_entry_confirmed'] ?? false) === true || ($expenseValues['date_matches_entry_confirmed'] ?? false) === '1') class="mt-1"><span>この費用の金額・証憑の税区分・発行者区分と、会計サービス画面の税区分表示を照合し、証憑日が上の取引日と一致することを確認しました。不一致や判断できない項目があれば保存しないでください。</span></label>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </fieldset>
                                        <fieldset class="space-y-2 rounded border border-amber-300 bg-amber-50 p-3">
                                            <legend class="px-1 text-sm font-black text-amber-950">照合前の確認</legend>
                                            @foreach([
                                                'service' => $providerName.'の仕訳帳を実際に開いて確認した',
                                                'same_purchase' => 'この仕訳がこの商品またはその在庫の仕入記録である',
                                                'date' => '会計サービス側の取引日が正しく、商品に仕入日がある場合は日付が一致する',
                                                'amount' => '借方・貸方の合計と商品に登録された仕入総額を照合した',
                                                'accounts_tax' => '勘定科目・税区分・発行者区分と、費用ごとの在庫／費用計上の扱いを仕訳帳と証憑で確認した',
                                                'no_resend' => 'これは証跡の保存だけであり、仕入仕訳の送信・再送を許可する操作ではないと理解した',
                                            ] as $confirmationKey => $confirmationLabel)
                                                <label class="flex items-start gap-2 text-sm font-semibold text-slate-900"><input required type="checkbox" name="confirmations[{{ $confirmationKey }}]" value="1" @checked(old('confirmations.'.$confirmationKey)) class="mt-1"><span>{{ $confirmationLabel }}</span></label>
                                            @endforeach
                                        </fieldset>
                                        <button class="rounded bg-amber-800 px-4 py-2.5 text-sm font-black text-white hover:bg-amber-900">手動照合の証跡を保存</button>
                                    </form>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
