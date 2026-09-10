<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_item_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_item_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->unique(['auction_item_id', 'position']);
            $table->index('path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_item_images');
    }
};
