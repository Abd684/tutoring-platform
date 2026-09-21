<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governortates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('governortate_id')->constrained('governortates')->cascadeOnDelete();
        });

        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('school');
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->foreign('student_id')->references('id')->on('students')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropForeign(['student_id']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
            $table->string('school')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
        });

        Schema::dropIfExists('schools');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('governortates');
    }
};
