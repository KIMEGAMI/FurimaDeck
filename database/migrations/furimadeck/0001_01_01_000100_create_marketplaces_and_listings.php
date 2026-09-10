<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplaces', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('base_url');
            $table->string('listing_url')->nullable();
            $table->string('fee_type')->default('percentage');
            $table->decimal('default_fee_rate', 5, 2)->default(0);
            $table->json('rule_config_json')->nullable();
            $table->string('rule_source_url')->nullable();
            $table->timestamp('rule_last_reviewed_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        Schema::create('listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_id')->constrained()->restrictOnDelete();
            $table->string('listing_title');
            $table->text('listing_description')->nullable();
            $table->unsignedInteger('listing_price')->nullable();
            $table->string('marketplace_category')->nullable();
            $table->string('marketplace_condition')->nullable();
            $table->string('shipping_method')->nullable();
            $table->unsignedInteger('shipping_fee')->default(0);
            $table->string('external_listing_url')->nullable();
            $table->string('external_listing_id')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('listed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['product_id', 'status']);
            $table->index(['marketplace_id', 'external_listing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
        Schema::dropIfExists('marketplaces');
    }
};
