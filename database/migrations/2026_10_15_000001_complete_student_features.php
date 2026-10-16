<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('avatar_path')->nullable());
        }
        if (! Schema::hasColumn('contents', 'learning_objectives')) {
            Schema::table('contents', fn (Blueprint $table) => $table->json('learning_objectives')->nullable());
        }
        if (! Schema::hasColumn('teachers', 'experience_years')) {
            Schema::table('teachers', fn (Blueprint $table) => $table->unsignedSmallInteger('experience_years')->nullable());
        }
        // Direct MP4 uploads have metadata but no HLS manifest.
        Schema::table('video_assets', fn (Blueprint $table) => $table->string('hls_manifest_key')->nullable()->change());
        if (! Schema::hasTable('content_completions')) {
            Schema::create('content_completions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('content_id')->constrained()->cascadeOnDelete();
                $table->timestamp('completed_at');
                $table->unique(['student_id', 'content_id']);
            });
        }
        if (! Schema::hasTable('device_transfer_requests')) {
            Schema::create('device_transfer_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('source_student_device_id')->constrained('student_devices')->cascadeOnDelete();
                $table->foreignId('target_device_id')->constrained('devices')->cascadeOnDelete();
                $table->string('token_hash', 64);
                $table->string('status')->default('pending');
                $table->timestamp('expires_at');
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'expires_at']);
                $table->index(['student_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_transfer_requests');
        Schema::dropIfExists('content_completions');
        Schema::table('teachers', fn (Blueprint $table) => $table->dropColumn('experience_years'));
        Schema::table('contents', fn (Blueprint $table) => $table->dropColumn('learning_objectives'));
        // Preserve this pre-existing production column and its profile images on rollback.
        DB::table('video_assets')->whereNull('hls_manifest_key')->update(['hls_manifest_key' => '']);
        Schema::table('video_assets', fn (Blueprint $table) => $table->string('hls_manifest_key')->nullable(false)->change());
    }
};
