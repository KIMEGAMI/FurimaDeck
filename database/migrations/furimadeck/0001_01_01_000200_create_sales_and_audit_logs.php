<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketplace_id')->constrained()->restrictOnDelete();
            $table->string('internal_sku_snapshot');
            $table->string('product_name_snapshot');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('sold_price');
            $table->timestamp('sold_at');
            $table->string('status')->default('pending');
            $table->unsignedInteger('cost_basis')->default(0);
            $table->unsignedInteger('sales_fee')->default(0);
            $table->unsignedInteger('shipping_fee')->default(0);
            $table->unsignedInteger('purchase_shipping_cost')->default(0);
            $table->unsignedInteger('packing_cost')->default(0);
            $table->unsignedInteger('repair_cost')->default(0);
            $table->unsignedInteger('cleaning_cost')->default(0);
            $table->unsignedInteger('other_expense')->default(0);
            $table->integer('net_profit')->default(0);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_reason')->nullable();
            $table->unsignedInteger('refund_amount')->default(0);
            $table->unsignedInteger('return_shipping_fee')->default(0);
            $table->unsignedInteger('restocked_quantity')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status', 'sold_at']);
            $table->index(['product_id', 'status']);
            $table->index(['listing_id', 'status']);
            $table->index(['marketplace_id', 'status', 'sold_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('sales');
    }
};
