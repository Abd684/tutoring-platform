<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_asset_id')->unique()->constrained('content_assets')->cascadeOnDelete();
            $table->string('hls_manifest_key');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('key_reference')->nullable();
            $table->boolean('watermark_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_assets');
    }
};
