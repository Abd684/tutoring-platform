<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReleaseMergeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_migration_preserves_an_existing_profile_image_and_can_be_repeated(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/existing.png']);
        $migration = require database_path('migrations/2026_10_11_000038_add_user_avatar_and_content_progress.php');
        $migration->up();
        $migration->up();
        $this->assertSame('avatars/existing.png', $user->fresh()->avatar_path);
        $this->assertTrue(Schema::hasTable('content_progress'));
        $this->assertTrue(Schema::hasTable('content_completions'));
    }

    public function test_device_constraint_migrations_work_after_either_release_line(): void
    {
        $earlier = require database_path('migrations/2026_10_16_000001_enforce_single_active_student_device.php');
        $later = require database_path('migrations/2026_10_17_000001_enforce_single_active_student_device.php');
        try {
            foreach ([[$earlier, $later], [$later, $earlier]] as [$first, $second]) {
                $earlier->down();
                $first->up();
                $second->up();
                $first->up();
                $this->assertTrue(Schema::hasIndex('student_devices', 'student_devices_one_active'));
                $later->down();
                $this->assertTrue(Schema::hasIndex('student_devices', 'student_devices_one_active'));
            }
        } finally {
            $earlier->up();
        }
    }

    public function test_old_and_new_booking_endpoints_share_conflicts_and_cancellation(): void
    {
        $teacherUser = User::factory()->create(['role' => 'teacher']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id,
            'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id]);
        $user = User::factory()->create(['role' => 'student']);
        $student = Student::create(['user_id' => $user->id, 'status' => 'active']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $teacher->id,
            'enrollable_type' => 'teacher_subject', 'enrollable_id' => $course->id, 'status' => 'active']);
        $availability = TeacherAvailability::create(['teacher_id' => $teacher->id,
            'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00',
            'timezone' => 'UTC', 'recurrence_type' => 'none', 'status' => 'active']);
        $body = ['availability_id' => $availability->id, 'enrollment_id' => $enrollment->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toIso8601String()];
        $old = '/api/v1/teachers/'.$teacher->id.'/bookings';
        $new = '/api/v1/teacher-availabilities/'.$availability->id.'/book';
        Sanctum::actingAs($user);
        $id = $this->postJson($old, $body)->assertCreated()->json('data.id');
        $this->postJson($new, $body)->assertConflict();
        $this->deleteJson('/api/v1/sessions/'.$id.'/booking')->assertOk();
        $this->postJson($new, $body)->assertCreated();
        $this->postJson($old, $body)->assertUnprocessable();
        $this->assertDatabaseCount('sessions', 2);
        Sanctum::actingAs($teacherUser);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'INDIVIDUAL', 'enrollment_id' => $enrollment->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->setTimezone('Asia/Damascus')->toIso8601String(),
            'ends_at' => now()->addDay()->setTime(11, 0)->setTimezone('Asia/Damascus')->toIso8601String()])->assertConflict();
    }
}
