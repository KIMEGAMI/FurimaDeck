<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('format');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->string('status')->default('preview');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('import_row_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('row_json');
            $table->json('errors_json')->nullable();
            $table->boolean('is_valid');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['import_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_results');
        Schema::dropIfExists('import_batches');
    }
};
