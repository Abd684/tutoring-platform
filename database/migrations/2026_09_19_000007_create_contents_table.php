<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->enum('type', ['video', 'pdf', 'quiz', 'link']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('order_no')->default(0);
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->dateTime('published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
