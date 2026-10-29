<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('sales', function (Blueprint $table): void {
            $table->boolean('cancelled_from_pending')->default(false);
        });
    }

    public function down(): void
    {
        // Keep cancellation provenance on rollback.
    }
};
