<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_pairings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('code_challenge', 43);
            $table->string('redirect_uri', 255);
            $table->string('device_name', 100);
            $table->foreignId('approved_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('approval_code_hash', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('exchanged_at')->nullable();
            $table->timestamps();

            $table->index(['approved_user_id', 'expires_at']);
        });

        Schema::create('extension_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void {}
};
