<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->create('accounting_entry_syncs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accounting_entry_id')->constrained('accounting_entries')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('status', 32)->default('not_synced');
            $table->string('external_transaction_id')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['accounting_entry_id', 'provider'], 'accounting_entry_syncs_entry_provider_unique');
            $table->index(['provider', 'status'], 'accounting_entry_syncs_provider_status_index');
        });

        $connection = DB::connection('furimadeck');
        $hasBatchItems = Schema::connection('furimadeck')->hasTable('accounting_sync_batch_items')
            && Schema::connection('furimadeck')->hasTable('accounting_sync_batches');
        $connection->table('accounting_entries')->orderBy('id')->chunkById(200, function ($entries) use ($connection, $hasBatchItems): void {
            $batchItems = collect();
            if ($hasBatchItems) {
                $batchItems = $connection->table('accounting_sync_batch_items as items')
                    ->join('accounting_sync_batches as batches', 'batches.id', '=', 'items.batch_id')
                    ->whereIn('items.accounting_entry_id', $entries->pluck('id'))
                    ->where('batches.provider', 'money_forward')
                    ->orderBy('items.id')
                    ->get(['items.accounting_entry_id', 'items.status'])
                    ->groupBy('accounting_entry_id')
                    ->map(fn ($items) => $items->last());
            }

            $syncRows = [];
            foreach ($entries as $entry) {
                $freeeStatus = 'not_synced';
                $moneyForwardStatus = 'not_synced';
                $moneyForwardBatchItem = $batchItems->get($entry->id);

                if ($entry->sync_status === 'queued' && in_array($moneyForwardBatchItem?->status, ['queued', 'running'], true)) {
                    $moneyForwardStatus = 'queued';
                } elseif ($entry->sync_status === 'sync_unknown' && $moneyForwardBatchItem?->status === 'uncertain') {
                    $moneyForwardStatus = 'sync_unknown';
                } elseif ($entry->sync_status === 'synced' && $moneyForwardBatchItem?->status === 'succeeded') {
                    $moneyForwardStatus = 'synced';
                }

                if (in_array($entry->sync_status, ['synced', 'sync_unknown', 'queued'], true)) {
                    if ($entry->sync_status === 'synced' && $moneyForwardStatus === 'synced') {
                        $freeeStatus = 'legacy_unknown';
                    } elseif ($entry->sync_status === 'sync_unknown' && $moneyForwardStatus === 'sync_unknown') {
                        $freeeStatus = 'not_synced';
                    } elseif ($entry->sync_status === 'queued' && $moneyForwardStatus === 'queued') {
                        $freeeStatus = 'not_synced';
                    } else {
                        $freeeStatus = 'legacy_unknown';
                        $moneyForwardStatus = 'legacy_unknown';
                    }
                }

                foreach (['freee' => $freeeStatus, 'money_forward' => $moneyForwardStatus] as $provider => $status) {
                    $syncRows[] = [
                        'accounting_entry_id' => $entry->id,
                        'provider' => $provider,
                        'status' => $status,
                        'external_transaction_id' => $provider === 'money_forward' && $status === 'synced'
                            ? $entry->external_transaction_id
                            : null,
                        'attempt_count' => 0,
                        'synced_at' => $status === 'synced' ? $entry->synced_at : null,
                        'created_at' => $entry->created_at,
                        'updated_at' => $entry->updated_at,
                    ];
                }
            }
            $connection->table('accounting_entry_syncs')->insert($syncRows);
        });
    }

    public function down(): void
    {
        // Provider-specific financial history is intentionally retained on rollback.
    }
};
