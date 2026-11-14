<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('manual_product_purchase_reconciliations', function (Blueprint $table): void {
            $table->longText('evidence_history')->change();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('furimadeck');
        if (! $schema->hasTable('manual_product_purchase_reconciliations')) {
            return;
        }

        if (DB::connection('furimadeck')->table('manual_product_purchase_reconciliations')->exists()) {
            throw new RuntimeException('Encrypted manual purchase reconciliation evidence must be preserved.');
        }

        $schema->table('manual_product_purchase_reconciliations', function (Blueprint $table): void {
            $table->json('evidence_history')->change();
        });
    }
};
