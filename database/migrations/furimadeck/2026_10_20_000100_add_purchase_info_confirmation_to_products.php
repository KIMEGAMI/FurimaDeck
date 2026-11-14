<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('furimadeck')->table('products', function (Blueprint $table): void {
            $table->boolean('purchase_info_confirmed')->default(true)->after('purchase_date');
        });
    }

    public function down(): void
    {
        Schema::connection('furimadeck')->table('products', function (Blueprint $table): void {
            $table->dropColumn('purchase_info_confirmed');
        });
    }
};
