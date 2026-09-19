<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_asset_id')->unique()->constrained('content_assets')->cascadeOnDelete();
            $table->unsignedInteger('page_count')->nullable();
            $table->boolean('watermark_enabled')->default(true);
            $table->string('status')->default('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_assets');
    }
};
