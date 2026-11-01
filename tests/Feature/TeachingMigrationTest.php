<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TeachingMigrationTest extends TestCase
{
    private string $previousConnection;

    private $previousSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        $this->previousSchema = Schema::getFacadeRoot();
        config(['database.connections.teaching_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('teaching_legacy');
        Schema::swap(DB::connection('teaching_legacy')->getSchemaBuilder());
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (str_contains($path, '2026_10_31_000002') || str_contains($path, '2026_10_31_000003')) {
                continue;
            }
            (require $path)->up();
        }
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousConnection);
        Schema::swap($this->previousSchema);
        DB::purge('teaching_legacy');
        parent::tearDown();
    }

    private function legacy(): array
    {
        $teacherUser = DB::table('users')->insertGetId(['name' => 'Teacher', 'phone' => '0991', 'email' => null, 'password_hash' => 'hash', 'role' => 'teacher', 'status' => 'active']);
        $studentUser = DB::table('users')->insertGetId(['name' => 'Student', 'phone' => '0992', 'email' => null, 'password_hash' => 'hash', 'role' => 'student', 'status' => 'active']);
        $teacher = DB::table('teachers')->insertGetId(['user_id' => $teacherUser, 'status' => 'active']);
        $student = DB::table('students')->insertGetId(['user_id' => $studentUser, 'status' => 'active']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Math', 'status' => 'active']);
        $course = DB::table('teacher_subjects')->insertGetId(['teacher_id' => $teacher, 'subject_id' => $subject, 'status' => 'active']);
        $enrollment = DB::table('enrollments')->insertGetId(['student_id' => $student, 'teacher_id' => $teacher, 'enrollable_type' => 'teacher_subject', 'enrollable_id' => $course, 'status' => 'active']);
        $group = DB::table('groups')->insertGetId(['teacher_subject_id' => $course, 'name' => 'Group', 'capacity' => 3, 'status' => 'active']);
        $member = DB::table('group_enrollments')->insertGetId(['group_id' => $group, 'enrollment_id' => $enrollment, 'status' => 'active', 'joined_at' => now()]);
        $session = DB::table('sessions')->insertGetId(['teacher_id' => $teacher, 'type' => 'INDIVIDUAL', 'enrollment_id' => $enrollment,
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'capacity' => 1, 'status' => 'scheduled']);
        DB::table('attendance')->insert(['session_id' => $session, 'enrollment_id' => $enrollment, 'status' => 'present', 'note' => 'Keep this note']);

        return compact('teacher', 'student', 'course', 'enrollment', 'group', 'session');
    }

    public function test_legacy_sessions_members_and_attendance_are_backfilled_before_columns_are_removed(): void
    {
        $f = $this->legacy();
        $availability = DB::table('teacher_availabilities')->insertGetId(['teacher_id' => $f['teacher'], 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '11:00', 'timezone' => 'UTC', 'recurrence_type' => 'none', 'status' => 'active']);
        $booking = DB::table('paid_bookings')->insertGetId(['student_id' => $f['student'], 'teacher_id' => $f['teacher'],
            'availability_id' => $availability, 'session_id' => $f['session'], 'status' => 'CONFIRMED',
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'hold_expires_at' => now()->addDay()]);
        $groupSession = DB::table('sessions')->insertGetId(['teacher_id' => $f['teacher'], 'type' => 'GROUP', 'group_id' => $f['group'],
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'capacity' => 3, 'status' => 'scheduled']);
        (require database_path('migrations/2026_10_31_000002_separate_teaching_sessions_from_enrollments.php'))->up();
        $session = DB::table('sessions')->find($f['session']);
        $this->assertSame('individual', $session->type);
        $this->assertDatabaseHas('paid_bookings', ['id' => $booking, 'individual_id' => $session->type_id, 'teacher_subject_id' => $f['course']]);
        $this->assertDatabaseHas('individuals', ['id' => $session->type_id, 'student_id' => $f['student'], 'teacher_subject_id' => $f['course']]);
        $this->assertDatabaseHas('sessions', ['id' => $groupSession, 'type' => 'group', 'type_id' => $f['group']]);
        $this->assertDatabaseHas('group_students', ['group_id' => $f['group'], 'student_id' => $f['student']]);
        $this->assertDatabaseHas('attendance', ['session_id' => $f['session'], 'student_id' => $f['student'], 'note' => 'Keep this note']);
        $this->assertFalse(Schema::hasTable('group_enrollments'));
        $this->assertFalse(Schema::hasColumn('sessions', 'enrollment_id'));
        $this->assertFalse(Schema::hasColumn('sessions', 'group_id'));
        $this->assertFalse(Schema::hasColumn('attendance', 'enrollment_id'));
        $this->assertTrue(Schema::hasColumn('sessions', 'progress_notes'));
    }

    public function test_ambiguous_legacy_subject_stops_before_schema_changes(): void
    {
        $f = $this->legacy();
        $subject = DB::table('subjects')->insertGetId(['name' => 'Other', 'status' => 'active']);
        DB::table('teacher_subjects')->insert(['teacher_id' => $f['teacher'], 'subject_id' => $subject, 'status' => 'active']);
        DB::table('enrollments')->where('id', $f['enrollment'])->update(['enrollable_type' => 'paid_booking', 'enrollable_id' => 999]);
        try {
            (require database_path('migrations/2026_10_31_000002_separate_teaching_sessions_from_enrollments.php'))->up();
            $this->fail('Ambiguous subject should stop migration.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot determine subject', $error->getMessage());
        }
        $this->assertFalse(Schema::hasTable('individuals'));
        $this->assertTrue(Schema::hasColumn('sessions', 'enrollment_id'));
        $this->assertDatabaseCount('group_enrollments', 1);
        config(['teaching.legacy_enrollment_subjects.'.$f['enrollment'] => $f['course']]);
        (require database_path('migrations/2026_10_31_000002_separate_teaching_sessions_from_enrollments.php'))->up();
        $this->assertDatabaseHas('individuals', ['student_id' => $f['student'], 'teacher_subject_id' => $f['course']]);
    }

    public function test_interrupted_backfill_resumes_without_duplicate_individuals_or_losing_attendance(): void
    {
        $f = $this->legacy();
        $migration = require database_path('migrations/2026_10_31_000002_separate_teaching_sessions_from_enrollments.php');
        $interrupt = true;
        DB::listen(function ($query) use (&$interrupt) {
            if ($interrupt && str_contains($query->sql, 'attendance_session_id_student_id_unique')) {
                $interrupt = false;
                throw new \RuntimeException('Simulated interruption after new attendance index');
            }
        });
        try {
            $migration->up();
            $this->fail('Expected simulated interruption');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated interruption after new attendance index', $error->getMessage());
        }
        $id = DB::table('sessions')->where('id', $f['session'])->value('type_id');
        $this->assertDatabaseCount('individuals', 1);
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('individuals', 1);
        $this->assertDatabaseHas('sessions', ['id' => $f['session'], 'type' => 'individual', 'type_id' => $id]);
        $this->assertDatabaseHas('attendance', ['session_id' => $f['session'], 'student_id' => $f['student'], 'note' => 'Keep this note']);
        (require database_path('migrations/2026_10_31_000003_complete_online_and_cash_teaching.php'))->up();
        $migration->up();
        $this->assertDatabaseCount('individuals', 1);
        $this->assertSame('subject', DB::table('individuals')->value('teaching_scope_type'));
        $this->assertTrue(Schema::hasIndex('refund_lesson_items', 'refund_lesson_items_request_allocation_unique'));
    }
}
