<?php

namespace App\Services;

use App\Jobs\SendFreeeAccountingEntryJob;
use App\Jobs\SendMoneyForwardAccountingEntryJob;
use App\Models\AccountingEntry;
use App\Models\AccountingSyncBatch;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FurimaDeckAccountingBatchService
{
    public const QUEUE_CONNECTION = 'database';

    public function __construct(private FurimaDeckPurchasePaymentSourceReadiness $paymentSourceReadiness) {}

    public function startFreee(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive): AccountingSyncBatch
    {
        return $this->start($user, 'freee', $from, $toExclusive);
    }

    public function startMoneyForward(User $user, CarbonImmutable $from, CarbonImmutable $toExclusive): AccountingSyncBatch
    {
        return $this->start($user, 'money_forward', $from, $toExclusive);
    }

    private function start(User $user, string $provider, CarbonImmutable $from, CarbonImmutable $toExclusive): AccountingSyncBatch
    {
        $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
        if (! app(FurimaDeckAccountingApiReleaseGate::class)->enabled()) {
            throw new RuntimeException('FurimaDeck 1.0では会計ソフトへのAPI仕訳送信を提供していません。会計ソフト用CSVを利用してください。');
        }
        $connection = $user->accountingConnections()->where('provider', $provider)->where('status', 'connected')->first();
        if ($connection === null) {
            throw new RuntimeException($providerName.'と連携してから一括送信してください。');
        }
        $missingConfiguration = $provider === 'freee'
            ? app(FurimaDeckFreeeAccountingSyncService::class)->missingConfiguration($connection)
            : app(FurimaDeckMoneyForwardAccountingSyncService::class)->missingConfiguration($connection);
        if ($missingConfiguration !== []) {
            throw new RuntimeException($providerName.'の勘定科目・税区分の設定が不足しています。');
        }

        return DB::connection('furimadeck')->transaction(function () use ($user, $provider, $providerName, $from, $toExclusive): AccountingSyncBatch {
            // Serialize batch starts per user so double-clicks cannot enqueue the same entries twice.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // Do not lock the connection before entry/sync rows; send completion updates it last.
            $connection = $user->accountingConnections()->where('provider', $provider)->where('status', 'connected')->first();
            if ($connection === null) {
                throw new RuntimeException($providerName.'との接続を確認できません。再連携してから再試行してください。');
            }
            $missingConfiguration = $provider === 'freee'
                ? app(FurimaDeckFreeeAccountingSyncService::class)->missingConfiguration($connection)
                : app(FurimaDeckMoneyForwardAccountingSyncService::class)->missingConfiguration($connection);
            if ($missingConfiguration !== []) {
                throw new RuntimeException($providerName.'の勘定科目・税区分の設定が不足しています。');
            }
            $activeBatchExists = $user->accountingSyncBatches()
                ->where('provider', $provider)
                ->whereIn('status', ['queued', 'running'])
                ->exists();
            if ($activeBatchExists) {
                throw new RuntimeException($providerName.'への一括送信がすでに処理中です。');
            }

            $candidates = $user->accountingEntries()->eligibleForAccounting()
                ->whereIn('entry_type', [AccountingEntry::TYPE_SALE, AccountingEntry::TYPE_PURCHASE])
                ->where('source_status', 'confirmed')
                ->whereNotNull('confirmed_at')
                ->where('transaction_date', '>=', $from->toDateString())
                ->where('transaction_date', '<', $toExclusive->toDateString())
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->with('providerSyncs')
                ->get();

            $entries = collect();
            foreach ($candidates as $candidate) {
                if (! in_array($candidate->providerSyncStatus($provider), ['not_synced', 'failed'], true)) {
                    continue;
                }

                if ($provider === 'money_forward'
                    && app(FurimaDeckMoneyForwardAccountingSyncService::class)
                        ->missingConfigurationForDate($connection, $candidate->transaction_date?->toDateString() ?? '') !== []) {
                    continue;
                }

                if ($candidate->entry_type === AccountingEntry::TYPE_PURCHASE) {
                    $related = $user->products()->whereKey($candidate->product_id)->lockForUpdate()->firstOrFail();
                } else {
                    $related = $user->sales()->whereKey($candidate->sale_id)->lockForUpdate()->firstOrFail();
                }

                $entry = $user->accountingEntries()->eligibleForAccounting()
                    ->whereKey($candidate->id)
                    ->whereIn('entry_type', [AccountingEntry::TYPE_SALE, AccountingEntry::TYPE_PURCHASE])
                    ->where('source_status', 'confirmed')
                    ->whereNotNull('confirmed_at')
                    ->where('transaction_date', '>=', $from->toDateString())
                    ->where('transaction_date', '<', $toExclusive->toDateString())
                    ->lockForUpdate()
                    ->first();
                if ($entry === null || $entry->entry_type !== $candidate->entry_type) {
                    continue;
                }

                $relationId = $entry->entry_type === AccountingEntry::TYPE_PURCHASE ? $entry->product_id : $entry->sale_id;
                if ((int) $relationId !== (int) $related->getKey()) {
                    throw new RuntimeException('一括送信の対象データが処理中に変更されました。画面を更新してから再試行してください。');
                }

                $providerSync = $entry->providerSyncs()->where('provider', $provider)->lockForUpdate()->first();
                if ($providerSync !== null && ! in_array($providerSync->status, ['not_synced', 'failed'], true)) {
                    continue;
                }

                $entry->setRelation('providerSyncs', $providerSync === null ? collect() : collect([$providerSync]));
                if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
                    $entry->setRelation('product', $related);
                } else {
                    if (! in_array($related->status, Sale::VALID_SOLD_STATUSES, true)) {
                        throw new RuntimeException('販売が取消・返品済みのため、一括送信を開始できません。');
                    }
                    $entry->setRelation('sale', $related->loadMissing('marketplace'));
                }
                $entries->push($entry);
            }

            if ($entries->isEmpty()) {
                throw new RuntimeException('対象期間にConfirm済みの未送信データがありません。');
            }

            $purchaseIssues = app(FurimaDeckAccountingEntryService::class)->providerPurchaseReconciliationIssues($entries, $provider);
            $entries = $entries->reject(fn ($entry): bool => isset($purchaseIssues[$entry->id]));
            if ($entries->isEmpty()) {
                throw new RuntimeException('仕入・期首在庫の照合済みで、一括送信できるConfirm済みの未送信データはありません。未照合明細は保留中です。');
            }
            $entries = $entries->reject(fn (AccountingEntry $entry): bool => ! $this->paymentSourceReadiness->isReady($entry, $provider, $connection));
            if ($entries->isEmpty()) {
                throw new RuntimeException('選択した会計サービスの支払元が未設定または無効な仕入明細しかありません。一括送信を開始していません。設定画面で支払元候補を追加し、各商品で支払元を選択してください。');
            }

            foreach ($entries as $entry) {
                app(FurimaDeckAccountingEntryService::class)->assertCurrent($entry);
                app(FurimaDeckAccountingEntryService::class)->assertProviderPurchaseReconciled($entry, $provider);
            }

            $batch = $user->accountingSyncBatches()->create([
                'provider' => $provider,
                'period_from' => $from->toDateString(),
                'period_to' => $toExclusive->subDay()->toDateString(),
                'status' => 'queued',
                'total_count' => $entries->count(),
            ]);

            foreach ($entries as $entry) {
                $providerSync = $entry->providerSyncs->firstWhere('provider', $provider);
                if ($providerSync === null) {
                    $providerSync = $entry->providerSyncs()->create([
                        'provider' => $provider,
                        'status' => 'queued',
                    ]);
                    $entry->setRelation('providerSyncs', $entry->providerSyncs->push($providerSync));
                } else {
                    $providerSync->update(['status' => 'queued', 'last_error' => null]);
                }
                $item = $batch->items()->create([
                    'accounting_entry_id' => $entry->id,
                    'management_id' => $entry->management_id,
                    'title' => $entry->title,
                    'status' => 'queued',
                ]);
                $entry->update(['sync_status' => 'queued']);
                $job = $provider === 'freee'
                    ? new SendFreeeAccountingEntryJob($batch->id, $item->id)
                    : new SendMoneyForwardAccountingEntryJob($batch->id, $item->id);
                dispatch($job)
                    ->onConnection(self::QUEUE_CONNECTION)
                    ->onQueue('default')
                    ->afterCommit();
            }

            return $batch;
        });
    }
}
