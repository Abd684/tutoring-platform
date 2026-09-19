<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('answer_id')->unique()->constrained('answers')->cascadeOnDelete();
            $table->string('model');
            $table->decimal('suggested_score', 8, 2)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('explanation')->nullable();
            $table->decimal('teacher_score', 8, 2)->nullable();
            $table->enum('status', ['suggested', 'approved', 'overridden'])->default('suggested');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluations');
    }
};
