<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Resolve every legacy target before changing schema. Never guess a subject.
        $targets = [];
        foreach (DB::table('sessions')->where('type', 'INDIVIDUAL')->get() as $session) {
            $enrollment = DB::table('enrollments')->find($session->enrollment_id);
            if (! $enrollment) {
                throw new RuntimeException("Session {$session->id} has no enrollment to identify its student.");
            }
            if ((int) $enrollment->teacher_id !== (int) $session->teacher_id) {
                throw new RuntimeException("Teacher mismatch on legacy session {$session->id}.");
            }
            $courseId = $this->courseId($enrollment);
            $targets[$enrollment->id] = ['student_id' => $enrollment->student_id, 'teacher_subject_id' => $courseId,
                'status' => 'active', 'starts_at' => null, 'ends_at' => null, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (DB::table('sessions')->where('type', 'GROUP')->get() as $session) {
            if (! DB::table('groups')->join('teacher_subjects', 'teacher_subjects.id', '=', 'groups.teacher_subject_id')->where('groups.id', $session->group_id)->where('teacher_subjects.teacher_id', $session->teacher_id)->exists()) {
                throw new RuntimeException("Missing group for session {$session->id}.");
            }
        }
        if (DB::table('attendance')->join('enrollments', 'enrollments.id', '=', 'attendance.enrollment_id')
            ->groupBy('attendance.session_id', 'enrollments.student_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve duplicate session/student attendance records before migration.');
        }
        Schema::create('individuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('teacher_subject_id')->constrained('teacher_subjects')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'teacher_subject_id', 'status']);
        });
        Schema::create('group_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->dateTime('joined_at')->nullable();
            $table->dateTime('left_at')->nullable();
            $table->unique(['group_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });
        Schema::table('sessions', function (Blueprint $table) {
            $table->string('type', 20)->change();
            $table->unsignedBigInteger('type_id')->nullable();
            $table->text('progress_notes')->nullable();
            $table->dateTime('progress_updated_at')->nullable();
            $table->index(['type', 'type_id', 'starts_at']);
        });
        $ids = [];
        foreach ($targets as $enrollmentId => $data) {
            $ids[$enrollmentId] = DB::table('individuals')->insertGetId($data);
        }
        foreach (DB::table('sessions')->get() as $session) {
            $isGroup = $session->type === 'GROUP';
            if ($isGroup && ! DB::table('groups')->where('id', $session->group_id)->exists()) {
                throw new RuntimeException("Missing group for session {$session->id}.");
            }
            DB::table('sessions')->where('id', $session->id)->update(['type' => $isGroup ? 'group' : 'individual', 'type_id' => $isGroup ? $session->group_id : $ids[$session->enrollment_id]]);
        }
        foreach (DB::table('group_enrollments')->join('enrollments', 'enrollments.id', '=', 'group_enrollments.enrollment_id')
            ->select('group_enrollments.*', 'enrollments.student_id')->orderBy('group_enrollments.id')->get() as $member) {
            $key = ['group_id' => $member->group_id, 'student_id' => $member->student_id];
            $existing = DB::table('group_students')->where($key)->first();
            // Several online enrollments may have represented the same group member.
            if (! $existing || $existing->status !== 'active') {
                DB::table('group_students')->updateOrInsert($key, ['status' => $member->status, 'joined_at' => $member->joined_at, 'left_at' => $member->left_at]);
            }
        }
        Schema::table('attendance', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->constrained('students')->restrictOnDelete();
        });
        foreach (DB::table('attendance')->get() as $record) {
            $studentId = DB::table('enrollments')->where('id', $record->enrollment_id)->value('student_id');
            if (! $studentId) {
                throw new RuntimeException("Cannot identify student for attendance {$record->id}.");
            }
            DB::table('attendance')->where('id', $record->id)->update(['student_id' => $studentId]);
        }
        // Duplicate attendance cannot be merged without potentially losing notes/times.
        if (DB::table('attendance')->groupBy('session_id', 'student_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve duplicate session/student attendance records before migration.');
        }
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropUnique(['session_id', 'enrollment_id']);
            $table->dropForeign(['enrollment_id']);
            $table->dropColumn('enrollment_id');
            $table->unsignedBigInteger('student_id')->nullable(false)->change();
            $table->unique(['session_id', 'student_id']);
        });
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropForeign(['enrollment_id']);
            $table->dropForeign(['group_id']);
            $table->dropColumn(['enrollment_id', 'group_id']);
            $table->unsignedBigInteger('type_id')->nullable(false)->change();
        });
        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->foreignId('teacher_subject_id')->nullable()->constrained('teacher_subjects')->restrictOnDelete();
        });
        Schema::table('paid_bookings', function (Blueprint $table) {
            $table->foreignId('teacher_subject_id')->nullable()->constrained('teacher_subjects')->restrictOnDelete();
            $table->foreignId('individual_id')->nullable()->constrained('individuals')->restrictOnDelete();
        });
        foreach (DB::table('paid_bookings')->whereNotNull('session_id')->get() as $booking) {
            $session = DB::table('sessions')->find($booking->session_id);
            if ($session && $session->type === 'individual') {
                DB::table('paid_bookings')->where('id', $booking->id)->update(['individual_id' => $session->type_id,
                    'teacher_subject_id' => DB::table('individuals')->where('id', $session->type_id)->value('teacher_subject_id')]);
            }
        }
        Schema::drop('group_enrollments');
    }

    private function courseId(object $enrollment): int
    {
        $id = config('teaching.legacy_enrollment_subjects.'.$enrollment->id) ?? match ($enrollment->enrollable_type) {
            'teacher_subject', 'App\\Models\\TeacherSubject' => $enrollment->enrollable_id,
            'unit', 'App\\Models\\Unit' => DB::table('units')->where('id', $enrollment->enrollable_id)->value('teacher_subject_id'),
            'content', 'App\\Models\\Content' => DB::table('contents')->join('lessons', 'lessons.id', '=', 'contents.lesson_id')->join('units', 'units.id', '=', 'lessons.unit_id')->where('contents.id', $enrollment->enrollable_id)->value('units.teacher_subject_id'),
            default => null,
        };
        if (! $id) {
            $courses = DB::table('teacher_subjects')->where('teacher_id', $enrollment->teacher_id)->pluck('id');
            if ($courses->count() === 1) {
                $id = $courses->first();
            }
        }
        if (! $id || ! DB::table('teacher_subjects')->where('id', $id)->where('teacher_id', $enrollment->teacher_id)->exists()) {
            throw new RuntimeException("Cannot determine subject for legacy enrollment {$enrollment->id}; set teaching.legacy_enrollment_subjects for this enrollment before migration.");
        }

        return (int) $id;
    }

    public function down(): void
    {
        throw new RuntimeException('This migration separates teaching from enrollments. Restore the pre-migration backup to roll back without losing teaching data.');
    }
};
