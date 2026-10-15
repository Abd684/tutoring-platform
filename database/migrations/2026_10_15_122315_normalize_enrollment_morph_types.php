<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('enrollments')
            ->where('enrollable_type', 'App\\Models\\TeacherSubject')
            ->update([
                'enrollable_type' => 'teacher_subject',
            ]);

        DB::table('enrollments')
            ->where('enrollable_type', 'App\\Models\\Unit')
            ->update([
                'enrollable_type' => 'unit',
            ]);

        DB::table('enrollments')
            ->where('enrollable_type', 'App\\Models\\Content')
            ->update([
                'enrollable_type' => 'content',
            ]);
    }

    public function down(): void
    {
        DB::table('enrollments')
            ->where('enrollable_type', 'teacher_subject')
            ->update([
                'enrollable_type' => 'App\\Models\\TeacherSubject',
            ]);

        DB::table('enrollments')
            ->where('enrollable_type', 'unit')
            ->update([
                'enrollable_type' => 'App\\Models\\Unit',
            ]);

        DB::table('enrollments')
            ->where('enrollable_type', 'content')
            ->update([
                'enrollable_type' => 'App\\Models\\Content',
            ]);
    }
};
