<?php

namespace App\Services;

use App\Models\AccountingSyncBatch;
use App\Models\AccountingSyncBatchItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FurimaDeckAccountingBatchCompletionService
{
    private const TERMINAL_STATUSES = ['succeeded', 'failed', 'uncertain'];

    public function finish(int $batchId, int $itemId, string $status, ?string $diagnostic): bool
    {
        if (! in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid accounting batch item completion status.');
        }

        return DB::connection('furimadeck')->transaction(function () use ($batchId, $itemId, $status, $diagnostic): bool {
            $batch = AccountingSyncBatch::query()->whereKey($batchId)->lockForUpdate()->first();
            $item = AccountingSyncBatchItem::query()->whereKey($itemId)->lockForUpdate()->first();

            return $batch !== null && $item !== null
                ? $this->finishLocked($batch, $item, $status, $diagnostic)
                : false;
        });
    }

    public function finishLocked(AccountingSyncBatch $batch, AccountingSyncBatchItem $item, string $status, ?string $diagnostic): bool
    {
        if (! in_array($status, self::TERMINAL_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid accounting batch item completion status.');
        }
        if ((int) $item->batch_id !== (int) $batch->id || in_array($item->status, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        $item->update([
            'status' => $status,
            'diagnostic' => $diagnostic === null ? null : mb_substr($diagnostic, 0, 500),
            'finished_at' => now(),
        ]);

        $batch->processed_count++;
        if ($status === 'succeeded') {
            $batch->succeeded_count++;
        } else {
            $batch->failed_count++;
        }
        if ($batch->processed_count >= $batch->total_count) {
            $batch->status = $batch->failed_count === 0 ? 'completed' : 'completed_with_errors';
            $batch->finished_at = now();
        }
        $batch->save();

        return true;
    }
}
