<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('products', function (Blueprint $table): void {
            $table->text('purchase_special_case_data')->nullable()->after('purchase_evidence');
        });

        Schema::connection('furimadeck')->table('accounting_entries', function (Blueprint $table): void {
            $table->text('purchase_special_case_data')->nullable()->after('expense_breakdown');
        });
    }

    public function down(): void
    {
        Schema::connection('furimadeck')->table('accounting_entries', function (Blueprint $table): void {
            $table->dropColumn('purchase_special_case_data');
        });

        Schema::connection('furimadeck')->table('products', function (Blueprint $table): void {
            $table->dropColumn('purchase_special_case_data');
        });
    }
};
