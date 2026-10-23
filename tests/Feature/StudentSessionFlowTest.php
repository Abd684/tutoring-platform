<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentSessionFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Student $student;

    private Teacher $teacher;

    private TeacherSubject $course;

    private Lesson $lesson;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $this->user->id, 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->course = TeacherSubject::create(['teacher_id' => $this->teacher->id, 'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id, 'price' => 100, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $this->course->id, 'title' => 'Algebra', 'status' => 'published']);
        $this->lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Equations', 'status' => 'published']);
        $this->enrollment = Enrollment::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'enrollable_type' => TeacherSubject::class, 'enrollable_id' => $this->course->id, 'price' => 100, 'status' => 'active']);
        Sanctum::actingAs($this->user);
    }

    private function makeSession(array $extra = []): Session
    {
        return Session::create(array_merge(['teacher_id' => $this->teacher->id,
            'type' => 'INDIVIDUAL', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled'], $extra));
    }

    private function slots(): string
    {
        return '/api/v1/teachers/'.$this->teacher->id.'/available-sessions';
    }

    public function test_bookings_include_only_own_upcoming_sessions_and_group_enrollment(): void
    {
        $own = $this->makeSession(['enrollment_id' => $this->enrollment->id]);
        $this->makeSession(['enrollment_id' => $this->enrollment->id, 'status' => 'cancelled']);
        $this->makeSession(['enrollment_id' => $this->enrollment->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->subHours(23)]);
        $this->makeSession();
        $group = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'Test', 'capacity' => 10, 'status' => 'active']);
        GroupEnrollment::create(['group_id' => $group->id, 'enrollment_id' => $this->enrollment->id, 'status' => 'active']);
        $groupSession = $this->makeSession(['type' => 'GROUP', 'group_id' => $group->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
        $this->getJson('/api/v1/student/bookings?student_id=999')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.0.teacher.id', $this->teacher->id)
            ->assertJsonPath('data.0.enrollment.id', $this->enrollment->id)
            ->assertJsonPath('data.1.id', $groupSession->id)->assertJsonPath('data.1.enrollment.id', $this->enrollment->id);
    }

    public function test_available_booking_cancellation_and_expiration(): void
    {
        $availability = TeacherAvailability::create(['teacher_id' => $this->teacher->id, 'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00', 'timezone' => 'UTC', 'recurrence_type' => 'none', 'status' => 'active']);
        $slots = '/api/v1/teachers/'.$this->teacher->id.'/available-slots';
        $this->getJson($slots)->assertOk()->assertJsonCount(1, 'data');
        $payload = ['enrollment_id' => $this->enrollment->id, 'starts_at' => now()->addDay()->startOfDay()->addHours(10)->toISOString(), 'ends_at' => now()->addDay()->startOfDay()->addHours(11)->toISOString()];
        $id = $this->postJson('/api/v1/teacher-availabilities/'.$availability->id.'/book', $payload)->assertCreated()->json('data.id');
        $this->deleteJson('/api/v1/sessions/'.$id.'/booking')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.enrollment_id', $this->enrollment->id);
        $this->getJson($slots)->assertOk()->assertJsonCount(1, 'data');
        $this->enrollment->update(['expires_at' => now()->addHour()]);
        $this->getJson($slots)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/teacher-availabilities/'.$availability->id.'/book', $payload)->assertUnprocessable();
    }

    public function test_booking_rejects_overlap_across_different_enrollments(): void
    {
        $other = $this->enrollment->replicate();
        $other->save();
        $start = now()->addDay()->startOfDay()->addHours(10);
        $end = $start->copy()->addHour();
        $this->makeSession(['enrollment_id' => $other->id, 'starts_at' => $start, 'ends_at' => $end]);
        $availability = TeacherAvailability::create(['teacher_id' => $this->teacher->id, 'date' => $start->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00', 'timezone' => 'UTC', 'recurrence_type' => 'none', 'status' => 'active']);
        $this->postJson('/api/v1/teacher-availabilities/'.$availability->id.'/book', ['enrollment_id' => $this->enrollment->id, 'starts_at' => $start->toISOString(), 'ends_at' => $end->toISOString()])->assertConflict();
    }

    public function test_manual_payment_requires_admin_and_activates_once(): void
    {
        $this->enrollment->update(['status' => 'pending']);
        $url = '/api/v1/student/enrollments/'.$this->enrollment->id.'/payments';
        $payment = $this->postJson($url, ['provider_reference' => 'TRANSFER-100', 'amount' => 1, 'status' => 'succeeded'])
            ->assertCreated()->assertJsonPath('data.amount', '100.00')->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson($url, ['provider_reference' => 'TRANSFER-100'])->assertOk()->assertJsonPath('data.id', $payment);
        $this->assertSame('pending', $this->enrollment->fresh()->status);
        $review = '/api/v1/admin/payments/'.$payment.'/review';
        $this->postJson($review, ['decision' => 'confirm'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin', 'status' => 'active']));
        $this->postJson($review, ['decision' => 'confirm'])->assertOk()->assertJsonPath('data.enrollment.status', 'active')->assertJsonPath('data.enrollment.expires_at', null);
        $paidAt = Payment::find($payment)->paid_at->toISOString();
        $this->travel(1)->hours();
        $this->postJson($review, ['decision' => 'confirm'])->assertOk();
        $this->assertSame($paidAt, Payment::find($payment)->paid_at->toISOString());
        $this->patchJson('/api/v1/payments/'.$payment, ['status' => 'pending'])->assertUnprocessable();
    }

    public function test_payment_rejection_allows_retry_and_other_students_cannot_access(): void
    {
        $this->enrollment->update(['status' => 'pending']);
        $url = '/api/v1/student/enrollments/'.$this->enrollment->id.'/payments';
        $id = $this->postJson($url, ['provider_reference' => 'FIRST'])->assertCreated()->json('data.id');
        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin', 'status' => 'active']));
        $this->postJson('/api/v1/admin/payments/'.$id.'/review', ['decision' => 'reject'])->assertOk()->assertJsonPath('data.payment.status', 'failed');
        Sanctum::actingAs($this->user);
        $this->postJson($url, ['provider_reference' => 'SECOND'])->assertCreated();
        $other = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/student/payments/'.$id)->assertNotFound();
        $this->postJson($url, ['provider_reference' => 'THIRD'])->assertForbidden();
    }

    public function test_teacher_creates_and_lists_only_their_sessions(): void
    {
        Sanctum::actingAs($this->teacher->user);
        $body = ['type' => 'INDIVIDUAL', 'enrollment_id' => $this->enrollment->id,
            'starts_at' => now()->addDays(5)->toISOString(), 'ends_at' => now()->addDays(5)->addHour()->toISOString()];
        $id = $this->postJson('/api/v1/teacher/sessions', $body)->assertCreated()->assertJsonPath('data.teacher_id', $this->teacher->id)->json('data.id');
        $this->getJson('/api/v1/teacher/sessions')->assertOk()->assertJsonPath('meta.total', 1);
        $other = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/teacher/sessions', array_merge($body, ['teacher_id' => $this->teacher->id]))->assertUnprocessable();
        $this->getJson('/api/v1/teacher/sessions')->assertJsonPath('meta.total', 0);
    }
}
