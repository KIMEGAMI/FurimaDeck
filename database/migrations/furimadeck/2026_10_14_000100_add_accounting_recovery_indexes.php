<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('accounting_entry_syncs', function (Blueprint $table): void {
            $table->index(['provider', 'status', 'attempted_at'], 'accounting_entry_syncs_stale_recovery_index');
        });

        Schema::connection('furimadeck')->table('accounting_sync_batch_items', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'accounting_batch_items_stale_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::connection('furimadeck')->table('accounting_sync_batch_items', function (Blueprint $table): void {
            $table->dropIndex('accounting_batch_items_stale_recovery_index');
        });

        Schema::connection('furimadeck')->table('accounting_entry_syncs', function (Blueprint $table): void {
            $table->dropIndex('accounting_entry_syncs_stale_recovery_index');
        });
    }
};
