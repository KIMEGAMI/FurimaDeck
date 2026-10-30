<?php

namespace App\Services;

use App\Jobs\SendFreeeAccountingEntryJob;
use App\Jobs\SendMoneyForwardAccountingEntryJob;
use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\AccountingSyncBatch;
use App\Models\AccountingSyncBatchItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FurimaDeckAccountingStaleSendRecoveryService
{
    /** @var array<string, true>|null Null means the database queue could not be inspected safely. */
    private ?array $queuedBatchJobKeys = null;

    private bool $queueInspectionAvailable = false;

    public function __construct(private FurimaDeckAccountingBatchCompletionService $batchCompletion) {}

    /** @return array{uncertain:int,not_sent:int,succeeded:int,deferred:int} */
    public function recover(int $ageSeconds): array
    {
        $counts = ['uncertain' => 0, 'not_sent' => 0, 'succeeded' => 0, 'deferred' => 0];
        if (! app(FurimaDeckAccountingApiReleaseGate::class)->enabled()) {
            return $counts;
        }

        $cutoff = now()->subSeconds($ageSeconds);
        $this->queueInspectionAvailable = false;
        $this->queuedBatchJobKeys = [];
        $staleQueuedItems = AccountingSyncBatchItem::query()
            ->where('status', 'queued')
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('batch', fn ($query) => $query->whereIn('provider', ['freee', 'money_forward']))
            ->count();
        if ($staleQueuedItems > 0) {
            $this->queuedBatchJobKeys = $this->loadQueuedBatchJobKeys();
            if ($this->queuedBatchJobKeys === null) {
                $counts['deferred'] = $staleQueuedItems;
            }
        }

        AccountingSyncBatchItem::query()
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('batch', fn ($query) => $query->whereIn('provider', ['freee', 'money_forward']))
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($cutoff, &$counts): void {
                foreach ($items as $candidate) {
                    $outcome = $this->recoverBatchItem((int) $candidate->id, $cutoff);
                    if ($outcome !== null) {
                        $counts[$outcome]++;
                    }
                }
            });

        AccountingEntrySync::query()
            ->whereIn('provider', ['freee', 'money_forward'])
            ->where('status', 'sending')
            ->whereNotNull('attempted_at')
            ->where('attempted_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($syncs) use ($cutoff, &$counts): void {
                foreach ($syncs as $candidate) {
                    if ($this->recoverStandaloneSending((int) $candidate->id, $cutoff)) {
                        $counts['uncertain']++;
                    }
                }
            });

        return $counts;
    }

    private function recoverBatchItem(int $itemId, CarbonInterface $cutoff): ?string
    {
        return DB::connection('furimadeck')->transaction(function () use ($itemId, $cutoff): ?string {
            $batchId = AccountingSyncBatchItem::query()->whereKey($itemId)->value('batch_id');
            if ($batchId === null) {
                return null;
            }

            $batch = AccountingSyncBatch::query()->whereKey($batchId)->lockForUpdate()->first();
            $item = AccountingSyncBatchItem::query()->whereKey($itemId)->lockForUpdate()->first();
            if ($batch === null || $item === null || ! in_array($batch->provider, ['freee', 'money_forward'], true)
                || ! in_array($item->status, ['queued', 'running'], true)
                || $item->updated_at === null || $item->updated_at->gt($cutoff)) {
                return null;
            }

            $entry = AccountingEntry::query()->whereKey($item->accounting_entry_id)->lockForUpdate()->first();
            $provider = $batch->provider;
            $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
            $sync = $entry?->providerSyncs()->where('provider', $provider)->lockForUpdate()->first();
            $outcome = 'failed';
            $diagnostic = '一括送信の処理が停止しました。'.$providerName.'に登録されていないことを確認してから再送してください。';

            if ($entry === null || $sync === null) {
                $outcome = 'uncertain';
                $diagnostic = '送信状態の記録を確認できません。二重登録防止のため、'.$providerName.'側を確認してください。';
            } elseif ($item->status === 'queued' && $this->queuedJobMayStillRun($batchId, $itemId)) {
                return null;
            } elseif ($sync->status === 'sending') {
                if ($sync->attempted_at === null || $sync->attempted_at->gt($cutoff)) {
                    return null;
                }
                $sync->update(['status' => 'sync_unknown', 'last_error' => 'worker_interrupted']);
                $outcome = 'uncertain';
                $diagnostic = '送信結果を確認できません。二重登録防止のため、'.$providerName.'側を確認してください。';
            } elseif (in_array($sync->status, ['sync_unknown', 'legacy_unknown'], true)) {
                $outcome = 'uncertain';
                $diagnostic = '送信結果が不明です。二重登録防止のため、'.$providerName.'側を確認してください。';
            } elseif ($sync->status === 'synced' && filled($sync->external_transaction_id)) {
                $outcome = 'succeeded';
                $diagnostic = null;
            } elseif (in_array($sync->status, ['queued', 'not_synced'], true)) {
                $sync->update(['status' => 'failed', 'last_error' => 'queue_interrupted_before_send']);
            } elseif ($sync->status === 'failed') {
                $diagnostic = '会計サービスへの送信に失敗しました。表示された診断を確認してください。';
            } else {
                $outcome = 'uncertain';
                $diagnostic = '送信状態を判定できません。二重登録防止のため、'.$providerName.'側を確認してください。';
            }

            if ($entry !== null) {
                $entry->refreshLegacySyncStatus();
            }

            $this->batchCompletion->finishLocked($batch, $item, $outcome, $diagnostic);

            return match ($outcome) {
                'succeeded' => 'succeeded',
                'uncertain' => 'uncertain',
                default => 'not_sent',
            };
        });
    }

    private function recoverStandaloneSending(int $syncId, CarbonInterface $cutoff): bool
    {
        return DB::connection('furimadeck')->transaction(function () use ($syncId, $cutoff): bool {
            $candidate = AccountingEntrySync::query()->whereKey($syncId)->first();
            if ($candidate === null) {
                return false;
            }

            $entry = AccountingEntry::query()->whereKey($candidate->accounting_entry_id)->lockForUpdate()->first();
            $sync = $entry?->providerSyncs()->whereKey($candidate->id)->lockForUpdate()->first();
            if ($entry === null || $sync === null || $sync->status !== 'sending'
                || $sync->attempted_at === null || $sync->attempted_at->gt($cutoff)) {
                return false;
            }

            $sync->update(['status' => 'sync_unknown', 'last_error' => 'worker_interrupted']);
            $entry->refreshLegacySyncStatus();

            return true;
        });
    }

    private function queuedJobMayStillRun(int $batchId, int $itemId): bool
    {
        return ! $this->queueInspectionAvailable
            || isset($this->queuedBatchJobKeys[$batchId.':'.$itemId]);
    }

    /** @return array<string, true>|null */
    private function loadQueuedBatchJobKeys(): ?array
    {
        $queueConfig = (array) config('queue.connections.database', []);
        $connection = $queueConfig['connection'] ?? config('database.default');
        $table = $queueConfig['table'] ?? 'jobs';
        if (! is_string($connection) || ! is_string($table) || preg_match('/\A[a-zA-Z0-9_]+\z/', $table) !== 1) {
            return null;
        }

        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return null;
            }

            $rows = DB::connection($connection)->table($table)
                ->where('queue', 'default')
                ->where(function ($query): void {
                    $query->where('payload', 'like', '%SendFreeeAccountingEntryJob%')
                        ->orWhere('payload', 'like', '%SendMoneyForwardAccountingEntryJob%');
                })
                ->get(['payload']);
        } catch (\Throwable) {
            return null;
        }

        $keys = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            if (! is_array($payload)) {
                continue;
            }

            $class = $payload['displayName'] ?? null;
            if (! in_array($class, [
                SendFreeeAccountingEntryJob::class,
                SendMoneyForwardAccountingEntryJob::class,
            ], true)) {
                continue;
            }

            $command = data_get($payload, 'data.command');
            if (! is_string($command)
                || ! preg_match($this->serializedIntegerPropertyPattern('batchId'), $command, $batchMatch)
                || ! preg_match($this->serializedIntegerPropertyPattern('itemId'), $command, $itemMatch)) {
                return null;
            }

            $keys[$batchMatch[1].':'.$itemMatch[1]] = true;
        }

        $this->queueInspectionAvailable = true;

        return $keys;
    }

    private function serializedIntegerPropertyPattern(string $property): string
    {
        return '/s:'.strlen($property).':"'.preg_quote($property, '/').'";i:(\d+);/';
    }
}
