<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('attempts_allowed')->default(1);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
