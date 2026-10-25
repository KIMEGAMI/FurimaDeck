<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extension_message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('purpose', 32);
            $table->text('body');
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'name']);
        });
    }

    public function down(): void
    {
        // Keep user-created template data if a rollback is requested.
    }
};
