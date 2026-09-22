<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')->where('quantity_available', '>', 0)->update(['inventory_status' => 'in_stock']);
        DB::table('products')->where('quantity_available', '<=', 0)->update(['inventory_status' => 'out_of_stock']);
    }

    public function down(): void
    {
        // The previous values cannot be restored safely because quantity_available is the source of truth.
    }
};
