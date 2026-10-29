<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_purchase_cap_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->char('counterparty_fingerprint', 64);
            $table->text('counterparty_name');
            $table->unsignedBigInteger('external_qualifying_purchase_amount')->default(0);
            $table->date('reviewed_through');
            $table->boolean('furimadeck_history_complete')->default(false);
            $table->boolean('external_history_complete')->default(false);
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->unique(
                ['user_id', 'period_from', 'period_to', 'counterparty_fingerprint'],
                'acct_purchase_cap_review_unique'
            );
            $table->index(['user_id', 'period_from', 'period_to'], 'acct_purchase_cap_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_purchase_cap_reviews');
    }
};
