<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->enum('type', ['mcq', 'true_false', 'essay']);
            $table->text('body');
            $table->decimal('points', 8, 2)->default(0);
            $table->json('rubric_json')->nullable();
            $table->unsignedInteger('order_no')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
