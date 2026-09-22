<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Money Forward仕訳設定</h2></x-slot>
    @php
        $accountOptions = [];
        foreach (($options['accounts']['accounts'] ?? []) as $account) {
            if (isset($account['id'], $account['name'])) $accountOptions[(string) $account['id']] = $account['name'];
            foreach (($account['sub_accounts'] ?? []) as $subAccount) if (isset($subAccount['id'], $subAccount['name'])) $accountOptions[(string) $subAccount['id']] = $account['name'].' / '.$subAccount['name'];
        }
    @endphp
    <div class="py-8"><div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($errors->any())<p class="mb-5 rounded bg-red-50 p-4 font-semibold text-red-800">{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ route('furimadeck-accounting.money-forward.setup.save') }}" class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
            @csrf
            <div><h3 class="font-semibold text-gray-900">仕訳の送信先</h3><p class="mt-2 text-sm leading-6 text-gray-600">勘定科目と税区分は税務上の判断を含むため、自動決定せず、Money Forward側のマスタから選択して保存してください。</p></div>
            @foreach(['settlement' => '決済・入金先', 'sales' => '売上', 'fees' => '販売手数料・送料等', 'cost' => '仕入原価', 'inventory' => '商品在庫'] as $key => $label)
                <label class="block text-sm font-semibold text-gray-700">{{ $label }}<select name="account_ids[{{ $key }}]" required class="mt-1 block w-full rounded-md border-gray-300"><option value="">選択してください</option>@foreach($accountOptions as $id => $name)<option value="{{ $id }}" @selected(old('account_ids.'.$key, $settings['account_ids'][$key] ?? '') === $id)>{{ $name }}</option>@endforeach</select></label>
            @endforeach
            @php($taxOptions = $options['taxes']['taxes'] ?? [])
            <label class="block text-sm font-semibold text-gray-700">税区分<select name="tax_id" required class="mt-1 block w-full rounded-md border-gray-300" @disabled($taxOptions === [])><option value="">{{ $taxOptions === [] ? 'Money Forwardから税区分を取得できません' : '選択してください' }}</option>@foreach($taxOptions as $tax)<option value="{{ $tax['id'] ?? '' }}" @selected(old('tax_id', $settings['tax_id'] ?? '') === (string) ($tax['id'] ?? ''))>{{ $tax['name'] ?? '' }}{{ isset($tax['abbreviation']) ? '（'.$tax['abbreviation'].'）' : '' }}</option>@endforeach</select></label>
            @if($taxOptions === [])<p class="-mt-4 text-sm font-semibold text-amber-800">Money Forwardから税区分が返っていません。Money Forward側の消費税設定を確認し、連携を解除してから再連携してください。</p>@endif
            <label class="block text-sm font-semibold text-gray-700">インボイス区分<select name="invoice_kind" class="mt-1 block w-full rounded-md border-gray-300">@foreach(['INVOICE_KIND_NOT_TARGET' => '対象外', 'INVOICE_KIND_QUALIFIED' => '適格請求書', 'INVOICE_KIND_NON_QUALIFIED' => '適格請求書以外'] as $value => $label)<option value="{{ $value }}" @selected(old('invoice_kind', $settings['invoice_kind'] ?? 'INVOICE_KIND_NOT_TARGET') === $value)>{{ $label }}</option>@endforeach</select></label>
            <div class="flex flex-wrap gap-3"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">設定を保存</button><a href="{{ route('furimadeck-accounting.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">戻る</a></div>
        </form>
    </div></div>
</x-app-layout>
