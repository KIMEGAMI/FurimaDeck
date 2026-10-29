@php($savedPurchaseSpecialCaseData = (array) ($product?->purchase_special_case_data ?? []))
@php($purchaseSpecialCaseData = (array) old('purchase_special_case', $savedPurchaseSpecialCaseData))
@php($purchaseSpecialCaseEnabled = (bool) ($purchaseSpecialCaseData['enabled'] ?? (($savedPurchaseSpecialCaseData['scheme'] ?? null) === 'antique_dealer')))
@php($purchaseSpecialCaseConfirmations = (array) ($purchaseSpecialCaseData['confirmations'] ?? []))
<fieldset class="mt-4 rounded border border-amber-300 bg-white p-3 md:col-span-2">
    <legend class="px-1 text-sm font-black text-slate-900">古物商等の特例（必要な場合のみ）</legend>
    <label class="flex items-start gap-2 text-sm font-semibold text-slate-900">
        <input id="purchase-special-case-enabled" type="checkbox" name="purchase_special_case[enabled]" value="1" @checked($purchaseSpecialCaseEnabled) class="mt-1">
        <span>この商品代に古物商等の特例を適用することを確認した</span>
    </label>
    <p class="mt-2 text-sm leading-6 text-slate-700">利用には、古物商許可・再販売目的・対象となる古物等・発行者区分などの要件確認が必要です。FurimaDeckは適用可否を自動判定しません。要件が不明なら選択せず、仕入データを保留してください。許可番号と取引先情報はFurimaDeck内で暗号化して保存し、ログや通常の商品CSVには平文で含めません。対応する会計CSVに含める場合、会計サービス側やCSVに許可番号・取引先名・住所が平文で残ることがあります。Money Forwardの仕入CSVは必要な区分を正確に表現できないため出力対象外です。</p>
    <div id="purchase-special-case-fields" class="mt-3 grid gap-3 md:grid-cols-2" @unless($purchaseSpecialCaseEnabled) hidden @endunless>
        <label class="block text-sm font-bold text-slate-900">古物商許可番号
            <input data-special-required type="text" name="purchase_special_case[license_number]" maxlength="100" value="{{ old('purchase_special_case.license_number', $purchaseSpecialCaseData['license_number'] ?? '') }}" class="mt-1 w-full rounded border-amber-400 bg-white text-slate-900" @if($purchaseSpecialCaseEnabled) required @endif>
        </label>
        <label class="block text-sm font-bold text-slate-900">取引相手の氏名・名称
            <input data-special-required type="text" name="purchase_special_case[counterparty_name]" maxlength="255" value="{{ old('purchase_special_case.counterparty_name', $purchaseSpecialCaseData['counterparty_name'] ?? '') }}" class="mt-1 w-full rounded border-amber-400 bg-white text-slate-900" @if($purchaseSpecialCaseEnabled) required @endif>
        </label>
        <label class="block text-sm font-bold text-slate-900 md:col-span-2">取引相手の住所
            <textarea data-special-required name="purchase_special_case[counterparty_address]" maxlength="1000" rows="2" class="mt-1 w-full rounded border-amber-400 bg-white text-slate-900" @if($purchaseSpecialCaseEnabled) required @endif>{{ old('purchase_special_case.counterparty_address', $purchaseSpecialCaseData['counterparty_address'] ?? '') }}</textarea>
        </label>
        <div class="grid gap-2 md:col-span-2">
            @foreach([
                'dealer_license' => '自分が有効な古物商許可を持つ',
                'qualifying_used_goods' => 'この仕入商品が特例対象となる古物等に該当する',
                'resale_inventory' => '転売目的の棚卸資産として仕入れた',
                'supplier_unqualified' => '仕入先が適格請求書発行事業者ではないことを確認した',
            ] as $confirmation => $label)
                <label class="flex items-start gap-2 text-sm font-semibold text-slate-900">
                    <input data-special-required type="checkbox" name="purchase_special_case[confirmations][{{ $confirmation }}]" value="1" @checked($purchaseSpecialCaseConfirmations[$confirmation] ?? false) class="mt-1" @if($purchaseSpecialCaseEnabled) required @endif>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
            <label class="flex items-start gap-2 text-sm font-semibold text-slate-900">
                <input type="checkbox" name="purchase_special_case[confirmations][not_specific_metal_scrap]" value="1" data-metal-exclusion-confirmation data-effective-date="{{ \App\Services\FurimaDeckPurchaseSpecialCaseService::SPECIFIED_METAL_EXCLUSION_EFFECTIVE_FROM }}" @checked($purchaseSpecialCaseConfirmations['not_specific_metal_scrap'] ?? false) class="mt-1">
                <span>仕入日が2026年9月1日以降の場合、この商品は古物商等特例から除外された「特定金属くず」ではないことを確認した</span>
            </label>
        </div>
    </div>
</fieldset>
<script>
    (() => {
        const checkbox = document.getElementById('purchase-special-case-enabled');
        const fields = document.getElementById('purchase-special-case-fields');
        const purchaseDate = document.querySelector('[name="purchase_date"]');
        if (!checkbox || !fields) return;
        const metalConfirmation = fields.querySelector('[data-metal-exclusion-confirmation]');
        const updateSpecialCaseFields = () => {
            fields.toggleAttribute('hidden', !checkbox.checked);
            fields.querySelectorAll('[data-special-required]').forEach((field) => {
                field.required = checkbox.checked;
            });
            if (metalConfirmation) {
                metalConfirmation.required = checkbox.checked
                    && Boolean(purchaseDate?.value)
                    && purchaseDate.value >= metalConfirmation.dataset.effectiveDate;
            }
        };
        checkbox.addEventListener('change', updateSpecialCaseFields);
        purchaseDate?.addEventListener('change', updateSpecialCaseFields);
        purchaseDate?.addEventListener('input', updateSpecialCaseFields);
        updateSpecialCaseFields();
    })();
</script>
