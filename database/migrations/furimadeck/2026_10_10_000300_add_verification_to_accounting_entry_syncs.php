<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('accounting_entry_syncs', function (Blueprint $table): void {
            $table->json('request_payload')->nullable();
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        // Keep financial evidence on rollback.
    }
};
