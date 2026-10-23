<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('avatar_path')->nullable();
            });
        }
        if (! Schema::hasTable('content_progress')) {
            Schema::create('content_progress', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('content_id')->constrained()->cascadeOnDelete();
                $table->dateTime('completed_at');
                $table->timestamps();
                $table->unique(['student_id', 'content_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('content_progress');
        // May predate this migration on the other release line; preserve profile images.
    }
};
