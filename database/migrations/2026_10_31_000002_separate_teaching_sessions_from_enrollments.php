<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits independently. Validate first, then resume each schema
        // step explicitly; never recreate or truncate a partially migrated table.
        $legacySessions = Schema::hasColumn('sessions', 'enrollment_id');
        $legacyAttendance = Schema::hasColumn('attendance', 'enrollment_id');
        $plans = [];
        $groupTargets = [];
        foreach (DB::table('sessions')->orderBy('id')->get() as $session) {
            $type = strtolower($session->type);
            if (! in_array($type, ['individual', 'group'], true)) {
                throw new RuntimeException("Unknown teaching type for session {$session->id}.");
            }
            if ($type === 'group') {
                $id = $session->type_id ?? $session->group_id ?? null;
                if (! $id || ! DB::table('groups')->join('teacher_subjects', 'teacher_subjects.id', '=', 'groups.teacher_subject_id')->where('groups.id', $id)->where('teacher_subjects.teacher_id', $session->teacher_id)->exists()
                    || (isset($session->group_id, $session->type_id) && (int) $session->group_id !== (int) $session->type_id)) {
                    throw new RuntimeException("Missing or mismatched group for session {$session->id}.");
                }
                $groupTargets[$session->id] = $id;

                continue;
            }
            if (! $legacySessions) {
                $this->individual($session->type_id ?? null, null, null, $session->teacher_id);

                continue;
            }
            $enrollment = DB::table('enrollments')->find($session->enrollment_id);
            if (! $enrollment || (int) $enrollment->teacher_id !== (int) $session->teacher_id) {
                throw new RuntimeException("Missing enrollment or teacher mismatch on legacy session {$session->id}.");
            }
            $courseId = $this->courseId($enrollment);
            $plans[$enrollment->id] ??= ['student_id' => $enrollment->student_id, 'teacher_subject_id' => $courseId, 'ids' => [], 'sessions' => []];
            $plans[$enrollment->id]['sessions'][] = $session->id;
            if (! empty($session->type_id)) {
                $this->individual($session->type_id, $enrollment->student_id, $courseId, $session->teacher_id);
                $plans[$enrollment->id]['ids'][] = (int) $session->type_id;
            }
        }
        foreach ($plans as $enrollmentId => &$plan) {
            $ids = array_unique($plan['ids']);
            if (count($ids) > 1) {
                throw new RuntimeException("Conflicting individuals for legacy enrollment {$enrollmentId}.");
            }
            $plan['individual_id'] = $ids ? reset($ids) : config('teaching.legacy_enrollment_individuals.'.$enrollmentId);
            if ($plan['individual_id']) {
                $this->individual($plan['individual_id'], $plan['student_id'], $plan['teacher_subject_id']);
            } elseif (Schema::hasTable('individuals') && DB::table('individuals')->where('student_id', $plan['student_id'])->where('teacher_subject_id', $plan['teacher_subject_id'])->exists()) {
                // An earlier, non-transactional insert may be orphaned. Matching a
                // student/course alone is not evidence that this is the same record.
                throw new RuntimeException("Unmapped individual for legacy enrollment {$enrollmentId}; verify and set teaching.legacy_enrollment_individuals before retrying.");
            }
        }
        unset($plan);
        if ($legacyAttendance) {
            if (DB::table('attendance')->leftJoin('enrollments', 'enrollments.id', '=', 'attendance.enrollment_id')->whereNull('enrollments.id')->exists()) {
                throw new RuntimeException('Cannot identify student for legacy attendance.');
            }
            if (DB::table('attendance')->join('enrollments', 'enrollments.id', '=', 'attendance.enrollment_id')->groupBy('attendance.session_id', 'enrollments.student_id')->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Resolve duplicate session/student attendance records before migration.');
            }
            if (Schema::hasColumn('attendance', 'student_id') && DB::table('attendance')->join('enrollments', 'enrollments.id', '=', 'attendance.enrollment_id')->whereNotNull('attendance.student_id')->whereColumn('attendance.student_id', '!=', 'enrollments.student_id')->exists()) {
                throw new RuntimeException('Existing attendance student disagrees with its legacy enrollment.');
            }
        }
        if (Schema::hasTable('group_enrollments') && DB::table('group_enrollments')->leftJoin('enrollments', 'enrollments.id', '=', 'group_enrollments.enrollment_id')->whereNull('enrollments.id')->exists()) {
            throw new RuntimeException('Cannot identify student for a legacy group membership.');
        }
        if (! Schema::hasTable('individuals')) {
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
        }
        if (! Schema::hasTable('group_students')) {
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
        }
        Schema::table('sessions', fn (Blueprint $table) => $table->string('type', 20)->change());
        $this->column('sessions', 'type_id', fn (Blueprint $table) => $table->unsignedBigInteger('type_id')->nullable());
        $this->column('sessions', 'progress_notes', fn (Blueprint $table) => $table->text('progress_notes')->nullable());
        $this->column('sessions', 'progress_updated_at', fn (Blueprint $table) => $table->dateTime('progress_updated_at')->nullable());
        $this->index('sessions', ['type', 'type_id', 'starts_at']);
        // The new individual and all session pointers are committed together.
        // A retry cannot create a second individual after an interrupted backfill.
        DB::transaction(function () use ($plans, $groupTargets) {
            foreach ($plans as $plan) {
                $id = $plan['individual_id'] ?: DB::table('individuals')->insertGetId([
                    'student_id' => $plan['student_id'], 'teacher_subject_id' => $plan['teacher_subject_id'],
                    'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('sessions')->whereIn('id', $plan['sessions'])->update(['type' => 'individual', 'type_id' => $id]);
            }
            foreach ($groupTargets as $sessionId => $id) {
                DB::table('sessions')->where('id', $sessionId)->update(['type' => 'group', 'type_id' => $id]);
            }
            if (Schema::hasTable('group_enrollments')) {
                foreach (DB::table('group_enrollments')->join('enrollments', 'enrollments.id', '=', 'group_enrollments.enrollment_id')->select('group_enrollments.*', 'enrollments.student_id')->orderBy('group_enrollments.id')->get() as $member) {
                    $key = ['group_id' => $member->group_id, 'student_id' => $member->student_id];
                    $existing = DB::table('group_students')->where($key)->first();
                    if (! $existing || $existing->status !== 'active') {
                        DB::table('group_students')->updateOrInsert($key, ['status' => $member->status, 'joined_at' => $member->joined_at, 'left_at' => $member->left_at]);
                    }
                }
            }
        });
        $this->column('attendance', 'student_id', fn (Blueprint $table) => $table->unsignedBigInteger('student_id')->nullable());
        $this->foreign('attendance', 'student_id', 'students');
        if ($legacyAttendance) {
            DB::transaction(function () {
                foreach (DB::table('attendance')->get() as $record) {
                    DB::table('attendance')->where('id', $record->id)->update(['student_id' => DB::table('enrollments')->where('id', $record->enrollment_id)->value('student_id')]);
                }
            });
        }
        if (DB::table('attendance')->whereNull('student_id')->exists() || DB::table('attendance')->groupBy('session_id', 'student_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve missing or duplicate session/student attendance before migration.');
        }
        // InnoDB may use the OLD composite unique index to support session_id's
        // foreign key. Install its replacement BEFORE dropping that index.
        $this->index('attendance', ['session_id', 'student_id'], true);
        $this->dropForeign('attendance', 'enrollment_id');
        if (Schema::hasIndex('attendance', 'attendance_session_id_enrollment_id_unique')) {
            Schema::table('attendance', fn (Blueprint $table) => $table->dropUnique(['session_id', 'enrollment_id']));
        }
        if (Schema::hasColumn('attendance', 'enrollment_id')) {
            Schema::table('attendance', fn (Blueprint $table) => $table->dropColumn('enrollment_id'));
        }
        Schema::table('attendance', fn (Blueprint $table) => $table->unsignedBigInteger('student_id')->nullable(false)->change());
        foreach (['enrollment_id', 'group_id'] as $column) {
            $this->dropForeign('sessions', $column);
            if (Schema::hasColumn('sessions', $column)) {
                Schema::table('sessions', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        Schema::table('sessions', fn (Blueprint $table) => $table->unsignedBigInteger('type_id')->nullable(false)->change());
        $this->column('payment_checkouts', 'teacher_subject_id', fn (Blueprint $table) => $table->unsignedBigInteger('teacher_subject_id')->nullable());
        $this->foreign('payment_checkouts', 'teacher_subject_id', 'teacher_subjects');
        $this->column('paid_bookings', 'teacher_subject_id', fn (Blueprint $table) => $table->unsignedBigInteger('teacher_subject_id')->nullable());
        $this->foreign('paid_bookings', 'teacher_subject_id', 'teacher_subjects');
        $this->column('paid_bookings', 'individual_id', fn (Blueprint $table) => $table->unsignedBigInteger('individual_id')->nullable());
        $this->foreign('paid_bookings', 'individual_id', 'individuals');
        foreach (DB::table('paid_bookings')->whereNotNull('session_id')->get() as $booking) {
            $session = DB::table('sessions')->find($booking->session_id);
            if ($session && $session->type === 'individual') {
                $individual = DB::table('individuals')->find($session->type_id);
                if (($booking->individual_id && (int) $booking->individual_id !== (int) $individual->id) || ($booking->teacher_subject_id && (int) $booking->teacher_subject_id !== (int) $individual->teacher_subject_id)) {
                    throw new RuntimeException("Conflicting teaching target for paid booking {$booking->id}.");
                }
                DB::table('paid_bookings')->where('id', $booking->id)->update(['individual_id' => $individual->id, 'teacher_subject_id' => $individual->teacher_subject_id]);
            }
        }
        if (Schema::hasTable('group_enrollments')) {
            // Only remove the obsolete bridge after checking every migrated member.
            foreach (DB::table('group_enrollments')->join('enrollments', 'enrollments.id', '=', 'group_enrollments.enrollment_id')->select('group_enrollments.group_id', 'group_enrollments.status', 'enrollments.student_id')->get() as $member) {
                $query = DB::table('group_students')->where('group_id', $member->group_id)->where('student_id', $member->student_id);
                if (! $query->exists() || ($member->status === 'active' && ! $query->where('status', 'active')->exists())) {
                    throw new RuntimeException('Legacy membership backfill verification failed.');
                }
            }
            Schema::drop('group_enrollments');
        }
    }

    private function individual($id, $student = null, $course = null, $teacher = null): void
    {
        $individual = $id && Schema::hasTable('individuals') ? DB::table('individuals')->find($id) : null;
        if (! $individual || ($student !== null && (int) $individual->student_id !== (int) $student)
            || ($course !== null && (int) $individual->teacher_subject_id !== (int) $course)
            || ($teacher !== null && ! DB::table('teacher_subjects')->where('id', $individual->teacher_subject_id)->where('teacher_id', $teacher)->exists())) {
            throw new RuntimeException('Missing or mismatched individual target; no legacy data has been discarded.');
        }
    }

    private function column(string $table, string $column, Closure $callback): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, $callback);
        }
    }

    private function index(string $table, array $columns, bool $unique = false): void
    {
        $name = $table.'_'.implode('_', $columns).($unique ? '_unique' : '_index');
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, fn (Blueprint $blueprint) => $unique ? $blueprint->unique($columns) : $blueprint->index($columns));
        }
    }

    private function foreign(string $table, string $column, string $target): void
    {
        if (! collect(Schema::getForeignKeys($table))->contains(fn ($key) => $key['columns'] === [$column])) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column)->references('id')->on($target)->restrictOnDelete());
        }
    }

    private function dropForeign(string $table, string $column): void
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($key['name'] ?? [$column]));
            }
        }
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
