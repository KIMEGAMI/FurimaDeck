<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->unsignedBigInteger('transaction_total')->nullable();
            $table->unsignedBigInteger('allocated_amount')->nullable();
        });

        Schema::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'provider', 'external_transaction_id_hash', 'product_id'],
                'manual_purchase_external_ref_product_unique'
            );
        });

        Schema::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->dropUnique('manual_purchase_external_ref_unique');
        });
    }

    public function down(): void
    {
        $connection = DB::connection('furimadeck');
        if (Schema::connection('furimadeck')->hasTable('manual_product_purchase_reconciliation_refs')
            && $connection->table('manual_product_purchase_reconciliation_refs')->exists()) {
            throw new RuntimeException('Manual purchase allocation evidence exists and must be preserved.');
        }

        Schema::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'provider', 'external_transaction_id_hash'],
                'manual_purchase_external_ref_unique'
            );
        });

        Schema::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->dropUnique('manual_purchase_external_ref_product_unique');
        });

        Schema::connection('furimadeck')->table('manual_product_purchase_reconciliation_refs', function (Blueprint $table): void {
            $table->dropColumn(['transaction_total', 'allocated_amount']);
        });
    }
};
