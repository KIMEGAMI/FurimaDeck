<?php

namespace App\Services;

use App\Models\AccountingSyncBatch;
use App\Models\AccountingSyncBatchItem;
use Illuminate\Support\Facades\DB;

class FurimaDeckAccountingBatchItemClaimService
{
    public function claim(int $batchId, int $itemId, string $provider): bool
    {
        return DB::connection('furimadeck')->transaction(function () use ($batchId, $itemId, $provider): bool {
            $batch = AccountingSyncBatch::query()->whereKey($batchId)->lockForUpdate()->first();
            $item = AccountingSyncBatchItem::query()->whereKey($itemId)->lockForUpdate()->first();

            if ($batch === null || $item === null
                || $batch->provider !== $provider
                || (int) $item->batch_id !== (int) $batch->id
                || $item->status !== 'queued') {
                return false;
            }

            $item->update(['status' => 'running']);
            if ($batch->status === 'queued') {
                $batch->update(['status' => 'running', 'started_at' => now()]);
            }

            return true;
        });
    }
}
