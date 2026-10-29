@php($manualEvidence = $providerSync->manual_reconciliation_evidence)
@if($providerSync->status === \App\Models\AccountingEntrySync::STATUS_MANUALLY_RECONCILED && is_array($manualEvidence))
    <div class="mt-1 rounded border border-amber-300 bg-amber-50 p-2 text-xs leading-5 text-amber-950">
        <strong>利用者による手動照合</strong><br>
        仕訳ID: {{ $providerSync->external_transaction_id }} / 取引日: {{ $manualEvidence['transaction_date'] ?? '記録なし' }}<br>
        借方・貸方: {{ number_format((int) ($manualEvidence['debit_total'] ?? 0)) }}円 / {{ number_format((int) ($manualEvidence['credit_total'] ?? 0)) }}円<br>
        勘定科目: {{ $manualEvidence['accounts'] ?? '' }}<br>
        税区分: {{ $manualEvidence['tax_classifications'] ?? '' }}<br>
        記録日時: {{ $providerSync->manual_reconciled_at?->format('Y/m/d H:i') ?? '記録なし' }}
    </div>
@endif
