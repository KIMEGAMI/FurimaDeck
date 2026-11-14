<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('products', function (Blueprint $table): void {
            $table->boolean('purchase_accounting_enabled')->default(false)->after('purchase_date');
            $table->string('purchase_document_type', 24)->nullable()->after('purchase_accounting_enabled');
            $table->string('purchase_tax_category', 32)->nullable()->after('purchase_document_type');
            $table->string('purchase_invoice_issuer', 24)->nullable()->after('purchase_tax_category');
            $table->timestamp('purchase_document_reviewed_at')->nullable()->after('purchase_invoice_issuer');
        });
    }

    public function down(): void
    {
        Schema::connection('furimadeck')->table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'purchase_accounting_enabled',
                'purchase_document_type',
                'purchase_tax_category',
                'purchase_invoice_issuer',
                'purchase_document_reviewed_at',
            ]);
        });
    }
};
