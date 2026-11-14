<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->create('accounting_sync_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status')->default('queued');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'provider', 'status']);
        });

        Schema::connection('furimadeck')->create('accounting_sync_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('accounting_sync_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('accounting_entry_id');
            $table->string('management_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->default('queued');
            $table->string('diagnostic', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'accounting_entry_id']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('furimadeck')->dropIfExists('accounting_sync_batch_items');
        Schema::connection('furimadeck')->dropIfExists('accounting_sync_batches');
    }
};
