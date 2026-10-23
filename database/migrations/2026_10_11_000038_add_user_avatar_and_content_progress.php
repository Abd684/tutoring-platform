<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable();
        });
        Schema::create('content_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_id')->constrained()->cascadeOnDelete();
            $table->dateTime('completed_at');
            $table->timestamps();
            $table->unique(['student_id', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_progress');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar_path'));
    }
};
