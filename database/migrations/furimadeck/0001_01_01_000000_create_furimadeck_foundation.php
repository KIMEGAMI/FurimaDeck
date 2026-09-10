<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('google_id')->nullable()->unique();
            $table->string('subscription_plan')->default('inactive');
            $table->string('subscription_status')->nullable();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->timestamp('premium_started_at')->nullable();
            $table->timestamp('premium_ends_at')->nullable();
            $table->timestamp('trial_used_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('last_login_user_agent_hash', 64)->nullable();
            $table->timestamp('suspicious_login_detected_at')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('category_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('product_categories')->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->string('input_type');
            $table->boolean('is_required')->default(false);
            $table->json('options_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'key']);
            $table->index(['category_id', 'is_active', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('internal_sku');
            $table->string('product_name');
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('condition');
            $table->text('description_base')->nullable();
            $table->date('purchase_date')->nullable();
            $table->unsignedInteger('purchase_unit_cost')->default(0);
            $table->unsignedInteger('purchase_quantity')->default(1);
            $table->unsignedInteger('quantity_available')->default(0);
            $table->unsignedInteger('purchase_shipping_cost')->default(0);
            $table->unsignedInteger('other_purchase_expense')->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('jan_ean')->nullable();
            $table->string('isbn')->nullable();
            $table->string('manufacturer_model_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('storage_location')->nullable();
            $table->string('inventory_status')->default('draft');
            $table->text('memo')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'internal_sku']);
            $table->index(['user_id', 'inventory_status']);
            $table->index(['user_id', 'category_id']);
            $table->index(['user_id', 'supplier_id']);
        });

        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_attribute_id')->constrained('category_attributes')->restrictOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 16, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'category_attribute_id']);
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('original_path');
            $table->string('derived_path')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('products');
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
