<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHUNK_SIZE = 200;

    public function up(): void
    {
        $connection = DB::connection('furimadeck');
        $connection->table('accounting_entries')
            ->where('sync_status', 'failed')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($entries) use ($connection): void {
                foreach ($entries as $entry) {
                    $connection->table('accounting_entry_syncs')
                        ->where('accounting_entry_id', $entry->id)
                        ->where('status', 'not_synced')
                        ->where('attempt_count', 0)
                        ->where('created_at', $entry->created_at)
                        ->update(['status' => 'legacy_unknown']);
                }
            });
    }

    public function down(): void
    {
        // Keep ambiguous legacy records blocked from automatic resend on rollback.
    }
};
