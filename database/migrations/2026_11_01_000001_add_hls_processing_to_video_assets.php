<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_assets', function (Blueprint $table) {
            $table->string('processing_status', 20)->default('pending')->index();
            $table->string('processing_error', 100)->nullable();
            $table->string('hls_directory', 255)->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->json('renditions')->nullable();
            $table->dateTime('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('video_assets', function (Blueprint $table) {
            $table->dropIndex(['processing_status']);
            $table->dropColumn(['processing_status', 'processing_error', 'hls_directory', 'source_fingerprint', 'renditions', 'processed_at']);
        });
    }
};
