<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('accounting_entries', function (Blueprint $table): void {
            $table->string('entry_type', 16)->default('sale')->after('sale_id');
            $table->foreignId('product_id')->nullable()->after('sale_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->change();
            $table->unique(['user_id', 'entry_type', 'product_id'], 'accounting_entries_user_type_product_unique');
        });
    }

    public function down(): void
    {
        $connection = DB::connection('furimadeck');
        if ($connection->table('accounting_entries')->where('entry_type', 'purchase')->exists()) {
            throw new RuntimeException('仕入会計データが存在するため、このmigrationは安全のため戻せません。');
        }

        Schema::connection('furimadeck')->table('accounting_entries', function (Blueprint $table): void {
            $table->dropUnique('accounting_entries_user_type_product_unique');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('entry_type');
            $table->foreignId('sale_id')->nullable(false)->change();
        });
    }
};
