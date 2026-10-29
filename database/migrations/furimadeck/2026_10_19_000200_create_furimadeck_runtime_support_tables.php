<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('furimadeck');

        if (! $schema->hasTable('system_settings')) {
            $schema->create('system_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('notices')) {
            $schema->create('notices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title');
                $table->text('body');
                $table->timestamp('published_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('subscription_cancellation_feedback')) {
            $schema->create('subscription_cancellation_feedback', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('reason', 60);
                $table->text('detail')->nullable();
                $table->string('subscription_status', 60)->nullable();
                $table->timestamps();
                $table->index(['reason', 'created_at']);
            });
        }

        if (! $schema->hasTable('cache')) {
            $schema->create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->bigInteger('expiration')->index();
            });
        }

        if (! $schema->hasTable('cache_locks')) {
            $schema->create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->bigInteger('expiration')->index();
            });
        }
    }

    public function down(): void
    {
        // Keep runtime support tables and their data on rollback.
    }
};
