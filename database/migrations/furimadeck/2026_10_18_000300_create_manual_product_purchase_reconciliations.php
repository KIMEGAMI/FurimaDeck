<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->create('manual_product_purchase_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('product_id');
            $table->string('provider', 32);
            $table->char('product_snapshot_hash', 64);
            $table->json('evidence_history');
            $table->foreignId('reviewed_by_user_id');
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->foreign('user_id', 'mpr_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('product_id', 'mpr_product_fk')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('reviewed_by_user_id', 'mpr_reviewer_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'product_id', 'provider'], 'manual_purchase_reconcile_product_provider_unique');
        });

        Schema::connection('furimadeck')->create('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('product_id');
            $table->string('provider', 32);
            $table->char('external_transaction_id_hash', 64);
            $table->timestamp('created_at');
            $table->foreign('user_id', 'mprref_user_fk')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('product_id', 'mprref_product_fk')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(
                ['user_id', 'provider', 'external_transaction_id_hash'],
                'manual_purchase_external_ref_unique'
            );
        });
    }

    public function down(): void
    {
        $connection = DB::connection('furimadeck');
        foreach (['manual_product_purchase_reconciliation_refs', 'manual_product_purchase_reconciliations'] as $table) {
            if (Schema::connection('furimadeck')->hasTable($table) && $connection->table($table)->exists()) {
                throw new RuntimeException('Manual purchase reconciliation evidence exists and must be preserved.');
            }
        }

        Schema::connection('furimadeck')->dropIfExists('manual_product_purchase_reconciliation_refs');
        Schema::connection('furimadeck')->dropIfExists('manual_product_purchase_reconciliations');
    }
};
