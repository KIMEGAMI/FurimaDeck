<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_response_caches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->string('purpose');
            $table->string('input_hash', 64);
            $table->json('response_json');
            $table->decimal('confidence', 5, 4)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'purpose', 'input_hash']);
        });

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->string('purpose');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);
            $table->decimal('estimated_cost_jpy', 12, 4)->default(0);
            $table->string('input_hash', 64);
            $table->boolean('success');
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('ai_response_caches');
    }
};
