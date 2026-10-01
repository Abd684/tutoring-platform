<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCurrentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_enrollments_are_scoped_to_bearer_token_and_exclude_finished_bookings(): void
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $user->id, 'status' => 'active']);
        $other = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id]);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id]);
        $subject = Subject::create(['name' => 'Math', 'status' => 'active']);
        $item = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'price' => 10, 'status' => 'active']);
        $base = ['student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollable_type' => TeacherSubject::class, 'enrollable_id' => $item->id, 'price' => 10];
        $active = Enrollment::create(array_merge($base, ['status' => 'active', 'expires_at' => now()->addDay()]));
        $pending = Enrollment::create(array_merge($base, ['status' => 'pending']));
        Enrollment::create(array_merge($base, ['status' => 'active', 'expires_at' => now()->subDay()]));
        Enrollment::create(array_merge($base, ['status' => 'cancelled']));
        Enrollment::create(array_merge($base, ['student_id' => $other->id, 'status' => 'active']));

        $token = $user->createToken('student')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/v1/student/booking?student_id='.$other->id)
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.teacher.id', $teacher->id)
            ->assertJsonPath('data.0.item.title', 'Math');
        $this->assertSame([$pending->id, $active->id], array_column($response->json('data'), 'id'));
        $this->withToken($token)->getJson('/api/v1/student/enrollments')->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_current_enrollments_require_student_authentication(): void
    {
        $this->getJson('/api/v1/student/booking')->assertUnauthorized();
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->withToken($teacher->createToken('teacher')->plainTextToken)
            ->getJson('/api/v1/student/booking')->assertForbidden();
    }
}
