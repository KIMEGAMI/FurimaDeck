<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FurimaDeckSaleExpenseAccountingService
{
    /** @var array<string, string> */
    public const EXPENSES = [
        'packing_cost' => '梱包費',
        'repair_cost' => '修理費',
        'cleaning_cost' => '清掃費',
        'other_expense' => 'その他経費',
    ];

    public const DOCUMENT_TYPE_LABELS = [
        'receipt' => '領収書',
        'invoice' => '請求書',
        'other' => 'その他の証憑',
        'unknown' => '未確認（送信保留）',
    ];

    public const TAX_CATEGORY_LABELS = [
        'standard_10' => '標準税率10%',
        'reduced_8' => '軽減税率8%',
        'exempt' => '非課税',
        'non_taxable' => '不課税',
        'out_of_scope' => '対象外',
        'unknown' => '未確認（送信保留）',
    ];

    public const INVOICE_ISSUER_LABELS = [
        'qualified' => '適格請求書発行事業者',
        'unqualified' => '適格請求書発行事業者以外',
        'not_applicable' => '対象外',
        'unknown' => '未確認（送信保留）',
    ];

    private const PROVIDERS = ['freee', 'money_forward'];

    private const IMMUTABLE_SYNC_STATUSES = [
        'queued', 'sending', 'synced', 'sync_unknown', 'legacy_unknown', 'csv_pending',
        AccountingEntrySync::STATUS_CSV_IMPORTED,
        AccountingEntrySync::STATUS_MANUALLY_RECONCILED,
    ];

    private const IMMUTABLE_ENTRY_STATUSES = [
        'queued', 'sending', 'synced', 'sync_unknown', 'legacy_unknown', 'csv_pending',
        AccountingEntrySync::STATUS_CSV_IMPORTED,
        AccountingEntrySync::STATUS_MANUALLY_RECONCILED,
    ];

    public function __construct(private readonly FurimaDeckAccountingEntryService $accountingEntries) {}

    /** @return array<string, list<array{id:string,name:string}>> */
    public function paymentSourceOptions(User $user): array
    {
        $options = ['freee' => [], 'money_forward' => []];
        $connections = $user->accountingConnections()
            ->whereIn('provider', self::PROVIDERS)
            ->whereIn('status', ['connected', 'expired'])
            ->get()
            ->keyBy('provider');

        foreach (self::PROVIDERS as $provider) {
            $connection = $connections->get($provider);
            foreach ((array) data_get($connection?->settings, 'purchase_payment_accounts', []) as $id => $account) {
                if (! is_array($account)) {
                    continue;
                }
                $accountId = trim((string) $id);
                $name = trim((string) ($account['name'] ?? ''));
                if ($accountId === '' || $name === '' || trim((string) ($account['account_id'] ?? '')) === '') {
                    continue;
                }
                $options[$provider][] = ['id' => $accountId, 'name' => $name];
            }
        }

        return $options;
    }

    /**
     * @param  array<string, int|string>  $amounts
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $existing
     * @return array<string, array<string, mixed>>
     */
    public function normalize(User $user, array $amounts, array $submitted, array $existing = []): array
    {
        $normalized = [];
        $connections = $user->accountingConnections()
            ->whereIn('provider', self::PROVIDERS)
            ->whereIn('status', ['connected', 'expired'])
            ->get()
            ->keyBy('provider');

        foreach (self::EXPENSES as $field => $_label) {
            if ((int) ($amounts[$field] ?? 0) <= 0) {
                continue;
            }

            $item = is_array($submitted[$field] ?? null) ? $submitted[$field] : [];
            $old = is_array($existing[$field] ?? null) ? $existing[$field] : [];
            $documentType = (string) ($item['document_type'] ?? $old['document_type'] ?? '');
            $taxCategory = (string) ($item['tax_category'] ?? $old['tax_category'] ?? '');
            $invoiceIssuer = (string) ($item['invoice_issuer'] ?? $old['invoice_issuer'] ?? '');
            $capReviewId = trim((string) ($item['cap_review_id'] ?? $old['cap_review_id'] ?? ''));
            $hasSubmittedItem = array_key_exists($field, $submitted) && is_array($submitted[$field]);
            $dateConfirmation = $hasSubmittedItem
                ? ($item['date_matches_entry_confirmed'] ?? false)
                : ($old['date_matches_entry_confirmed'] ?? false);
            $dateMatchesEntryConfirmed = in_array($dateConfirmation, [true, 1, '1', 'on'], true);
            if (($documentType !== '' && ! array_key_exists($documentType, self::DOCUMENT_TYPE_LABELS))
                || ($taxCategory !== '' && ! array_key_exists($taxCategory, self::TAX_CATEGORY_LABELS))
                || ($invoiceIssuer !== '' && ! array_key_exists($invoiceIssuer, self::INVOICE_ISSUER_LABELS))) {
                throw new RuntimeException(self::EXPENSES[$field].'の証憑情報が無効です。内容を確認してください。');
            }

            $sources = (array) ($old['payment_sources'] ?? []);
            $inputSources = is_array($item['payment_sources'] ?? null) ? $item['payment_sources'] : [];
            foreach (self::PROVIDERS as $provider) {
                $connection = $connections->get($provider);
                $configured = (array) data_get($connection?->settings, 'purchase_payment_accounts', []);
                if ($connection === null || $configured === []) {
                    continue;
                }
                $sourceId = array_key_exists($provider, $inputSources)
                    ? trim((string) ($inputSources[$provider] ?? ''))
                    : trim((string) ($sources[$provider] ?? ''));
                if ($sourceId !== '' && ! $this->isConfiguredSource($configured, $sourceId)) {
                    throw new RuntimeException(self::EXPENSES[$field].'の'.$this->providerName($provider).'支払元を選択してください。仕訳設定の支払元候補も確認してください。');
                }
                if ($sourceId === '') {
                    unset($sources[$provider]);
                } else {
                    $sources[$provider] = $sourceId;
                }
            }

            $details = array_filter([
                'document_type' => $documentType,
                'tax_category' => $taxCategory,
                'invoice_issuer' => $invoiceIssuer,
            ], static fn (string $value): bool => $value !== '');
            $classified = in_array($documentType, array_diff(array_keys(self::DOCUMENT_TYPE_LABELS), ['unknown']), true)
                && in_array($taxCategory, array_diff(array_keys(self::TAX_CATEGORY_LABELS), ['unknown']), true)
                && in_array($invoiceIssuer, array_diff(array_keys(self::INVOICE_ISSUER_LABELS), ['unknown']), true);
            if ($classified) {
                $details['date_matches_entry_confirmed'] = $dateMatchesEntryConfirmed;
                if ($dateMatchesEntryConfirmed) {
                    $details['reviewed_at'] = now()->toIso8601String();
                }
            }
            if ($capReviewId !== '') {
                $details['cap_review_id'] = (int) $capReviewId;
            }
            if ($sources !== []) {
                $details['payment_sources'] = $sources;
            }
            if ($details !== []) {
                $normalized[$field] = $details;
            }
        }

        return $normalized;
    }

    public function assertClassified(AccountingEntry $entry): void
    {
        if ($entry->entry_type !== AccountingEntry::TYPE_SALE) {
            return;
        }

        $breakdown = (array) $entry->expense_breakdown;
        $evidence = (array) ($breakdown['sale_expenses'] ?? []);
        foreach (self::EXPENSES as $field => $label) {
            if ((int) ($breakdown[$field] ?? 0) <= 0) {
                continue;
            }
            $item = (array) ($evidence[$field] ?? []);
            if (! in_array($item['document_type'] ?? null, array_diff(array_keys(self::DOCUMENT_TYPE_LABELS), ['unknown']), true)
                || ! in_array($item['tax_category'] ?? null, array_diff(array_keys(self::TAX_CATEGORY_LABELS), ['unknown']), true)
                || ! in_array($item['invoice_issuer'] ?? null, array_diff(array_keys(self::INVOICE_ISSUER_LABELS), ['unknown']), true)
                || ($item['date_matches_entry_confirmed'] ?? false) !== true
                || ! is_string($item['reviewed_at'] ?? null) || trim($item['reviewed_at']) === '') {
                throw new RuntimeException($label.'の証憑情報または証憑日と販売仕訳日の一致が未確認です。確認するまで、Confirm・送信・CSV出力はできません。');
            }
        }
    }

    /** @return array<string, array{source_id:string,source:array<string,mixed>,evidence:array<string,mixed>}> */
    public function providerDetails(AccountingEntry $entry, string $provider, array $settings): array
    {
        $this->assertClassified($entry);
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('会計サービスの指定が正しくありません。');
        }

        $sale = $entry->loadMissing('sale')->sale;
        if ($sale === null || $sale->user_id !== $entry->user_id) {
            throw new RuntimeException('販売記録を確認できません。会計データは送信していません。');
        }
        $evidence = (array) data_get($entry->expense_breakdown, 'sale_expenses', []);
        $saleDetails = (array) ($sale->accounting_expense_details ?? []);
        $sources = (array) data_get($settings, 'purchase_payment_accounts', []);
        $details = [];
        foreach (self::EXPENSES as $field => $label) {
            if ((int) ($sale->{$field} ?? 0) <= 0) {
                continue;
            }
            $sourceId = trim((string) data_get($saleDetails, $field.'.payment_sources.'.$provider, ''));
            $source = (array) ($sources[$sourceId] ?? []);
            if (! $this->isConfiguredSource($sources, $sourceId)
                || trim((string) ($source['account_id'] ?? '')) === '') {
                throw new RuntimeException($label.'の'.$this->providerName($provider).'支払元が未設定または無効です。仕訳は送信・CSV出力していません。');
            }
            $details[$field] = [
                'source_id' => $sourceId,
                'source' => $source,
                'evidence' => (array) ($evidence[$field] ?? []),
            ];
        }

        return $details;
    }

    /** @param array<string, mixed> $submitted */
    public function updateExpenseDetails(User $user, Sale $sale, array $submitted): Sale
    {
        return DB::connection('furimadeck')->transaction(function () use ($user, $sale, $submitted): Sale {
            $locked = Sale::query()->whereKey($sale->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, Sale::VALID_SOLD_STATUSES, true)) {
                throw new RuntimeException('取消・返品済みの販売記録では会計情報を変更できません。');
            }

            $entry = AccountingEntry::query()
                ->where('user_id', $user->id)
                ->where('sale_id', $locked->id)
                ->lockForUpdate()
                ->first();
            if ($entry !== null && $this->hasImmutableExternalUse($entry)) {
                throw new RuntimeException('会計サービスへ送信・取込済み、処理中、または結果未確定のため、会計情報を変更できません。');
            }

            $amounts = [];
            foreach (self::EXPENSES as $field => $_label) {
                $amounts[$field] = (int) $locked->{$field};
            }
            $existing = (array) ($locked->accounting_expense_details ?? []);
            $normalized = $this->normalize($user, $amounts, $submitted, $existing);
            $locked->update(['accounting_expense_details' => $normalized]);

            $this->accountingEntries->recordSale($user, $locked);

            return $locked->refresh();
        });
    }

    /**
     * Update only provider payment-source mappings. Financial evidence and amounts are immutable after external use.
     *
     * @param  array<string, string>  $sourceIds  keyed by expense field
     */
    public function updateProviderSources(User $user, Sale $sale, string $provider, array $sourceIds): Sale
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('会計サービスの指定が正しくありません。');
        }

        return DB::connection('furimadeck')->transaction(function () use ($user, $sale, $provider, $sourceIds): Sale {
            $locked = Sale::query()->whereKey($sale->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, Sale::VALID_SOLD_STATUSES, true)) {
                throw new RuntimeException('取消・返品済みの販売記録では支払元を変更できません。');
            }
            $connection = $user->accountingConnections()->where('provider', $provider)->whereIn('status', ['connected', 'expired'])->first();
            if ($connection === null) {
                throw new RuntimeException($this->providerName($provider).'が連携されていません。先に会計サービスを連携してください。');
            }
            $configured = (array) data_get($connection->settings, 'purchase_payment_accounts', []);
            $details = (array) ($locked->accounting_expense_details ?? []);
            $entry = $user->accountingEntries()->where('sale_id', $locked->id)->first();
            $providerSync = $entry?->providerSync($provider);

            foreach (self::EXPENSES as $field => $label) {
                if ((int) $locked->{$field} <= 0) {
                    continue;
                }
                $item = (array) ($details[$field] ?? []);
                if (! in_array($item['document_type'] ?? null, array_diff(array_keys(self::DOCUMENT_TYPE_LABELS), ['unknown']), true)
                    || ! in_array($item['tax_category'] ?? null, array_diff(array_keys(self::TAX_CATEGORY_LABELS), ['unknown']), true)
                    || ! in_array($item['invoice_issuer'] ?? null, array_diff(array_keys(self::INVOICE_ISSUER_LABELS), ['unknown']), true)
                    || ! is_string($item['reviewed_at'] ?? null) || trim($item['reviewed_at']) === '') {
                    throw new RuntimeException($label.'の証憑情報を先に会計データ画面で確認してください。');
                }
                if (! array_key_exists($field, $sourceIds)) {
                    continue;
                }
                $sourceId = trim((string) $sourceIds[$field]);
                if (! $this->isConfiguredSource($configured, $sourceId)) {
                    throw new RuntimeException($label.'の'.$this->providerName($provider).'支払元が設定候補にありません。');
                }
                $oldSource = trim((string) data_get($details, $field.'.payment_sources.'.$provider, ''));
                if ($oldSource !== $sourceId && $providerSync !== null
                    && (in_array($providerSync->status, self::IMMUTABLE_SYNC_STATUSES, true)
                        || filled($providerSync->external_transaction_id) || filled($providerSync->external_import_id))) {
                    throw new RuntimeException($label.'は'.$this->providerName($provider).'へ送信・取込済み、処理中、または結果不明です。支払元を変更できません。');
                }
                data_set($details, $field.'.payment_sources.'.$provider, $sourceId);
            }

            $locked->update(['accounting_expense_details' => $details]);

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $configured */
    private function isConfiguredSource(array $configured, string $sourceId): bool
    {
        if ($sourceId === '' || ! is_array($configured[$sourceId] ?? null)) {
            return false;
        }

        return trim((string) ($configured[$sourceId]['account_id'] ?? '')) !== '';
    }

    private function providerName(string $provider): string
    {
        return $provider === 'freee' ? 'freee' : 'Money Forward';
    }

    private function hasImmutableExternalUse(AccountingEntry $entry): bool
    {
        if (filled($entry->external_transaction_id)
            || in_array($entry->sync_status, self::IMMUTABLE_ENTRY_STATUSES, true)) {
            return true;
        }

        return $entry->providerSyncs()->where(function ($query): void {
            $query->whereIn('status', self::IMMUTABLE_SYNC_STATUSES)
                ->orWhereNotNull('external_transaction_id')
                ->orWhereNotNull('external_import_id');
        })->exists();
    }
}
