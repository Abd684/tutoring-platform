<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_assets', function (Blueprint $table) {
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('next_processing_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_assets', fn (Blueprint $table) => $table->dropColumn([
            'processing_attempts', 'processing_started_at', 'next_processing_at',
        ]));
    }
};
