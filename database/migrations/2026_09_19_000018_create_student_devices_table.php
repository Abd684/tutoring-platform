<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->boolean('is_active')->default(true);
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->unique(['student_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_devices');
    }
};
