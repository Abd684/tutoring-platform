<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 150);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['event_key', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_notification_events');
    }
};
