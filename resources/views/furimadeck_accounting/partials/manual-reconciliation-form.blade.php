<details class="mt-2 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
    <summary class="cursor-pointer font-semibold">旧記録を手動照合</summary>
    <p class="mt-2 leading-5">{{ $provider === 'freee' ? 'freee' : 'Money Forward' }}側の仕訳帳を開き、対象明細と照合してから入力してください。ここに入力した内容は監査記録として保存されます。会計サービス側の内容を自動取得するものではなく、保存後は再登録できません。取引先の個人情報や住所は入力しないでください。</p>
    <form method="post" action="{{ route('furimadeck-accounting.sync.resolve', [$entry, $provider]) }}" class="mt-3 space-y-3">
        @csrf
        <input type="hidden" name="action" value="manual_reconcile">
        <label class="block font-semibold">外部仕訳ID
            <input name="manual_external_transaction_id" required maxlength="{{ \App\Models\AccountingEntrySync::MANUAL_RECONCILIATION_EXTERNAL_ID_MAX_LENGTH }}" class="mt-1 w-full rounded border-amber-500 bg-white text-gray-950">
        </label>
        <label class="block font-semibold">外部仕訳の取引日
            <input name="manual_transaction_date" type="date" required value="{{ $entry->transaction_date?->toDateString() }}" class="mt-1 w-full rounded border-amber-500 bg-white text-gray-950">
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="block font-semibold">借方合計（円）
                <input name="manual_debit_total" type="number" min="0" max="{{ \App\Models\AccountingEntrySync::MANUAL_RECONCILIATION_AMOUNT_MAX }}" step="1" required class="mt-1 w-full rounded border-amber-500 bg-white text-gray-950">
            </label>
            <label class="block font-semibold">貸方合計（円）
                <input name="manual_credit_total" type="number" min="0" max="{{ \App\Models\AccountingEntrySync::MANUAL_RECONCILIATION_AMOUNT_MAX }}" step="1" required class="mt-1 w-full rounded border-amber-500 bg-white text-gray-950">
            </label>
        </div>
        <label class="block font-semibold">勘定科目・補助科目（仕訳帳に表示された名称）
            <textarea name="manual_accounts" rows="2" maxlength="{{ \App\Models\AccountingEntrySync::MANUAL_RECONCILIATION_TEXT_MAX_LENGTH }}" required class="mt-1 w-full rounded border-amber-500 bg-white text-gray-950"></textarea>
        </label>
        <label class="block font-semibold">税区分（仕訳帳に表示された区分）
            <textarea name="manual_tax_classifications" rows="2" maxlength="{{ \App\Models\AccountingEntrySync::MANUAL_RECONCILIATION_TEXT_MAX_LENGTH }}" required class="mt-1 w-full rounded border-amber-500 bg-white text-gray-950"></textarea>
        </label>
        <fieldset class="space-y-2 border-t border-amber-300 pt-3">
            <legend class="font-bold">照合した項目をすべて確認してください</legend>
            @foreach([
                'confirm_same_entry' => 'この仕訳が画面上の商品・売上明細に対応する',
                'confirm_accounting_service' => '照合先が表示された会計サービスである',
                'confirm_journal_id' => '外部仕訳IDが正確である',
                'confirm_transaction_date' => '取引日が正確である',
                'confirm_amounts' => '借方・貸方の金額が正確である',
                'confirm_accounts' => '勘定科目・補助科目が正確である',
                'confirm_tax_classifications' => '税区分が正確である',
                'confirm_no_retry' => 'これは手動確認の記録であり、再登録できない',
            ] as $field => $label)
                <label class="flex items-start gap-2"><input type="checkbox" name="{{ $field }}" value="1" required class="mt-1 rounded border-amber-600 text-amber-800"><span>{{ $label }}</span></label>
            @endforeach
        </fieldset>
        <button class="rounded bg-amber-800 px-3 py-2 font-bold text-white">手動照合記録を保存</button>
    </form>
</details>
