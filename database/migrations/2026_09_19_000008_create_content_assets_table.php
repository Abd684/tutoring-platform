<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->string('disk');
            $table->string('storage_key');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum')->nullable();
            $table->string('encryption_type')->nullable();
            $table->enum('encryption_status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->enum('status', ['active', 'archived'])->default('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_assets');
    }
};
