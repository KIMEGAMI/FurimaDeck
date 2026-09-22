<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_entries', function (Blueprint $table): void {
            $table->unique(['user_id', 'external_transaction_id'], 'accounting_entries_user_external_transaction_unique');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_entries', function (Blueprint $table): void {
            $table->dropUnique('accounting_entries_user_external_transaction_unique');
        });
    }
};
