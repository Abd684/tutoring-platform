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
use Illuminate\Support\Facades\DB;
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
        $this->enrollment = Enrollment::create(['student_id' => $this->student->id, 'teacher_id' => $this->teacher->id, 'enrollable_type' => (new TeacherSubject)->getMorphClass(), 'enrollable_id' => $this->course->id, 'price' => 100, 'status' => 'active']);
        Sanctum::actingAs($this->user);
    }

    private function makeSession(array $extra = []): Session
    {
        return Session::create(array_merge(['teacher_id' => $this->teacher->id,
            'type' => 'INDIVIDUAL', 'enrollment_id' => $this->enrollment->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'scheduled'], $extra));
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
        $group = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'Test', 'capacity' => 10, 'status' => 'active']);
        GroupEnrollment::create(['group_id' => $group->id, 'enrollment_id' => $this->enrollment->id, 'status' => 'active']);
        $groupSession = $this->makeSession(['type' => 'GROUP', 'enrollment_id' => null, 'group_id' => $group->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
        $this->getJson('/api/v1/student/bookings?student_id=999')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.0.teacher.id', $this->teacher->id)
            ->assertJsonPath('data.0.enrollment.id', $this->enrollment->id)
            ->assertJsonPath('data.1.id', $groupSession->id)->assertJsonPath('data.1.enrollment.id', $this->enrollment->id);
    }

    private function availability(): TeacherAvailability
    {
        return TeacherAvailability::create(['teacher_id' => $this->teacher->id,
            'date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00',
            'recurrence_type' => 'none', 'timezone' => 'UTC', 'status' => 'active']);
    }

    private function bookingBody($availability): array
    {
        return ['availability_id' => $availability->id, 'enrollment_id' => $this->enrollment->id,
            'starts_at' => now()->addDay()->setTime(10, 0)->toIso8601String(),
            'ends_at' => now()->addDay()->setTime(11, 0)->toIso8601String()];
    }

    public function test_available_booking_cancellation_and_expiration(): void
    {
        $availability = $this->availability();
        $this->assertDatabaseCount('sessions', 0);
        $this->getJson($this->slots())->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.availability_id', $availability->id);
        $url = '/api/v1/teachers/'.$this->teacher->id.'/bookings';
        $id = $this->postJson($url, $this->bookingBody($availability))->assertCreated()->json('data.id');
        $this->getJson($this->slots())->assertJsonPath('meta.total', 0);
        $this->deleteJson('/api/v1/sessions/'.$id.'/booking')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.enrollment_id', $this->enrollment->id);
        $this->getJson($this->slots())->assertJsonPath('meta.total', 1);
        $this->enrollment->update(['expires_at' => now()->addHour()]);
        $this->getJson($this->slots())->assertJsonPath('meta.total', 0);
        $this->postJson($url, $this->bookingBody($availability))->assertUnprocessable();
    }

    public function test_booking_rejects_overlap_across_different_enrollments(): void
    {
        $availability = $this->availability();
        $other = $this->enrollment->replicate();
        $other->save();
        $body = $this->bookingBody($availability);
        $this->makeSession(['enrollment_id' => $other->id, 'starts_at' => $body['starts_at'], 'ends_at' => $body['ends_at']]);
        $this->getJson($this->slots())->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/teachers/'.$this->teacher->id.'/bookings', $body)->assertUnprocessable();
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
        $this->getJson('/api/v1/student/payments/'.$id)->assertForbidden();
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

    public function test_store_and_partial_updates_enforce_final_session_shape(): void
    {
        Sanctum::actingAs($this->teacher->user);
        $group = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'A', 'capacity' => 5, 'status' => 'active']);
        $times = ['starts_at' => now()->addDays(5)->toISOString(), 'ends_at' => now()->addDays(5)->addHour()->toISOString()];
        foreach ([['type' => 'GROUP'], ['type' => 'GROUP', 'group_id' => $group->id, 'enrollment_id' => $this->enrollment->id],
            ['type' => 'INDIVIDUAL'], ['type' => 'INDIVIDUAL', 'group_id' => $group->id, 'enrollment_id' => $this->enrollment->id]] as $invalid) {
            $this->postJson('/api/v1/teacher/sessions', array_merge($times, $invalid))->assertUnprocessable();
        }
        $id = $this->postJson('/api/v1/teacher/sessions', array_merge($times, ['type' => 'GROUP', 'group_id' => $group->id]))->assertCreated()->json('data.id');
        $url = '/api/v1/teacher/sessions/'.$id;
        $this->patchJson($url, ['type' => 'INDIVIDUAL'])->assertUnprocessable();
        $this->patchJson($url, ['type' => 'INDIVIDUAL', 'group_id' => null, 'enrollment_id' => $this->enrollment->id, 'capacity' => 9])
            ->assertOk()->assertJsonPath('data.capacity', 1)->assertJsonPath('data.group_id', null);
        $this->patchJson($url, ['type' => 'GROUP'])->assertUnprocessable();
        $this->patchJson($url, ['type' => 'GROUP', 'group_id' => $group->id, 'enrollment_id' => null])->assertOk();
        $this->patchJson($url, ['group_id' => null])->assertUnprocessable();
        $this->postJson('/api/v1/teacher/sessions', array_merge($times, ['type' => 'GROUP', 'group_id' => $group->id]))->assertUnprocessable();
    }

    public function test_booking_rejects_invalid_availability_enrollment_and_teacher(): void
    {
        $availability = $this->availability();
        $body = $this->bookingBody($availability);
        $url = '/api/v1/teachers/'.$this->teacher->id.'/bookings';
        $this->postJson($url, array_merge($body, ['availability_id' => 999999]))->assertUnprocessable();
        $this->postJson($url, array_merge($body, ['starts_at' => now()->addDay()->setTime(9, 0)->toIso8601String()]))->assertUnprocessable();
        foreach (['pending', 'expired'] as $status) {
            $this->enrollment->update(['status' => $status]);
            $this->postJson($url, $body)->assertUnprocessable();
        }
        $this->enrollment->update(['status' => 'active', 'starts_at' => now()->addDays(2)]);
        $this->postJson($url, $body)->assertUnprocessable();
        $this->enrollment->update(['starts_at' => null]);
        $otherUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $otherStudent = Student::create(['user_id' => $otherUser->id, 'status' => 'active']);
        $this->enrollment->update(['student_id' => $otherStudent->id]);
        $this->postJson($url, $body)->assertForbidden();
        $this->enrollment->update(['student_id' => $this->student->id]);
        $this->teacher->update(['status' => 'suspended']);
        $this->postJson($url, $body)->assertUnprocessable();
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_booking_rejects_student_overlap_with_a_different_teacher_and_duplicate_booking(): void
    {
        $availability = $this->availability();
        $body = $this->bookingBody($availability);
        $otherTeacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher', 'status' => 'active'])->id, 'status' => 'active']);
        $otherEnrollment = $this->enrollment->replicate();
        $otherEnrollment->teacher_id = $otherTeacher->id;
        $otherEnrollment->save();
        $session = $this->makeSession(['teacher_id' => $otherTeacher->id, 'enrollment_id' => $otherEnrollment->id,
            'starts_at' => $body['starts_at'], 'ends_at' => $body['ends_at']]);
        $url = '/api/v1/teachers/'.$this->teacher->id.'/bookings';
        $this->postJson($url, $body)->assertUnprocessable();
        $session->update(['status' => 'cancelled']);
        $id = $this->postJson($url, $body)->assertCreated()->json('data.id');
        $this->postJson($url, $body)->assertUnprocessable();
        $this->getJson('/api/v1/student/sessions/'.$id)->assertOk();
        Sanctum::actingAs(User::find($otherTeacher->user_id));
        $this->postJson($url, $body)->assertForbidden();
        Sanctum::actingAs($this->teacher->user);
        $this->patchJson('/api/v1/teacher/sessions/'.$session->id, ['capacity' => 2])->assertNotFound();
    }

    public function test_group_rejects_unit_content_and_other_subject_enrollments(): void
    {
        $group = Group::create(['teacher_subject_id' => $this->course->id, 'name' => 'A', 'capacity' => 5, 'status' => 'active']);
        Sanctum::actingAs($this->teacher->user);
        $url = '/api/v1/teacher/groups/'.$group->id.'/enrollments';
        $this->enrollment->update(['enrollable_type' => 'unit', 'enrollable_id' => $this->lesson->unit_id]);
        $this->postJson($url, ['enrollment_id' => $this->enrollment->id])->assertUnprocessable();
        $this->enrollment->update(['enrollable_type' => 'content', 'enrollable_id' => 9999]);
        $this->postJson($url, ['enrollment_id' => $this->enrollment->id])->assertUnprocessable();
        $this->enrollment->update(['enrollable_type' => 'teacher_subject', 'enrollable_id' => 9999]);
        $this->postJson($url, ['enrollment_id' => $this->enrollment->id])->assertUnprocessable();
        $this->enrollment->update(['enrollable_id' => $this->course->id]);
        $this->postJson($url, ['enrollment_id' => $this->enrollment->id])->assertCreated();
    }

    public function test_weekly_availability_uses_teacher_timezone_and_booking_normalizes_time(): void
    {
        $day = now()->addDays(3)->setTimezone('Asia/Damascus');
        $availability = TeacherAvailability::create(['teacher_id' => $this->teacher->id,
            'weekday' => $day->dayOfWeek, 'start_time' => '10:00', 'end_time' => '11:00',
            'recurrence_type' => 'weekly', 'timezone' => 'Asia/Damascus', 'status' => 'active']);
        $body = ['availability_id' => $availability->id, 'enrollment_id' => $this->enrollment->id,
            'starts_at' => $day->copy()->setTime(10, 0)->toIso8601String(), 'ends_at' => $day->copy()->setTime(11, 0)->toIso8601String()];
        $url = '/api/v1/teachers/'.$this->teacher->id.'/bookings';
        $id = $this->postJson($url, $body)->assertCreated()->json('data.id');
        $this->assertSame($day->copy()->setTime(10, 0)->utc()->format('Y-m-d H:i:s'), Session::findOrFail($id)->getRawOriginal('starts_at'));
        $this->postJson($url, $body)->assertUnprocessable();
        $range = '?from='.urlencode($day->copy()->startOfDay()->utc()->toIso8601String()).'&to='.urlencode($day->copy()->endOfDay()->utc()->toIso8601String());
        $this->getJson($this->slots().$range)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_anonymous_and_non_owners_cannot_book_read_or_cancel(): void
    {
        $session = $this->makeSession();
        $other = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/student/sessions/'.$session->id)->assertNotFound();
        $this->deleteJson('/api/v1/sessions/'.$session->id.'/booking')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/student/sessions/'.$session->id)->assertUnauthorized();
        $this->postJson('/api/v1/teachers/'.$this->teacher->id.'/bookings', [])->assertUnauthorized();
    }

    public function test_legacy_empty_slots_are_archived_and_converted_to_availability(): void
    {
        $migration = require database_path('migrations/2026_10_16_000002_convert_legacy_session_slots.php');
        $migration->down();
        $id = DB::table('sessions')->insertGetId(['teacher_id' => $this->teacher->id, 'type' => 'INDIVIDUAL',
            'starts_at' => now()->addDay()->setTime(10, 0), 'ends_at' => now()->addDay()->setTime(11, 0), 'status' => 'scheduled']);
        $migration->up();
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
        $this->assertDatabaseHas('legacy_session_slots', ['id' => $id]);
        $this->getJson($this->slots())->assertOk()->assertJsonPath('meta.total', 1);
        $migration->down();
        $this->assertDatabaseHas('sessions', ['id' => $id, 'enrollment_id' => null]);
        // Restore the migrated schema for RefreshDatabase's shared connection.
        $migration->up();
    }
}
