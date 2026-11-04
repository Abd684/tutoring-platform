<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('content_assets', function (Blueprint $table) {
            $table->string('processing_status', 20)->default('pending')->index();
            $table->unsignedInteger('total_pages')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_error_code', 80)->nullable();
            $table->unsignedSmallInteger('processing_version')->default(1);
        });
        Schema::create('pdf_viewing_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_reference')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('content_assets')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('device_identifier', 160)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('pdf_viewing_sessions');
        Schema::table('content_assets', function (Blueprint $table) {
            $table->dropColumn(['processing_status','total_pages','processed_at','processing_error_code','processing_version']);
        });
    }
};
