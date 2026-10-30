<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800 leading-tight">Money Forward CSV設定</h2></x-slot>
    @php
        $invoiceKindLabels = app(\App\Services\FurimaDeckMoneyForwardInvoiceKindService::class)->csvLabels();
        $businessTaxPeriods = old('business_tax_periods', $settings['business_tax_periods'] ?? []);
        $businessTaxPeriods = is_array($businessTaxPeriods) ? $businessTaxPeriods : [];
        $invoiceKindsRequired = collect($businessTaxPeriods)->contains(
            fn ($period) => is_array($period) && ($period['invoice_kind'] ?? null) === \App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE,
        );
        $accountOptions = [];
        $accountTaxIds = [];
        $accountParentNames = [];
        $taxNames = collect($options['taxes']['taxes'] ?? [])->mapWithKeys(fn ($tax) => [(string) ($tax['id'] ?? '') => (string) ($tax['name'] ?? $tax['abbreviation'] ?? '')]);
        $taxOptions = $options['taxes']['taxes'] ?? [];
        foreach (($options['accounts']['accounts'] ?? []) as $account) {
            if (isset($account['id'], $account['name'])) {
                $accountOptions[(string) $account['id']] = $account['name'];
                $accountTaxIds[(string) $account['id']] = (string) ($account['tax_id'] ?? '');
                $accountParentNames[(string) $account['id']] = (string) $account['name'];
            }
            foreach (($account['sub_accounts'] ?? []) as $subAccount) if (isset($subAccount['id'], $subAccount['name'])) {
                $accountOptions[(string) $subAccount['id']] = $account['name'].' / '.$subAccount['name'];
                $accountTaxIds[(string) $subAccount['id']] = (string) ($subAccount['tax_id'] ?? '');
                $accountParentNames[(string) $subAccount['id']] = (string) $account['name'];
            }
        }
    @endphp
    <div class="py-8"><div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($errors->any())<p class="mb-5 rounded bg-red-50 p-4 font-semibold text-red-800">{{ $errors->first() }}</p>@endif
        <form method="post" action="{{ route('furimadeck-accounting.money-forward.setup.save') }}" class="space-y-6 rounded-lg bg-white p-6 shadow-sm">
            @csrf
            <div><h3 class="font-semibold text-gray-900">CSV用の勘定科目・区分</h3><p class="mt-2 text-sm leading-6 text-gray-700">売上日には実際の入金を確認できないため、売上の借方には「売掛金」を使います。銀行口座科目ではCSVを出力しません。勘定科目の税区分はMoney Forwardから取得しますが、取引に適切かは自動判定できないため、原資料と照合してください。</p></div>
            <div class="rounded border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950"><strong>インボイス区分は勘定科目側ごとに設定します。</strong>事業者自身の課税・登録状況を、仕入先や販売手数料の相手先へ一律に流用しません。課税事業者であることを確認済みの期間だけ、請求書・利用明細などを見て設定してください。状態未確認・免税の期間だけの場合、選択は不要です。</div>
            <div class="rounded border border-amber-300 bg-amber-50 p-4 text-sm leading-6 text-amber-950"><strong>Money Forwardサポート回答（2026/10/26）：</strong>会計設定が「免税事業者」の場合、消費税関連の設定・機能は利用できず、インボイス欄も用意されません。仕訳CSVの税区分やインボイス欄に値を入力した場合、その内容は仕訳メモとして取り込まれる想定との回答です。FurimaDeckは免税確認済みの取引で両欄を空欄にしてCSVを出力しますが、空欄の実際の表示と仕訳内容は最新CSVのプレビューで確認してください。簡易課税・原則課税で税区分欄が空欄または省略された場合の扱いは、<a class="font-semibold underline" href="https://biz.moneyforward.com/support/tax-return/guide/import-books/ib01.html" target="_blank" rel="noopener noreferrer">Money Forward公式ガイド</a>と取込プレビューで確認してください。自分の税務上の区分はMoney Forwardの画面表示だけでは判断できません。不明な場合は「課税・免税の状態未確認」のままにしてください。その期間のCSV出力は停止されます。</div>
            @foreach(['settlement' => '売掛金（入金前の売上）', 'sales' => '売上', 'fees' => '販売手数料', 'shipping' => '送料', 'other' => 'その他費用', 'cost' => '仕入原価', 'inventory' => '商品在庫'] as $key => $label)
                @php($selectedAccountId = (string) old('account_ids.'.$key, $settings['sub_account_ids'][$key] ?? $settings['account_ids'][$key] ?? ''))
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm font-semibold text-gray-700">{{ $label }}勘定科目<select name="account_ids[{{ $key }}]" @required(! in_array($key, ['shipping', 'other'], true)) class="mt-1 block w-full rounded-md border-gray-300"><option value="">選択してください</option>@foreach($accountOptions as $id => $name)@if(in_array($id, $eligibleAccountIds[$key] ?? [], true) && ($key !== 'settlement' || ($accountParentNames[$id] ?? '') === '売掛金'))<option value="{{ $id }}" data-tax-name="{{ $taxNames[$accountTaxIds[$id] ?? ''] ?? '標準税区分なし' }}" @selected($selectedAccountId === $id)>{{ $name }}</option>@endif @endforeach</select>@if($key === 'settlement' && $selectedAccountId !== '' && ($accountParentNames[$selectedAccountId] ?? '') !== '売掛金')<span class="mt-1 block text-xs font-semibold text-red-700">保存済み科目は銀行口座です。売上日は入金済みと確認できないため、売掛金へ設定し直してください。</span>@elseif($selectedAccountId !== '' && ! in_array($selectedAccountId, $eligibleAccountIds[$key] ?? [], true))<span class="mt-1 block text-xs font-semibold text-red-700">保存済みの科目はこの入力欄の区分に合いません。選び直してください。</span>@endif<span class="mt-1 block text-xs font-normal text-gray-700">Money Forwardの科目税区分: <span data-default-tax>{{ $taxNames[$accountTaxIds[$selectedAccountId] ?? ''] ?? '科目を選択してください' }}</span></span></label>
                    <div data-invoice-kind-fields @if(! $invoiceKindsRequired) hidden @endif>
                        <label class="block text-sm font-semibold text-gray-700">{{ $label }}側のインボイス区分<select name="invoice_kinds[{{ $key }}]" data-invoice-kind-role="{{ $key }}" @required($invoiceKindsRequired && ! in_array($key, ['shipping', 'other'], true)) @disabled(! $invoiceKindsRequired) class="mt-1 block w-full rounded-md border-gray-300"><option value="">選択してください</option>@foreach($invoiceKindLabels as $value => $invoiceLabel)<option value="{{ $value }}" @selected(old('invoice_kinds.'.$key, $settings['invoice_kinds'][$key] ?? '') === $value)>{{ $invoiceLabel }}</option>@endforeach</select><span class="mt-1 block text-xs font-normal text-gray-700">該当する取引の証憑で確認してください。CSVでは「対象外」「未送信」は出力できません。</span></label>
                    </div>
                    <p data-invoice-kind-hold @if($invoiceKindsRequired) hidden @endif class="text-sm leading-6 text-gray-700">課税事業者の確認済み期間を設定すると、証憑に基づく選択欄が表示されます。状態未確認・免税の間は選択しないでください。</p>
                </div>
            @endforeach
            <fieldset class="rounded-md border border-gray-300 p-4">
                <legend class="px-2 text-sm font-semibold text-gray-900">仕入商品の支払元候補</legend>
                <p class="mb-3 text-sm leading-6 text-gray-700">候補は商品別の仕入会計データに保存されますが、FurimaDeck 1.0では仕入CSVを出力しません。仕入仕訳はMoney Forward側で登録してください。この候補設定は、1.0の売上CSV出力には必要ありません。</p>
                <input type="hidden" name="purchase_payment_account_ids_present" value="1">
                @php($savedPaymentAccountIds = array_map('strval', array_keys((array) ($settings['purchase_payment_accounts'] ?? []))))
                @php($eligiblePaymentIds = $eligibleAccountIds['purchase_payment_source'] ?? [])
                @if($eligiblePaymentIds === [])
                    <p class="text-sm font-semibold text-amber-900">Money Forwardから利用可能な資産・負債科目を確認できません。支払元候補は設定できません。</p>
                @else
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach($accountOptions as $id => $name)
                            @if(in_array($id, $eligiblePaymentIds, true))
                                <label class="flex items-start gap-2 text-sm text-gray-800"><input type="checkbox" name="purchase_payment_account_ids[]" value="{{ $id }}" @checked(in_array($id, $savedPaymentAccountIds, true)) class="mt-1"><span>{{ $name }}</span></label>
                            @endif
                        @endforeach
                    </div>
                @endif
            </fieldset>
            <fieldset class="rounded-md border border-gray-300 p-4">
                <legend class="px-2 text-sm font-semibold text-gray-900">仕入証憑の税区分対応</legend>
                <p class="mb-3 text-sm leading-6 text-gray-700">商品ごとに確認した証憑上の税区分に対し、Money Forwardの税区分を選びます。名前だけで判断せず、Money Forwardの税区分詳細と証憑を照合してください。設定がない、または一致を確認できない明細はCSV出力しません。</p>
                @foreach(['standard_10' => '標準税率 10%', 'reduced_8' => '軽減税率 8%', 'exempt' => '非課税', 'non_taxable' => '不課税', 'out_of_scope' => '対象外'] as $category => $label)
                    <label class="mb-3 block text-sm font-semibold text-gray-700">証憑: {{ $label }}<select name="purchase_tax_ids[{{ $category }}]" class="mt-1 block w-full rounded-md border-gray-300"><option value="">未設定</option>@foreach($taxOptions as $tax)<option value="{{ $tax['id'] }}" @selected((string) old('purchase_tax_ids.'.$category, $settings['purchase_tax_ids'][$category] ?? '') === (string) $tax['id'])>{{ $tax['name'] ?? $tax['abbreviation'] ?? $tax['id'] }} ({{ $tax['id'] }})</option>@endforeach</select></label>
                @endforeach
            </fieldset>
            @if($taxOptions === [])<p class="-mt-4 text-sm font-semibold text-amber-900">Money Forwardから利用可能な税区分が返っていません。対象期間ごとに「状態未確認」を設定して保存できますが、その期間のCSV出力は停止します。「免税事業者」として税区分を省略する設定は、Money Forward側で免税を確認できた場合だけ選んでください。</p>@endif
            <div class="rounded border border-sky-300 bg-sky-50 p-4 text-sm leading-6 text-sky-950">
                <p><strong>確認先を分けてください。</strong>Money Forwardの「各種設定」→「事業者」→「消費税」にある「課税形式」は、Money Forward上の会計設定です。税務上の課税・免税の判定そのものではありません。</p>
                <p class="mt-2">適格請求書発行事業者の登録状況は、<a class="font-semibold underline" href="https://www.invoice-kohyo.nta.go.jp/" target="_blank" rel="noopener noreferrer">国税庁の公表サイト</a>で登録番号を検索します。番号は通知書またはe-Taxの受信通知で確認し、検索欄には「T」を除いた13桁を入力してください。番号が見つからないことだけで、消費税の課税・免税を判断しないでください。課税形式が不明なら「状態未確認」のままにし、税区分が確認できるまでCSVを出力しないでください。</p>
            </div>
            <fieldset class="rounded-md border border-gray-300 p-4">
                <legend class="px-2 text-sm font-semibold text-gray-900">事業者の課税・免税状態と対象期間</legend>
                <p class="mb-3 text-sm leading-6 text-gray-700">会計年度や税務上の状態が変わる場合に備え、期間を分けて登録します。取引日を含む期間が1件だけ必要です。期間の未設定・重複・状態未確認ではCSV出力を停止します。以前の単一設定は対象期間を特定できないため、自動流用しません。</p>
                <p class="mb-3 rounded border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-950">旧設定の「適格・対象外・控除率」は取引先や証憑の区分であり、自社の課税・免税状態とは確認できません。旧値は「状態未確認」として表示します。税務資料で自社の状態を確認して選び直すまで、CSV出力は停止します。</p>
                <div id="business-tax-periods" class="space-y-3">
                    @forelse($businessTaxPeriods as $index => $period)
                        @php($period = is_array($period) ? $period : [])
                        <div data-business-period-row class="grid gap-3 rounded border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_2fr_auto]">
                            <label class="block text-sm font-semibold text-gray-700">開始日<input type="date" name="business_tax_periods[{{ $index }}][from]" value="{{ $period['from'] ?? '' }}" required class="mt-1 block w-full rounded-md border-gray-300"></label>
                            <label class="block text-sm font-semibold text-gray-700">終了日<input type="date" name="business_tax_periods[{{ $index }}][to]" value="{{ $period['to'] ?? '' }}" required class="mt-1 block w-full rounded-md border-gray-300"></label>
                            <label class="block text-sm font-semibold text-gray-700">この期間の状態<select name="business_tax_periods[{{ $index }}][invoice_kind]" data-business-status-select required class="mt-1 block w-full rounded-md border-gray-300">@foreach(\App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES as $value)<option value="{{ $value }}" @selected($period['invoice_kind'] === $value)>{{ \App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_LABELS[$value] }}</option>@endforeach</select></label>
                            <button type="button" data-remove-business-period class="self-end rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-800">削除</button>
                        </div>
                    @empty
                        <div data-business-period-row class="grid gap-3 rounded border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_2fr_auto]">
                            <label class="block text-sm font-semibold text-gray-700">開始日<input type="date" name="business_tax_periods[0][from]" required class="mt-1 block w-full rounded-md border-gray-300"></label>
                            <label class="block text-sm font-semibold text-gray-700">終了日<input type="date" name="business_tax_periods[0][to]" required class="mt-1 block w-full rounded-md border-gray-300"></label>
                            <label class="block text-sm font-semibold text-gray-700">この期間の状態<select name="business_tax_periods[0][invoice_kind]" data-business-status-select required class="mt-1 block w-full rounded-md border-gray-300">@foreach(\App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES as $value)<option value="{{ $value }}" @selected($value === \App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED)>{{ \App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_LABELS[$value] }}</option>@endforeach</select></label>
                            <button type="button" data-remove-business-period class="self-end rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-800">削除</button>
                        </div>
                    @endforelse
                </div>
                <button type="button" id="add-business-tax-period" class="mt-3 rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-800">対象期間を追加</button>
                <template id="business-tax-period-template">
                    <div data-business-period-row class="grid gap-3 rounded border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_2fr_auto]">
                        <label class="block text-sm font-semibold text-gray-700">開始日<input type="date" name="business_tax_periods[__INDEX__][from]" required class="mt-1 block w-full rounded-md border-gray-300"></label>
                        <label class="block text-sm font-semibold text-gray-700">終了日<input type="date" name="business_tax_periods[__INDEX__][to]" required class="mt-1 block w-full rounded-md border-gray-300"></label>
                    <label class="block text-sm font-semibold text-gray-700">この期間の状態<select name="business_tax_periods[__INDEX__][invoice_kind]" data-business-status-select required class="mt-1 block w-full rounded-md border-gray-300">@foreach(\App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES as $value)<option value="{{ $value }}" @selected($value === \App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED)>{{ \App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_LABELS[$value] }}</option>@endforeach</select></label>
                        <button type="button" data-remove-business-period class="self-end rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-800">削除</button>
                    </div>
                </template>
            </fieldset>
            <div class="flex flex-wrap gap-3"><button class="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white">設定を保存</button><a href="{{ route('furimadeck-accounting.index') }}" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700">戻る</a></div>
        </form>
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            const businessTaxPeriods = document.getElementById('business-tax-periods');
            const businessTaxPeriodTemplate = document.getElementById('business-tax-period-template');
            const existingBusinessTaxPeriodIndices = Array.from(businessTaxPeriods?.querySelectorAll('[data-business-period-row] [name^="business_tax_periods["]') ?? [])
                .map((field) => Number(field.getAttribute('name')?.match(/^business_tax_periods\[(\d+)\]/)?.[1] ?? -1));
            let nextBusinessTaxPeriodIndex = Math.max(-1, ...existingBusinessTaxPeriodIndices) + 1;
            const taxableBusinessStatus = @js(\App\Services\FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
            const invoiceKindRequiredRoles = new Set(['settlement', 'sales', 'fees', 'cost', 'inventory']);
            const updateInvoiceKindControls = () => {
                const requireInvoiceKinds = Array.from(businessTaxPeriods?.querySelectorAll('[data-business-status-select]') ?? [])
                    .some((select) => select.value === taxableBusinessStatus);
                document.querySelectorAll('[data-invoice-kind-fields]').forEach((fields) => { fields.hidden = !requireInvoiceKinds; });
                document.querySelectorAll('[data-invoice-kind-hold]').forEach((message) => { message.hidden = requireInvoiceKinds; });
                document.querySelectorAll('[data-invoice-kind-role]').forEach((select) => {
                    select.disabled = !requireInvoiceKinds;
                    select.required = requireInvoiceKinds && invoiceKindRequiredRoles.has(select.dataset.invoiceKindRole);
                });
            };
            updateInvoiceKindControls();
            document.getElementById('add-business-tax-period')?.addEventListener('click', () => {
                if (!businessTaxPeriods || !businessTaxPeriodTemplate) return;
                const index = String(nextBusinessTaxPeriodIndex++);
                const row = businessTaxPeriodTemplate.content.cloneNode(true);
                row.querySelectorAll('[name]').forEach((field) => {
                    const name = field.getAttribute('name');
                    if (name) field.setAttribute('name', name.replaceAll('__INDEX__', index));
                });
                businessTaxPeriods.append(row);
                updateInvoiceKindControls();
            });
            businessTaxPeriods?.addEventListener('click', (event) => {
                const target = event.target;
                if (!(target instanceof Element) || !target.closest('[data-remove-business-period]')) return;
                if (businessTaxPeriods.querySelectorAll('[data-business-period-row]').length <= 1) return;
                target.closest('[data-business-period-row]')?.remove();
                updateInvoiceKindControls();
            });
            businessTaxPeriods?.addEventListener('change', (event) => {
                if (event.target instanceof HTMLSelectElement && event.target.matches('[data-business-status-select]')) {
                    updateInvoiceKindControls();
                }
            });
            document.querySelectorAll('select[name^="account_ids["]').forEach((select) => {
                const output = select.closest('label')?.querySelector('[data-default-tax]');
                const update = () => { if (output) output.textContent = select.selectedOptions[0]?.dataset.taxName || '科目を選択してください'; };
                select.addEventListener('change', update);
                update();
            });
        </script>
    </div></div>
</x-app-layout>
