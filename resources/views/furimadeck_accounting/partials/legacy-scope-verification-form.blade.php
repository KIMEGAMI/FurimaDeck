<div class="mt-2 rounded border border-amber-300 bg-amber-50 p-3 text-xs leading-5 text-amber-950">
    <p class="font-bold">送信時の事業者IDが保存されていないため、通常の自動照合は停止しています。</p>
    <p class="mt-1">{{ $provider === 'freee' ? 'freee' : 'Money Forward' }}で現在選択中の事業者を確認し、次の仕訳IDがその事業者の仕訳帳に存在する場合だけ読み戻します。登録・編集・削除・再送はしません。一致した場合に限り、現在の事業者との紐付けを保存します。</p>
    <p class="mt-1">保存済み仕訳ID: <code class="font-bold">{{ $providerSync->external_transaction_id }}</code></p>
    <form method="post" action="{{ route('furimadeck-accounting.sync.verify', [$entry, $provider]) }}" class="mt-2 space-y-2">
        @csrf
        <input type="hidden" name="bind_legacy_scope" value="1">
        <label class="flex items-start gap-2"><input type="checkbox" name="confirm_current_account" value="1" required class="mt-0.5 rounded border-amber-700"><span>現在の会計事業者が、この仕訳を登録した事業者と同じことを確認した</span></label>
        <label class="flex items-start gap-2"><input type="checkbox" name="confirm_external_transaction_id" value="1" required class="mt-0.5 rounded border-amber-700"><span>会計ソフト側の仕訳帳に上記の仕訳IDがあることを確認した</span></label>
        <label class="flex items-start gap-2"><input type="checkbox" name="confirm_read_only" value="1" required class="mt-0.5 rounded border-amber-700"><span>この操作は読取確認のみで、再送・変更・削除をしないことを確認した</span></label>
        <button class="rounded bg-amber-800 px-3 py-1.5 font-bold text-white">確認した事業者で読み戻す</button>
    </form>
</div>
