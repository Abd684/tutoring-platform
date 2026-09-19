<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_subject_id')->constrained('teacher_subjects')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->enum('status', ['forming', 'active', 'closed'])->default('forming');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
