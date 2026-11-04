<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Store only active device registrations. FCM tokens will be encrypted
        // by the application; this column must never contain a raw token.
        Schema::create('push_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_ciphertext');
            $table->string('platform', 16);
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
            $table->index(['device_id', 'is_active']);
        });

        // A broadcast is saved ONCE, not copied into notifications for each user.
        // The API will filter campaigns by recipient role / specific user and
        // by dispatched_at, and show only the first 30 days after dispatch.
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_scope', 24); // all, students, teachers, student, teacher
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('body');
            $table->json('data')->nullable();
            $table->string('status', 24)->default('draft');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
            $table->index(['target_scope', 'dispatched_at']);
        });

        // A marker is created only WHEN a recipient reads a campaign.
        // Keep markers until their campaign expires (<= 30 days); removing
        // them earlier could make a previously-read campaign appear unread.
        Schema::create('notification_campaign_reads', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained('notification_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('read_at');
            $table->primary(['campaign_id', 'user_id']);
        });

        // Existing personal notifications remain in the same table. A database
        // default assigns a creation time even with the current model's
        // timestamps=false. Legacy rows gain the migration-time default;
        // they are NOT deleted by this migration.
        Schema::table('notifications', function (Blueprint $table) {
            $table->dateTime('created_at')->useCurrent();
            // One composite index supports both retention rules:
            // read_at <= 7 days or (read_at IS NULL and created_at <= 30 days).
            $table->index(['read_at', 'created_at'], 'notifications_retention_idx');
        });

        // Intentionally no permanent notification_deliveries table: FCM sends
        // will be handled through bounded queue jobs and short-lived retries.
    }

    public function down(): void
    {
        // Do not silently delete campaigns, read markers or registrations.
        throw new RuntimeException(
            'Notification infrastructure contains user data. Use a reviewed, backed-up manual rollback.'
        );
    }
};
