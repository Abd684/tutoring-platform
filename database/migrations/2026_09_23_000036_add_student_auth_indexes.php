<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_devices', function (Blueprint $table) {
            $table->index(
                ['student_id', 'status', 'is_active'],
                'student_devices_student_active_idx'
            );
        });

        Schema::table('device_sessions', function (Blueprint $table) {
            $table->index('refresh_token_hash', 'device_sessions_refresh_hash_idx');
            $table->index(
                ['student_device_id', 'status', 'expires_at'],
                'device_sessions_device_status_expiry_idx'
            );
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'status'], 'users_role_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('device_sessions', function (Blueprint $table) {
            $table->dropIndex('device_sessions_refresh_hash_idx');
            $table->dropIndex('device_sessions_device_status_expiry_idx');
        });

        Schema::table('student_devices', function (Blueprint $table) {
            $table->dropIndex('student_devices_student_active_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_status_idx');
        });
    }
};
