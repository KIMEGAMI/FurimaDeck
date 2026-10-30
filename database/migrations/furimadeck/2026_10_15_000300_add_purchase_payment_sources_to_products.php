<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('products', function (Blueprint $table): void {
            $table->json('purchase_payment_sources')->nullable()->after('purchase_invoice_issuer');
        });
    }

    public function down(): void
    {
        Schema::connection('furimadeck')->table('products', function (Blueprint $table): void {
            $table->dropColumn('purchase_payment_sources');
        });
    }
};
