<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->unsignedInteger('listing_quantity')->default(1)->after('listing_price');
            $table->decimal('expected_fee_rate', 5, 2)->default(0)->after('listing_quantity');
            $table->integer('expected_profit')->nullable()->after('expected_fee_rate');
            $table->decimal('expected_margin', 5, 2)->nullable()->after('expected_profit');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn([
                'listing_quantity',
                'expected_fee_rate',
                'expected_profit',
                'expected_margin',
            ]);
        });
    }
};
