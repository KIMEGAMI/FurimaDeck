<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-black text-gray-900">販売の会計データ</h2></x-slot>
    <main class="mx-auto max-w-5xl space-y-5 px-4 py-8 sm:px-6">
        @if(session('success'))<p class="border-l-4 border-emerald-700 bg-emerald-50 p-3 text-sm font-bold text-emerald-950">{{ session('success') }}</p>@endif
        @if(session('error'))<p class="border-l-4 border-red-700 bg-red-50 p-3 text-sm font-bold text-red-950">{{ session('error') }}</p>@endif
        @if($errors->any())<p role="alert" class="border-l-4 border-red-700 bg-red-50 p-3 text-sm font-bold text-red-950">{{ $errors->first() }}</p>@endif

        <section class="space-y-3 border-b border-gray-300 pb-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-black text-gray-950">{{ $sale->product_name_snapshot }}</h3>
                    <p class="text-sm font-semibold text-gray-800">{{ $sale->internal_sku_snapshot }} / {{ $sale->marketplace?->name ?? '販売先未設定' }} / {{ $sale->sold_at?->format('Y/m/d') }}</p>
                </div>
                <a href="{{ route('furimadeck-accounting.index', ['year' => $sale->sold_at?->year ?? now()->year, 'section' => 'review']) }}" class="text-sm font-bold text-blue-800 underline">会計データ一覧へ戻る</a>
            </div>
            <dl class="grid gap-x-6 gap-y-2 text-sm text-gray-900 sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="font-bold">売上</dt><dd>¥{{ number_format($sale->sold_price) }}</dd></div>
                <div><dt class="font-bold">販売手数料</dt><dd>¥{{ number_format($sale->sales_fee) }}</dd></div>
                <div><dt class="font-bold">販売送料</dt><dd>¥{{ number_format($sale->shipping_fee) }}</dd></div>
            </dl>
        </section>

        <section class="space-y-4 border-b border-gray-300 pb-5">
            <div>
                <h3 class="text-lg font-black text-gray-950">原価・販売時の費用</h3>
                <p class="mt-1 text-sm leading-6 text-gray-800">仕入原価や販売時に別途かかった費用をここで登録します。保存すると実利益と会計データを更新します。</p>
                @if($sale->product)
                    <a class="mt-2 inline-block text-sm font-bold text-blue-800 underline" href="{{ route('furimadeck-accounting.products.purchase-info.edit', $sale->product) }}">商品ごとの仕入日・仕入単価・仕入送料・その他仕入費を確認</a>
                @endif
            </div>
            @if($detailsLocked)
                <dl class="grid gap-x-6 gap-y-2 text-sm text-gray-900 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($saleAmountFields as $field => $label)
                        <div><dt class="font-bold">{{ $label }}</dt><dd>¥{{ number_format((int) $sale->{$field}) }}</dd></div>
                    @endforeach
                </dl>
            @else
                <form method="post" action="{{ route('furimadeck-accounting.sales.amounts.update', $sale) }}" class="space-y-4">
                    @csrf @method('PUT')
                    <input type="hidden" name="record_version" value="{{ $sale->recordVersionToken() }}">
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($saleAmountFields as $field => $label)
                            @php($purchaseCostLocked = in_array($field, ['cost_basis', 'purchase_shipping_cost'], true) && (bool) $sale->product?->purchase_accounting_enabled)
                            <label class="block text-sm font-bold text-gray-900">{{ $label }}
                                <input required type="number" min="0" step="1" name="{{ $field }}" value="{{ old($field, $sale->{$field}) }}" @readonly($purchaseCostLocked) class="mt-1 block w-full rounded border-gray-400 text-gray-950 @if($purchaseCostLocked) bg-gray-100 @endif">
                                @if($purchaseCostLocked)
                                    <span class="mt-1 block text-xs font-normal text-gray-700">商品仕入時に在庫へ計上済みです。二重計上防止のため変更できません。</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                    @if($errors->has('accounting_amounts'))<p role="alert" class="text-sm font-bold text-red-800">{{ $errors->first('accounting_amounts') }}</p>@endif
                    <button class="rounded bg-gray-900 px-4 py-2.5 text-sm font-bold text-white">会計金額を保存</button>
                </form>
            @endif
        </section>

        @if($detailsLocked)
            <section class="space-y-3 border-l-4 border-amber-600 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                <h3 class="font-black">証憑情報は変更できません</h3>
                <p>この販売記録は会計サービスへの送信・取込済み、処理中、または結果未確定です。送信済みの記録と内容が食い違わないよう、証憑情報はロックされています。未送信サービスの支払元だけを変更できます。</p>
            </section>
            @foreach(['freee' => 'freee', 'money_forward' => 'Money Forward'] as $provider => $providerName)
                @if(count($paymentSourceOptions[$provider] ?? []) > 0)
                    <form method="post" action="{{ route('furimadeck-accounting.sales.expense-sources.update', [$sale, $provider]) }}" class="space-y-3 border-b border-gray-200 pb-4">
                        @csrf @method('PUT')
                        <h3 class="font-bold text-gray-950">{{ $providerName }}の支払元</h3>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach($expenseLabels as $field => $label)
                                @if((int) $sale->{$field} > 0)
                                    <label class="block text-sm font-semibold text-gray-900">{{ $label }}の支払元
                                        <select name="payment_sources[{{ $field }}]" class="mt-1 block w-full rounded border-gray-400 bg-white text-gray-950">
                                            <option value="">選択してください</option>
                                            @foreach($paymentSourceOptions[$provider] as $source)
                                                <option value="{{ $source['id'] }}" @selected(data_get($sale->accounting_expense_details, $field.'.payment_sources.'.$provider) === $source['id'])>{{ $source['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                @endif
                            @endforeach
                        </div>
                        <button class="rounded bg-gray-900 px-4 py-2 text-sm font-bold text-white">{{ $providerName }}の支払元を保存</button>
                    </form>
                @endif
            @endforeach
        @else
            <form method="post" action="{{ route('furimadeck-accounting.sales.expenses.update', $sale) }}" class="space-y-6">
                @csrf @method('PUT')
                @if($expenseFields !== [])
                    @foreach($expenseFields as $field => $label)
                        @php($detail = (array) old('accounting_expense_details.'.$field, data_get($sale->accounting_expense_details, $field, [])))
                        <fieldset class="space-y-4 border-b border-gray-300 pb-5">
                            <legend class="px-1 text-base font-black text-gray-950">{{ $label }} / ¥{{ number_format((int) $sale->{$field}) }}</legend>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="block text-sm font-bold text-gray-900">証憑の種類
                                    <select name="accounting_expense_details[{{ $field }}][document_type]" class="mt-1 block w-full rounded border-gray-400 bg-white text-gray-950">
                                        <option value="">選択してください</option>
                                        @foreach($documentTypes as $value => $optionLabel)<option value="{{ $value }}" @selected(data_get($detail, 'document_type') === $value)>{{ $optionLabel }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="block text-sm font-bold text-gray-900">証憑の税区分
                                    <select name="accounting_expense_details[{{ $field }}][tax_category]" class="mt-1 block w-full rounded border-gray-400 bg-white text-gray-950">
                                        <option value="">選択してください</option>
                                        @foreach($taxCategories as $value => $optionLabel)<option value="{{ $value }}" @selected(data_get($detail, 'tax_category') === $value)>{{ $optionLabel }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="block text-sm font-bold text-gray-900">証憑の発行者区分
                                    <select name="accounting_expense_details[{{ $field }}][invoice_issuer]" class="mt-1 block w-full rounded border-gray-400 bg-white text-gray-950">
                                        <option value="">選択してください</option>
                                        @foreach($invoiceIssuers as $value => $optionLabel)<option value="{{ $value }}" @selected(data_get($detail, 'invoice_issuer') === $value)>{{ $optionLabel }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="block text-sm font-bold text-gray-900">費用先の年間上限確認（該当する場合）
                                    <select name="accounting_expense_details[{{ $field }}][cap_review_id]" class="mt-1 block w-full rounded border-gray-400 bg-white text-gray-950">
                                        <option value="">選択してください</option>
                                        @foreach($purchaseCapReviews as $review)<option value="{{ $review->id }}" @selected((string) data_get($detail, 'cap_review_id', '') === (string) $review->id)>{{ $review->counterparty_name }} / {{ $review->period_from->format('Y/m/d') }}～{{ $review->period_to->format('Y/m/d') }}</option>@endforeach
                                    </select>
                                </label>
                                <label class="flex items-start gap-2 text-sm font-semibold text-gray-900 sm:col-span-2"><input type="checkbox" name="accounting_expense_details[{{ $field }}][date_matches_entry_confirmed]" value="1" @checked(in_array(data_get($detail, 'date_matches_entry_confirmed'), [true, 1, '1', 'on'], true)) class="mt-1"><span>証憑の金額・税区分・発行者区分を確認し、証憑日が販売日と一致することを確認しました。</span></label>
                                @foreach(['freee' => 'freee', 'money_forward' => 'Money Forward'] as $provider => $providerName)
                                    @if(count($paymentSourceOptions[$provider] ?? []) > 0)
                                        <label class="block text-sm font-bold text-gray-900">{{ $providerName }}で実際に使った支払元
                                            <select name="accounting_expense_details[{{ $field }}][payment_sources][{{ $provider }}]" class="mt-1 block w-full rounded border-gray-400 bg-white text-gray-950">
                                                <option value="">選択してください</option>
                                                @foreach($paymentSourceOptions[$provider] as $source)<option value="{{ $source['id'] }}" @selected((string) data_get($detail, 'payment_sources.'.$provider, '') === (string) $source['id'])>{{ $source['name'] }}</option>@endforeach
                                            </select>
                                        </label>
                                    @endif
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    <button class="rounded bg-cyan-800 px-5 py-2.5 text-sm font-black text-white">会計情報を保存</button>
                @else
                    <p class="text-sm font-semibold text-gray-800">この販売に登録する別途費用はありません。</p>
                @endif
            </form>
        @endif
    </main>
</x-app-layout>
