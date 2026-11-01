<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_join_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $t->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $t->string('status', 20)->default('pending');
            // Only pending rows use 1; NULL permits multiple historical decisions.
            $t->unsignedTinyInteger('pending_guard')->nullable()->default(1);
            $t->string('message', 1000)->nullable();
            $t->string('review_note', 1000)->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['group_id', 'student_id', 'pending_guard'], 'group_join_requests_one_pending');
            $t->index(['group_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Keep group join request history; restore the pre-upgrade backup to roll back safely.');
    }
};
