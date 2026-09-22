<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('management_id');
            $table->string('title');
            $table->string('marketplace')->nullable();
            $table->unsignedInteger('sale_amount')->default(0);
            $table->unsignedInteger('purchase_cost')->default(0);
            $table->unsignedInteger('selling_fee')->default(0);
            $table->unsignedInteger('shipping_cost')->default(0);
            $table->unsignedInteger('other_cost')->default(0);
            $table->integer('furimadeck_actual_profit')->default(0);
            $table->text('note')->nullable();
            $table->string('source_status')->default('ready');
            $table->string('sync_status')->default('not_synced');
            $table->string('external_transaction_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sale_id']);
            $table->index(['user_id', 'transaction_date']);
            $table->index(['user_id', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_entries');
    }
};
