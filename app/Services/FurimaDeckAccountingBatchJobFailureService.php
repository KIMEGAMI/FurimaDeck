<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\AccountingSyncBatch;
use App\Models\AccountingSyncBatchItem;
use Illuminate\Support\Facades\DB;

class FurimaDeckAccountingBatchJobFailureService
{
    public function __construct(private FurimaDeckAccountingBatchCompletionService $completion) {}

    public function fail(int $batchId, int $itemId, string $provider): void
    {
        DB::connection('furimadeck')->transaction(function () use ($batchId, $itemId, $provider): void {
            $batch = AccountingSyncBatch::query()->whereKey($batchId)->lockForUpdate()->first();
            $item = AccountingSyncBatchItem::query()->whereKey($itemId)->lockForUpdate()->first();
            if ($batch === null || $item === null || $batch->provider !== $provider
                || (int) $item->batch_id !== (int) $batch->id
                || in_array($item->status, ['succeeded', 'failed', 'uncertain'], true)) {
                return;
            }

            $entry = AccountingEntry::query()->whereKey($item->accounting_entry_id)->lockForUpdate()->first();
            $sync = $entry?->providerSyncs()->where('provider', $provider)->lockForUpdate()->first();
            $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
            $status = 'uncertain';
            $diagnostic = 'キュー処理が中断しました。送信結果を確認できないため、'.$providerName.'側の仕訳帳を確認してください。';

            if ($sync?->status === 'synced' && filled($sync->external_transaction_id)) {
                $status = 'succeeded';
                $diagnostic = null;
            } elseif ($sync !== null && in_array($sync->status, ['queued', 'not_synced'], true)) {
                $sync->update(['status' => 'failed', 'last_error' => 'queue_interrupted_before_send']);
                $status = 'failed';
                $diagnostic = '送信前にキュー処理が停止しました。会計サービスへの登録は行われていません。安全に再送できます。';
            } elseif ($sync?->status === 'failed') {
                $status = 'failed';
                $diagnostic = '会計サービスへの送信は失敗しました。診断を確認し、設定を修正してから再送してください。';
            } elseif ($sync?->status === 'sending') {
                $sync->update(['status' => 'sync_unknown', 'last_error' => 'worker_interrupted']);
            }

            $entry?->refreshLegacySyncStatus();
            $this->completion->finishLocked($batch, $item, $status, $diagnostic);
        });
    }
}
