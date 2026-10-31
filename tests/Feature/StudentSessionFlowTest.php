<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Individual;
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

    private Individual $individual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $this->user->id, 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $this->course = TeacherSubject::create(['teacher_id' => $this->teacher->id, 'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id, 'price' => 100, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $this->course->id, 'title' => 'Algebra', 'status' => 'published']);
        $this->lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Equations', 'status' => 'published']);
        $this->enrollment = Enrollment::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'enrollable_type' => (new TeacherSubject)->getMorphClass(), 'enrollable_id' => $this->course->id, 'price' => 100, 'status' => 'active']);
        $this->individual = Individual::create(['student_id' => $this->student->id, 'teacher_subject_id' => $this->course->id, 'status' => 'active']);
        Sanctum::actingAs($this->user);
    }

    private function interval(): array
    {
        return ['starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'ends_at' => now()->addDay()->setTime(11, 0)->toIso8601String()];
    }

    private function makeSession(array $extra = []): Session
    {
        return Session::create(array_replace(['teacher_id' => $this->teacher->id, 'type' => 'individual', 'type_id' => $this->individual->id,
            'status' => 'scheduled', 'capacity' => 1] + $this->interval(), $extra));
    }

    private function availability(): TeacherAvailability
    {
        return TeacherAvailability::create(['teacher_id' => $this->teacher->id, 'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00', 'recurrence_type' => 'none', 'timezone' => 'UTC', 'status' => 'active']);
    }

    public function test_individual_and_group_sessions_are_independent_of_online_enrollments(): void
    {
        $this->enrollment->delete();
        $own = $this->makeSession();
        $group = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'Group', 'capacity' => 3, 'status' => 'active']);
        GroupStudent::create(['group_id' => $group->id, 'student_id' => $this->student->id, 'status' => 'active']);
        $groupSession = $this->makeSession(['type' => 'group', 'type_id' => $group->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
        $response = $this->getJson('/api/v1/student/bookings')->assertOk()->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.type_id', $own->type_id)->assertJsonMissingPath('data.0.enrollment');
        $this->assertInstanceOf(Individual::class, $own->target);
        $this->assertInstanceOf(Group::class, $groupSession->target);
        $other = User::factory()->create();
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/student/bookings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student/sessions/'.$own->id)->assertNotFound();
    }

    public function test_booking_cancel_and_rebooking_preserve_teaching_and_history(): void
    {
        $a = $this->availability();
        $body = ['individual_id' => $this->individual->id] + $this->interval();
        $url = '/api/v1/teacher-availabilities/'.$a->id.'/book';
        $id = $this->postJson($url, $body)->assertCreated()->json('data.id');
        $this->postJson($url, $body)->assertConflict();
        $this->deleteJson('/api/v1/sessions/'.$id.'/booking')->assertOk();
        $this->assertSame('active', $this->individual->fresh()->status);
        $this->postJson($url, $body)->assertCreated();
        $this->assertDatabaseCount('sessions', 2);
    }

    public function test_morph_targets_validate_ownership_type_and_capacity(): void
    {
        Sanctum::actingAs($this->teacher->user);
        $url = '/api/v1/teacher/sessions';
        $this->postJson($url, ['type' => 'individual', 'enrollment_id' => $this->enrollment->id] + $this->interval())->assertUnprocessable();
        $id = $this->postJson($url, ['type' => 'individual', 'type_id' => $this->individual->id, 'capacity' => 8] + $this->interval())
            ->assertCreated()->assertJsonPath('data.capacity', 1)->assertJsonMissingPath('data.enrollment_id')->json('data.id');
        $this->patchJson($url.'/'.$id, ['type' => 'group'])->assertUnprocessable();
        $group = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'Group', 'capacity' => 3, 'status' => 'active']);
        $this->patchJson($url.'/'.$id, ['type' => 'group', 'type_id' => $group->id, 'capacity' => 2])->assertOk();
        $this->patchJson($url.'/'.$id, ['capacity' => 4])->assertUnprocessable();
        $foreignTeacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        Sanctum::actingAs($foreignTeacher->user);
        $this->postJson($url, ['type' => 'individual', 'type_id' => $this->individual->id] + $this->interval())->assertNotFound();
        $this->patchJson($url.'/'.$id, ['capacity' => 2])->assertNotFound();
    }

    public function test_progress_can_be_recorded_and_cleared_on_completed_sessions_by_owner_only(): void
    {
        $session = $this->makeSession(['starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(), 'status' => 'completed']);
        $url = '/api/v1/teacher/sessions/'.$session->id.'/progress';
        $this->patchJson($url, ['progress_notes' => 'Page 32'])->assertForbidden();
        Sanctum::actingAs($this->teacher->user);
        $this->patchJson($url, ['progress_notes' => 'وصلنا للصفحة ٣٢'])->assertOk()->assertJsonPath('data.progress_notes', 'وصلنا للصفحة ٣٢');
        $this->assertNotNull($session->fresh()->progress_updated_at);
        $this->patchJson($url, ['progress_notes' => str_repeat('a', 10001)])->assertUnprocessable();
        $this->patchJson($url, ['progress_notes' => null])->assertOk()->assertJsonPath('data.progress_notes', null);
        $session->update(['status' => 'cancelled']);
        $this->patchJson($url, ['progress_notes' => 'x'])->assertConflict();
    }

    public function test_attendance_uses_student_and_rejects_nonparticipants_and_duplicates(): void
    {
        $session = $this->makeSession();
        Sanctum::actingAs($this->teacher->user);
        $url = '/api/v1/teacher/sessions/'.$session->id.'/attendance';
        $body = ['records' => [['student_id' => $this->student->id, 'status' => 'present']]];
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();
        $this->assertDatabaseCount('attendance', 1);
        $other = Student::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
        $this->postJson($url, ['records' => [['student_id' => $other->id, 'status' => 'present']]])->assertUnprocessable();
        $this->postJson($url, ['records' => [$body['records'][0], $body['records'][0]]])->assertUnprocessable();
        $this->assertDatabaseHas('attendance', ['session_id' => $session->id, 'student_id' => $this->student->id]);
    }

    public function test_group_membership_is_direct_and_checks_capacity_and_teacher(): void
    {
        $group = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'Group', 'capacity' => 1, 'status' => 'active']);
        $this->enrollment->delete();
        Sanctum::actingAs($this->teacher->user);
        $url = '/api/v1/teacher/groups/'.$group->id.'/students';
        $this->postJson($url, ['student_id' => $this->student->id])->assertCreated();
        $this->postJson($url, ['student_id' => $this->student->id])->assertConflict();
        $other = Student::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
        $this->postJson($url, ['student_id' => $other->id])->assertConflict();
        $this->deleteJson($url.'/'.$this->student->id)->assertOk();
        $this->postJson($url, ['student_id' => $other->id])->assertCreated();
        Sanctum::actingAs($this->user);
        $this->getJson('/api/v1/student/groups')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student/groups?scope=history')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_individual_creation_is_scoped_and_dates_cannot_invalidate_scheduled_sessions(): void
    {
        Sanctum::actingAs($this->teacher->user);
        $id = $this->postJson('/api/v1/teacher/individuals', ['student_id' => $this->student->id, 'teacher_subject_id' => $this->course->id])
            ->assertCreated()->json('data.id');
        $this->getJson('/api/v1/teacher/individuals')->assertOk()->assertJsonCount(2, 'data');
        $this->makeSession(['type_id' => $id]);
        $this->patchJson('/api/v1/teacher/individuals/'.$id, ['status' => 'cancelled'])->assertConflict();
        $this->patchJson('/api/v1/teacher/individuals/'.$id, ['ends_at' => now()])->assertConflict();
        $this->patchJson('/api/v1/teacher/individuals/'.$id, ['student_id' => $this->student->id])->assertUnprocessable();
    }

    public function test_student_cannot_double_book_different_teachers(): void
    {
        $this->makeSession();
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $this->course->subject_id, 'status' => 'active']);
        $individual = Individual::create(['student_id' => $this->student->id, 'teacher_subject_id' => $course->id, 'status' => 'active']);
        Sanctum::actingAs($teacher->user);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'individual', 'type_id' => $individual->id] + $this->interval())->assertConflict();
    }

    public function test_manual_payment_requires_admin_and_activates_once(): void
    {
        $this->enrollment->update(['status' => 'pending']);
        $url = '/api/v1/student/enrollments/'.$this->enrollment->id.'/payments';
        // Historical manual transfers remain reviewable; proof-free submissions are retired.
        $this->postJson($url, ['provider_reference' => 'TRANSFER-100'])->assertStatus(410);
        $payment = Payment::create(['payer_id' => $this->user->id, 'payable_type' => $this->enrollment->getMorphClass(),
            'payable_id' => $this->enrollment->id, 'amount' => 100, 'currency' => 'SYP', 'provider' => 'manual_transfer',
            'provider_reference' => 'TRANSFER-100', 'status' => 'pending'])->id;
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

    public function test_historical_payment_rejection_and_other_students_cannot_access(): void
    {
        $this->enrollment->update(['status' => 'pending']);
        $url = '/api/v1/student/enrollments/'.$this->enrollment->id.'/payments';
        $id = Payment::create(['payer_id' => $this->user->id, 'payable_type' => $this->enrollment->getMorphClass(),
            'payable_id' => $this->enrollment->id, 'amount' => 100, 'currency' => 'SYP', 'provider' => 'manual_transfer',
            'provider_reference' => 'FIRST', 'status' => 'pending'])->id;
        Sanctum::actingAs(User::factory()->create(['role' => 'company_admin', 'status' => 'active']));
        $this->postJson('/api/v1/admin/payments/'.$id.'/review', ['decision' => 'reject'])->assertOk()->assertJsonPath('data.payment.status', 'failed');
        Sanctum::actingAs($this->user);
        $this->postJson($url, ['provider_reference' => 'SECOND'])->assertStatus(410);
        $other = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/student/payments/'.$id)->assertForbidden();
        $this->postJson($url, ['provider_reference' => 'THIRD'])->assertForbidden();
    }
}
