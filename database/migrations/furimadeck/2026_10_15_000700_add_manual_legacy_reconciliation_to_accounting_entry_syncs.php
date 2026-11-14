<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('accounting_entry_syncs', function (Blueprint $table): void {
            $table->json('manual_reconciliation_evidence')->nullable();
            $table->unsignedBigInteger('manual_reconciled_by_user_id')->nullable();
            $table->timestamp('manual_reconciled_at')->nullable();
        });
    }

    public function down(): void
    {
        // Preserve audit evidence if this migration is rolled back.
    }
};
