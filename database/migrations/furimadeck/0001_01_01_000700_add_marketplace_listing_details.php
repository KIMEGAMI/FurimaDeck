<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('shipping_payer')->nullable()->after('shipping_method');
            $table->string('sender_region')->nullable()->after('shipping_payer');
            $table->unsignedTinyInteger('dispatch_days')->nullable()->after('sender_region');
            $table->string('shipping_size')->nullable()->after('dispatch_days');
            $table->unsignedInteger('shipping_weight_grams')->nullable()->after('shipping_size');
            $table->string('sale_format')->nullable()->after('shipping_weight_grams');
            $table->unsignedSmallInteger('listing_period_days')->nullable()->after('sale_format');
            $table->string('return_policy')->nullable()->after('listing_period_days');
            $table->string('purchase_application')->nullable()->after('return_policy');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn([
                'shipping_payer',
                'sender_region',
                'dispatch_days',
                'shipping_size',
                'shipping_weight_grams',
                'sale_format',
                'listing_period_days',
                'return_policy',
                'purchase_application',
            ]);
        });
    }
};
