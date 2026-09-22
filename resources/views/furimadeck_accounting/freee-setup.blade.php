<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">freee仕訳設定</h2></x-slot>
    <div class="py-8"><div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
        @if($errors->any())<p class="rounded bg-red-50 p-4 font-semibold text-red-800">{{ $errors->first() }}</p>@endif
        <section class="bg-white p-6 shadow-sm sm:rounded-lg">
            <h3 class="font-semibold text-gray-900">freeeの事業所を選択</h3>
            <p class="mt-2 text-sm leading-6 text-gray-600">連携したfreeeアカウントの事業所一覧です。仕訳を登録する事業所を選択してください。</p>
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
                <h3 class="font-semibold text-gray-900">仕訳に使う科目を選択</h3>
                <p class="mt-2 text-sm leading-6 text-gray-600">各項目はfreeeのマスタから取得しています。税区分や入金先は、実際の会計処理に合わせて選択してください。</p>
                <form method="post" action="{{ route('furimadeck-accounting.freee.setup.save') }}" class="mt-5 space-y-5">
                    @csrf
                    <input type="hidden" name="company_id" value="{{ $options['company']['id'] }}">
                    <label class="block text-sm font-semibold text-gray-700">税区分<select name="tax_code" required class="mt-1 block w-full rounded-md border-gray-300">
                        <option value="">選択してください</option>
                        @foreach($options['tax_codes'] as $tax)<option value="{{ $tax['code'] }}" @selected((string) old('tax_code', $saved['tax_code'] ?? '') === (string) $tax['code'])>{{ $tax['name'] }}（{{ $tax['code'] }}）</option>@endforeach
                    </select></label>
                    @php($labels = ['settlement' => '売上入金先', 'sales' => '売上', 'fees' => '販売手数料・送料', 'cost' => '仕入原価', 'inventory' => '商品在庫'])
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach($labels as $key => $label)
                            <label class="block text-sm font-semibold text-gray-700">{{ $label }}<select name="account_item_ids[{{ $key }}]" required class="mt-1 block w-full rounded-md border-gray-300">
                                <option value="">選択してください</option>
                                @foreach($options['account_items'] as $item)<option value="{{ $item['id'] }}" @selected((string) old('account_item_ids.'.$key, $saved['account_item_ids'][$key] ?? '') === (string) $item['id'])>{{ $item['name'] }}（ID: {{ $item['id'] }}）</option>@endforeach
                            </select></label>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-3"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">設定を保存</button><a href="{{ route('furimadeck-accounting.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">会計画面へ戻る</a></div>
                </form>
            </section>
        @endif
    </div></div>
</x-app-layout>
