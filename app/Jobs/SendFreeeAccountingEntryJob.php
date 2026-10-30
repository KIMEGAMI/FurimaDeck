<?php

namespace App\Jobs;

use App\Models\AccountingEntry;
use App\Models\AccountingSyncBatchItem;
use App\Services\FurimaDeckAccountingApiReleaseGate;
use App\Services\FurimaDeckAccountingBatchCompletionService;
use App\Services\FurimaDeckAccountingBatchItemClaimService;
use App\Services\FurimaDeckAccountingBatchJobFailureService;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendFreeeAccountingEntryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public const TIMEOUT_SECONDS = 60;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(public int $batchId, public int $itemId) {}

    public function handle(FurimaDeckFreeeAccountingSyncService $sync): void
    {
        $item = AccountingSyncBatchItem::query()->with('batch')->findOrFail($this->itemId);
        if ((int) $item->batch_id !== $this->batchId || $item->status !== 'queued') {
            return;
        }

        if (! app(FurimaDeckAccountingApiReleaseGate::class)->enabled()) {
            if (! app(FurimaDeckAccountingBatchItemClaimService::class)->claim($this->batchId, $item->id, 'freee')) {
                return;
            }

            $entry = AccountingEntry::query()->find($item->accounting_entry_id);
            $providerSync = $entry?->providerSync('freee');
            if ($providerSync?->status === 'queued') {
                $providerSync->update(['status' => 'failed', 'last_error' => 'provider_api_disabled']);
            }
            $entry?->refreshLegacySyncStatus();
            $this->finish('failed', 'FurimaDeck 1.0ではAPI送信を停止しているため、外部送信は行いません。会計CSVをご利用ください。');

            return;
        }

        if (! app(FurimaDeckAccountingBatchItemClaimService::class)->claim($this->batchId, $item->id, 'freee')) {
            return;
        }

        $item->refresh();

        try {
            $batch = $item->batch;
            $entry = AccountingEntry::query()->findOrFail($item->accounting_entry_id);
            $sync->send($batch->user, $entry, $item->id);
            $this->finish('succeeded', null);
        } catch (Throwable $exception) {
            $entry = AccountingEntry::query()->find($item->accounting_entry_id);
            $providerSync = $entry?->providerSync('freee');
            $alreadySucceeded = $providerSync?->status === 'synced' && filled($providerSync->external_transaction_id);
            $isUncertain = in_array($providerSync?->status, ['sync_unknown', 'legacy_unknown', 'sending'], true);
            if ($providerSync?->status === 'sending') {
                $providerSync->update(['status' => 'sync_unknown', 'last_error' => 'worker_interrupted']);
                $isUncertain = true;
            } elseif ($providerSync?->status === 'queued') {
                $providerSync->update(['status' => 'failed', 'last_error' => 'preflight_failed']);
            }
            if ($entry !== null) {
                $entry->refreshLegacySyncStatus();
            }
            $diagnostic = $exception instanceof \RuntimeException
                ? mb_substr($exception->getMessage(), 0, 500)
                : '送信処理を完了できませんでした。freee側を確認してください。';
            $this->finish($alreadySucceeded ? 'succeeded' : ($isUncertain ? 'uncertain' : 'failed'), $alreadySucceeded ? null : $diagnostic);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(FurimaDeckAccountingBatchJobFailureService::class)->fail($this->batchId, $this->itemId, 'freee');
    }

    private function finish(string $status, ?string $diagnostic): void
    {
        app(FurimaDeckAccountingBatchCompletionService::class)->finish($this->batchId, $this->itemId, $status, $diagnostic);
    }
}
