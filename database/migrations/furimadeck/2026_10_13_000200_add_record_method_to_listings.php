<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->string('record_method', 32)->nullable()->after('external_listing_id');
        });
    }

    public function down(): void
    {
        // Keep recorded listing provenance if a rollback is requested.
    }
};
