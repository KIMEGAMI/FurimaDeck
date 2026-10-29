<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('furimadeck')->hasColumn('accounting_entry_syncs', 'provider_account_scope_hash')) {
            return;
        }

        Schema::connection('furimadeck')->table('accounting_entry_syncs', function (Blueprint $table): void {
            $table->char('provider_account_scope_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Keep provider scope evidence on rollback.
    }
};
