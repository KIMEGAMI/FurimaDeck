<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingEntrySync extends Model
{
    public const LAST_ERROR_LENGTH = 100;

    public const STATUS_CSV_IMPORTED = 'csv_imported';

    public const STATUS_MANUALLY_RECONCILED = 'manually_reconciled';

    public const MANUAL_RECONCILIATION_AMOUNT_MIN = 1;

    public const MANUAL_RECONCILIATION_AMOUNT_MAX = 999999999999;

    public const MANUAL_RECONCILIATION_TEXT_MAX_LENGTH = 2000;

    public const MANUAL_RECONCILIATION_EXTERNAL_ID_MAX_LENGTH = 255;

    public const EXTERNAL_VOUCHER_DELETED_TAX_REVIEW = 'external_voucher_deleted_tax_review';

    public const VERIFIED_ABSENT_INACTIVE_SALE = 'verified_absent_inactive_sale';

    public const PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED = 'provider_default_invoice_kind_unverified';

    public const PROVIDER_DEFAULT_INVOICE_KIND_MANUALLY_CONFIRMED = 'provider_default_invoice_kind_manually_confirmed';

    protected $fillable = [
        'accounting_entry_id', 'provider', 'status', 'external_transaction_id',
        'external_import_id', 'provider_account_scope_hash', 'last_error', 'attempt_count', 'attempted_at', 'synced_at', 'request_payload', 'sensitive_request_payload', 'verified_at',
        'manual_invoice_kind_confirmed_at', 'manual_invoice_kind_confirmed_by_user_id', 'manual_invoice_kind_evidence_hash',
    ];

    protected $hidden = [
        'sensitive_request_payload',
        'provider_account_scope_hash',
        'manual_reconciliation_evidence',
        'manual_reconciled_by_user_id',
        'manual_reconciled_at',
        'manual_invoice_kind_confirmed_by_user_id',
        'manual_invoice_kind_evidence_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'attempted_at' => 'datetime',
            'synced_at' => 'datetime',
            'request_payload' => 'array',
            'sensitive_request_payload' => 'encrypted:array',
            'verified_at' => 'datetime',
            'manual_reconciliation_evidence' => 'array',
            'manual_reconciled_by_user_id' => 'integer',
            'manual_reconciled_at' => 'datetime',
            'manual_invoice_kind_confirmed_at' => 'datetime',
            'manual_invoice_kind_confirmed_by_user_id' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(AccountingEntry::class, 'accounting_entry_id');
    }

    /** @return array<string, mixed>|null */
    public function accountingPayload(): ?array
    {
        return $this->sensitive_request_payload ?? $this->request_payload;
    }

    /** @param array<string, mixed> $payload */
    public function setAccountingPayload(array $payload, bool $containsSensitiveRemarks = false): void
    {
        $publicPayload = $payload;
        if ($containsSensitiveRemarks) {
            foreach (['branches' => 'remark', 'details' => 'description'] as $collection => $field) {
                if (! is_array($publicPayload[$collection] ?? null)) {
                    continue;
                }
                foreach ($publicPayload[$collection] as &$row) {
                    if (is_array($row) && is_string($row[$field] ?? null)) {
                        $row[$field] = '[暗号化された仕入摘要]';
                    }
                }
                unset($row);
            }
        }

        $this->request_payload = $publicPayload;
        $this->sensitive_request_payload = $containsSensitiveRemarks ? $payload : null;
    }

    public function getLastErrorLabelAttribute(): ?string
    {
        if ($this->last_error === null || $this->last_error === '') {
            return null;
        }
        $knownErrors = [
            'worker_interrupted' => '送信中に処理が中断され、登録結果を確認できません。会計サービス側の仕訳帳を確認してください。',
            'queue_interrupted_before_send' => '送信キューが送信前に停止しました。会計サービスへの登録は行われていません。',
            self::VERIFIED_ABSENT_INACTIVE_SALE => '会計サービスに仕訳がないことを確認済みです。売上は取消・返品済みのため再送対象ではありません。',
            self::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED => 'Money Forwardから送信していないインボイス区分「対象外」が返りました。正しい区分か確認できないため未照合です。登録済み仕訳の自動修正・再送はしません。',
            self::PROVIDER_DEFAULT_INVOICE_KIND_MANUALLY_CONFIRMED => 'Money Forwardが補ったインボイス区分「対象外」を利用者が画面で確認しました。これはAPIの完全一致確認ではありません。再送はしません。',
        ];
        if (isset($knownErrors[$this->last_error])) {
            return $knownErrors[$this->last_error];
        }
        if (str_starts_with($this->last_error, 'external_content_mismatch:')) {
            $labels = [
                'transaction_date' => '取引日',
                'journal_type' => '仕訳種類',
                'memo' => '摘要',
                'branches' => '仕訳明細の数・金額・勘定科目',
                'debit_tax' => '借方の税区分',
                'debit_sub' => '借方の補助科目',
                'debit_invoice' => '借方のインボイス区分',
                'credit_tax' => '貸方の税区分',
                'credit_sub' => '貸方の補助科目',
                'credit_invoice' => '貸方のインボイス区分',
            ];
            $fields = explode(',', substr($this->last_error, strlen('external_content_mismatch:')));
            $mismatchText = collect($fields)->map(fn ($field) => $labels[$field] ?? '仕訳内容')->unique()->implode('、');

            return '不一致項目: '.$mismatchText.'。値そのものは保存していません。会計ソフト側の仕訳を確認してください。FurimaDeckから自動再送はしません。';
        }

        return '診断: '.$this->last_error;
    }

    public function hasInvoiceKindMismatch(): bool
    {
        $prefix = 'external_content_mismatch:';
        if (! is_string($this->last_error) || ! str_starts_with($this->last_error, $prefix)) {
            return false;
        }

        return array_intersect(
            ['debit_invoice', 'credit_invoice'],
            explode(',', substr($this->last_error, strlen($prefix))),
        ) !== [];
    }

    public function hasUnverifiedProviderInvoiceKindDefault(): bool
    {
        return $this->last_error === self::PROVIDER_DEFAULT_INVOICE_KIND_UNVERIFIED;
    }

    public function hasManuallyConfirmedProviderInvoiceKindDefault(): bool
    {
        return $this->last_error === self::PROVIDER_DEFAULT_INVOICE_KIND_MANUALLY_CONFIRMED
            && $this->manual_invoice_kind_confirmed_at !== null
            && filled($this->manual_invoice_kind_evidence_hash);
    }
}
