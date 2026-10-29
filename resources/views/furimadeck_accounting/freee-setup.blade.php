<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">freee仕訳設定</h2></x-slot>
    <div class="py-8"><div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @if($errors->any())<p class="rounded bg-red-50 p-4 font-semibold text-red-800">{{ $errors->first() }}</p>@endif
        <section class="bg-white p-6 shadow-sm sm:rounded-lg">
            <h3 class="font-semibold text-gray-900">freeeの事業所を選択</h3>
            <p class="mt-2 text-sm leading-6 text-gray-600">連携したfreeeアカウントの事業所一覧です。CSV用の勘定科目を設定する事業所を選択してください。FurimaDeckから仕訳を直接登録する機能ではありません。</p>
            <form method="get" action="{{ route('furimadeck-accounting.freee.setup') }}" class="mt-4 flex flex-wrap items-end gap-3">
                <label class="text-sm font-semibold text-gray-700">事業所<select name="company_id" required class="mt-1 block min-w-72 rounded-md border-gray-300">
                    <option value="">選択してください</option>
                    @foreach($companies as $company)
                        <option value="{{ $company['id'] }}" @selected((string) request('company_id') === (string) $company['id'])>{{ $company['display_name'] ?: $company['name'] }}（ID: {{ $company['id'] }}）</option>
                    @endforeach
                </select></label>
                <button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">勘定科目を取得</button>
            </form>
        </section>

        @if($options)
            @php($saved = $options['settings'])
            <section class="bg-white p-6 shadow-sm sm:rounded-lg">
                <h3 class="font-semibold text-gray-900">CSVに使う勘定科目を選択</h3>
                <p class="mt-2 text-sm leading-6 text-gray-600">CSV出力用の勘定科目と税区分を設定します。売上日には実際の入金を確認できないため、売上の借方には「売掛金」を使います。銀行口座科目ではCSVを出力しません。保存時とCSV作成時にfreeeから科目区分を取得し、用途と合わない科目や区分を確認できない科目も使いません。科目区分の確認は、個別取引の会計上の妥当性を保証するものではありません。</p>
                <form method="post" action="{{ route('furimadeck-accounting.freee.setup.save') }}" class="mt-5 space-y-5">
                    @csrf
                    <input type="hidden" name="company_id" value="{{ $options['company']['id'] }}">
                    @php($labels = ['settlement' => '売掛金（入金前の売上）', 'sales' => '売上', 'fees' => '販売手数料', 'shipping' => '送料', 'other' => 'その他費用', 'cost' => '仕入原価', 'inventory' => '商品在庫'])
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach($labels as $key => $label)
                            <label class="block text-sm font-semibold text-gray-700">{{ $label }}<select name="account_item_ids[{{ $key }}]" @required(! in_array($key, ['shipping', 'other'], true)) class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">選択してください</option>
                                @foreach($options['account_items'] as $item)
                                    @if(($item['available'] ?? false) && ($key !== 'settlement' || \App\Services\FurimaDeckFreeeAccountingSetupService::categoryMatchesCsvRole($key, $item['categories'])))
                                        <option value="{{ $item['id'] }}" data-tax-name="{{ $item['default_tax_name'] }}" @selected((string) old('account_item_ids.'.$key, $saved['account_item_ids'][$key] ?? '') === (string) $item['id'])>{{ $item['name'] }}（ID: {{ $item['id'] }}）</option>
                                    @endif
                                @endforeach
                            </select><span class="mt-1 block text-xs font-normal text-gray-600">freeeの標準税区分: <span data-default-tax>{{ collect($options['account_items'])->firstWhere('id', (int) old('account_item_ids.'.$key, $saved['account_item_ids'][$key] ?? 0))['default_tax_name'] ?? '科目を選択してください' }}</span></span></label>
                        @endforeach
                    </div>
                    <fieldset class="rounded-md border border-gray-300 p-4">
                        <legend class="px-2 text-sm font-semibold text-gray-900">仕入商品の支払元候補</legend>
                        <p class="mb-3 text-sm leading-6 text-gray-700">候補は商品別の仕入会計データに保存されますが、FurimaDeck 1.0では仕入CSVを出力しません。仕入仕訳はfreee側で登録してください。この候補設定は、1.0の売上CSV出力には必要ありません。</p>
                        <input type="hidden" name="purchase_payment_account_ids_present" value="1">
                        @if(($options['purchase_payment_account_items'] ?? []) === [])
                            <p class="text-sm font-semibold text-amber-900">freeeから利用可能な資産・負債科目を確認できません。支払元候補は設定できません。</p>
                        @else
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach($options['purchase_payment_account_items'] as $item)
                                    <label class="flex items-start gap-2 text-sm text-gray-800">
                                        <input type="checkbox" name="purchase_payment_account_ids[]" value="{{ $item['id'] }}" @checked(in_array((string) $item['id'], array_map('strval', array_keys((array) ($saved['purchase_payment_accounts'] ?? []))), true)) class="mt-1">
                                        <span>{{ $item['name'] }}（{{ $item['account_category'] ?: implode(' / ', $item['categories']) }}）</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </fieldset>
                    <div class="flex flex-wrap gap-3"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">設定を保存</button><a href="{{ route('furimadeck-accounting.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">会計画面へ戻る</a></div>
                </form>
                <script>
                    document.querySelectorAll('select[name^="account_item_ids["]').forEach((select) => {
                        const output = select.closest('label')?.querySelector('[data-default-tax]');
                        const update = () => { if (output) output.textContent = select.selectedOptions[0]?.dataset.taxName || '科目を選択してください'; };
                        select.addEventListener('change', update);
                        update();
                    });
                </script>
            </section>
        @endif
    </div></div>
</x-app-layout>
