<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_device_id')->constrained('student_devices')->cascadeOnDelete();
            $table->string('refresh_token_hash');
            $table->enum('status', ['active', 'expired', 'revoked'])->default('active');
            $table->dateTime('last_activity_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sessions');
    }
};
