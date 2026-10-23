<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Device;
use App\Models\DeviceTransferRequest;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\Session;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use App\Services\AvailabilityBooking;
use App\Services\DeviceBindings;
use App\Services\DeviceTransfers;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CriticalBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private function student(): array
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);

        return [$user, Student::create(['user_id' => $user->id, 'status' => 'active'])];
    }

    private function course(): array
    {
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Math', 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'status' => 'active', 'price' => 50]);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'Unit', 'status' => 'published', 'price' => 10]);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'type' => 'pdf', 'title' => 'Content', 'status' => 'published', 'price' => 5]);

        return [$user, $teacher, $course, $unit, $content];
    }

    private function enrollment(Student $student, Teacher $teacher, $item, array $extra = []): Enrollment
    {
        return Enrollment::create(array_replace(['student_id' => $student->id, 'teacher_id' => $teacher->id,
            'enrollable_type' => $item->getMorphClass(), 'enrollable_id' => $item->id, 'price' => 10, 'status' => 'active',
            'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(10)], $extra));
    }

    private function availability(Teacher $teacher): TeacherAvailability
    {
        return TeacherAvailability::create(['teacher_id' => $teacher->id, 'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00', 'timezone' => 'UTC', 'recurrence_type' => 'none', 'status' => 'active']);
    }

    private function interval(): array
    {
        return ['starts_at' => now()->addDay()->startOfDay()->addHours(10)->toIso8601String(),
            'ends_at' => now()->addDay()->startOfDay()->addHours(11)->toIso8601String()];
    }

    public function test_individual_requires_enrollment_and_forces_capacity_one(): void
    {
        [$studentUser,$student] = $this->student();
        [$teacherUser,$teacher,$course] = $this->course();
        Sanctum::actingAs($teacherUser);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'INDIVIDUAL', ...$this->interval()])->assertUnprocessable();
        $enrollment = $this->enrollment($student, $teacher, $course);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'INDIVIDUAL', 'enrollment_id' => $enrollment->id, 'capacity' => 7, ...$this->interval()])
            ->assertCreated()->assertJsonPath('data.capacity', 1)->assertJsonPath('data.enrollment_id', $enrollment->id);
    }

    public function test_group_and_type_conversion_validate_final_state(): void
    {
        [$user,$student] = $this->student();
        [$teacherUser,$teacher,$course] = $this->course();
        $group = Group::create(['teacher_subject_id' => $course->id, 'name' => 'Group', 'capacity' => 3, 'status' => 'active']);
        $enrollment = $this->enrollment($student, $teacher, $course);
        Sanctum::actingAs($teacherUser);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'GROUP', ...$this->interval()])->assertUnprocessable();
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'GROUP', 'group_id' => $group->id, 'enrollment_id' => $enrollment->id, ...$this->interval()])->assertUnprocessable();
        $id = $this->postJson('/api/v1/teacher/sessions', ['type' => 'GROUP', 'group_id' => $group->id, ...$this->interval()])->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/teacher/sessions/'.$id, ['type' => 'INDIVIDUAL', 'enrollment_id' => $enrollment->id])->assertUnprocessable();
        $this->patchJson('/api/v1/teacher/sessions/'.$id, ['type' => 'INDIVIDUAL', 'group_id' => null, 'enrollment_id' => $enrollment->id])->assertOk()->assertJsonPath('data.capacity', 1);
    }

    public function test_booking_creates_session_and_cancel_preserves_history_and_reopens_time(): void
    {
        [$user,$student] = $this->student();
        [, $teacher,$course] = $this->course();
        $enrollment = $this->enrollment($student, $teacher, $course);
        $availability = $this->availability($teacher);
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/v1/teacher-availabilities/'.$availability->id.'/book', ['enrollment_id' => $enrollment->id, ...$this->interval()])->assertCreated()->json('data.id');
        $slots = $this->getJson('/api/v1/teachers/'.$teacher->id.'/available-slots?from='.now()->addDay()->toDateString().'T10:00:00Z&to='.now()->addDay()->toDateString().'T12:00:00Z')->assertOk();
        $this->assertCount(1, $slots->json('data'));
        $this->assertStringContainsString('11:00:00', $slots->json('data.0.starts_at'));
        $this->deleteJson('/api/v1/sessions/'.$id.'/booking')->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.enrollment_id', $enrollment->id);
        $this->assertSame(1, Session::count());
        $this->postJson('/api/v1/teacher-availabilities/'.$availability->id.'/book', ['enrollment_id' => $enrollment->id, ...$this->interval()])->assertCreated();
    }

    public function test_booking_rejects_other_students_enrollment_and_duplicate_interval(): void
    {
        [$user,$student] = $this->student();
        [, $other] = $this->student();
        [, $teacher,$course] = $this->course();
        $own = $this->enrollment($student, $teacher, $course);
        $foreign = $this->enrollment($other, $teacher, $course);
        $a = $this->availability($teacher);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/teacher-availabilities/'.$a->id.'/book', ['enrollment_id' => $foreign->id, ...$this->interval()])->assertForbidden();
        $this->postJson('/api/v1/teacher-availabilities/'.$a->id.'/book', ['enrollment_id' => $own->id, ...$this->interval()])->assertCreated();
        $this->postJson('/api/v1/teacher-availabilities/'.$a->id.'/book', ['enrollment_id' => $own->id, ...$this->interval()])->assertConflict();
        $this->assertSame(1, Session::count());
    }

    public function test_booking_rejects_expired_future_and_outside_availability(): void
    {
        [$user,$student] = $this->student();
        [, $teacher,$course] = $this->course();
        $a = $this->availability($teacher);
        Sanctum::actingAs($user);
        foreach ([['status' => 'pending'], ['expires_at' => now()->subMinute()], ['starts_at' => now()->addDays(3)]] as $extra) {
            $e = $this->enrollment($student, $teacher, $course, $extra);
            $this->postJson('/api/v1/teacher-availabilities/'.$a->id.'/book', ['enrollment_id' => $e->id, ...$this->interval()])->assertUnprocessable();
        }
        $e = $this->enrollment($student, $teacher, $course);
        $this->postJson('/api/v1/teacher-availabilities/'.$a->id.'/book', ['enrollment_id' => $e->id, 'starts_at' => now()->addDay()->startOfDay()->addHours(9)->toIso8601String(), 'ends_at' => $this->interval()['ends_at']])->assertUnprocessable();
    }

    public function test_student_cannot_cancel_another_students_booking(): void
    {
        [$user,$student] = $this->student();
        [$otherUser,$other] = $this->student();
        [, $teacher,$course] = $this->course();
        $e = $this->enrollment($student, $teacher, $course);
        Sanctum::actingAs($user);
        $session = app(AvailabilityBooking::class)->book($student, $this->availability($teacher), ['enrollment_id' => $e->id, ...$this->interval()]);
        Sanctum::actingAs($otherUser);
        $this->deleteJson('/api/v1/sessions/'.$session->id.'/booking')->assertNotFound();
        $this->assertSame('scheduled', $session->fresh()->status);
    }

    public function test_group_membership_requires_exact_active_full_subject(): void
    {
        [, $student] = $this->student();
        [$teacherUser,$teacher,$course,$unit] = $this->course();
        $group = Group::create(['teacher_subject_id' => $course->id, 'name' => 'Group', 'capacity' => 5, 'status' => 'active']);
        Sanctum::actingAs($teacherUser);
        foreach ([[$unit, []], [$course, ['status' => 'pending']], [$course, ['expires_at' => now()->subMinute()]]] as [$item,$extra]) {
            $e = $this->enrollment($student, $teacher, $item, $extra);
            $this->postJson('/api/v1/teacher/groups/'.$group->id.'/enrollments', ['enrollment_id' => $e->id])->assertUnprocessable();
        }
        $e = $this->enrollment($student, $teacher, $course);
        $this->postJson('/api/v1/teacher/groups/'.$group->id.'/enrollments', ['enrollment_id' => $e->id])->assertCreated();
    }

    public function test_content_policy_covers_subject_unit_and_content_without_cross_scope_leak(): void
    {
        [$user,$student] = $this->student();
        [, $teacher,$course,$unit,$content] = $this->course();
        foreach ([$course, $unit, $content] as $item) {
            Enrollment::query()->delete();
            $this->enrollment($student, $teacher, $item);
            $this->assertTrue(Gate::forUser($user)->allows('access', $content));
        }
        [,,$otherCourse,,$otherContent] = $this->course();
        $this->assertFalse(Gate::forUser($user)->allows('access', $otherContent));
        $content->update(['status' => 'draft']);
        $this->assertFalse(Gate::forUser($user)->allows('access', $content));
    }

    public function test_expired_future_pending_and_suspended_never_unlock_content(): void
    {
        [$user,$student] = $this->student();
        [, $teacher,$course,,$content] = $this->course();
        foreach ([['status' => 'pending'], ['expires_at' => now()->subSecond()], ['starts_at' => now()->addDay()]] as $extra) {
            Enrollment::query()->delete();
            $this->enrollment($student, $teacher, $course, $extra);
            $this->assertFalse(Gate::forUser($user)->allows('access', $content));
        }
        Enrollment::query()->delete();
        $this->enrollment($student, $teacher, $course);
        $user->update(['status' => 'suspended']);
        $this->assertFalse(Gate::forUser($user->fresh())->allows('access', $content));
    }

    public function test_pending_subject_does_not_cover_unit_but_duplicate_subject_is_rejected(): void
    {
        [$user,$student] = $this->student();
        [, $teacher,$course,$unit] = $this->course();
        $this->enrollment($student, $teacher, $course, ['status' => 'pending']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/teacher-subjects/'.$course->id.'/enroll')->assertConflict();
        $this->postJson('/api/v1/units/'.$unit->id.'/enroll')->assertCreated();
    }

    public function test_quiz_and_completion_use_the_same_content_policy(): void
    {
        [$user,$student] = $this->student();
        [, $teacher,$course,,$content] = $this->course();
        $quiz = Quiz::create(['content_id' => $content->id, 'title' => 'Quiz', 'status' => 'published', 'attempts_allowed' => 2]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/quizzes/'.$quiz->id.'/data')->assertNotFound();
        $this->postJson('/api/v1/quizzes/'.$quiz->id.'/attempts')->assertNotFound();
        $this->postJson('/api/v1/student/contents/'.$content->id.'/complete')->assertNotFound();
        $this->enrollment($student, $teacher, $course);
        $this->getJson('/api/v1/quizzes/'.$quiz->id.'/data')->assertOk();
        $this->postJson('/api/v1/quizzes/'.$quiz->id.'/attempts')->assertCreated();
        $this->postJson('/api/v1/student/contents/'.$content->id.'/complete')->assertOk();
        $this->assertDatabaseCount('attendance', 0);
    }

    private function device(): Device
    {
        return Device::create(['device_uuid' => fake()->uuid(), 'platform' => 'android', 'status' => 'trusted']);
    }

    public function test_revoked_device_can_return_and_old_tokens_are_invalidated(): void
    {
        [$user,$student] = $this->student();
        $a = $this->device();
        $b = $this->device();
        $first = DB::transaction(fn () => app(DeviceBindings::class)->activate($student, $a));
        $token = $user->createToken('old')->plainTextToken;
        DB::transaction(fn () => app(DeviceBindings::class)->activate($student, $b));
        $this->assertSame('revoked', $first->fresh()->status);
        $this->assertSame(0, $user->tokens()->count());
        $back = DB::transaction(fn () => app(DeviceBindings::class)->activate($student, $a));
        $this->assertSame($first->id, $back->id);
        $this->assertSame(1, StudentDevice::where('student_id', $student->id)->where('status', 'active')->where('is_active', true)->count());
    }

    public function test_one_phone_can_bind_multiple_students_but_blocked_phone_is_rejected(): void
    {
        [, $a] = $this->student();
        [, $b] = $this->student();
        $phone = $this->device();
        DB::transaction(fn () => app(DeviceBindings::class)->activate($a, $phone));
        DB::transaction(fn () => app(DeviceBindings::class)->activate($b, $phone));
        $this->assertSame(2, StudentDevice::where('device_id', $phone->id)->where('status', 'active')->count());
        $phone->update(['status' => 'blocked']);
        $this->expectException(HttpException::class);
        DB::transaction(fn () => app(DeviceBindings::class)->activate($a, $phone));
    }

    public function test_database_rejects_two_active_devices_for_one_student(): void
    {
        [, $student] = $this->student();
        $a = $this->device();
        $b = $this->device();
        StudentDevice::create(['student_id' => $student->id, 'device_id' => $a->id, 'status' => 'active', 'is_active' => true]);
        $this->expectException(QueryException::class);
        StudentDevice::create(['student_id' => $student->id, 'device_id' => $b->id, 'status' => 'active', 'is_active' => true]);
    }

    public function test_transfer_waits_for_approval_then_auto_approves_at_72_hours(): void
    {
        [, $student] = $this->student();
        $a = $this->device();
        $b = $this->device();
        $source = DB::transaction(fn () => app(DeviceBindings::class)->activate($student, $a));
        $transfer = DeviceTransferRequest::create(['student_id' => $student->id, 'source_student_device_id' => $source->id, 'target_device_id' => $b->id,
            'token_hash' => str_repeat('a', 64), 'status' => 'pending', 'expires_at' => now()->addHours(72)]);
        $this->assertSame('pending', app(DeviceTransfers::class)->resolve($transfer->id)->status);
        $this->travel(72)->hours();
        $this->assertSame('auto_approved', app(DeviceTransfers::class)->resolve($transfer->id)->status);
        $this->assertSame('revoked', $source->fresh()->status);
    }

    public function test_student_cannot_double_book_different_teachers(): void
    {
        [$user,$student] = $this->student();
        [, $a,$ca] = $this->course();
        [, $b,$cb] = $this->course();
        $ea = $this->enrollment($student, $a, $ca);
        $eb = $this->enrollment($student, $b, $cb);
        Sanctum::actingAs($user);
        app(AvailabilityBooking::class)->book($student, $this->availability($a), ['enrollment_id' => $ea->id, ...$this->interval()]);
        $this->postJson('/api/v1/teacher-availabilities/'.$this->availability($b)->id.'/book', ['enrollment_id' => $eb->id, ...$this->interval()])->assertConflict();
        $this->assertSame(1, Session::count());
    }

    public function test_teacher_cannot_attach_another_teachers_enrollment_or_availability(): void
    {
        [, $student] = $this->student();
        [$user,$a,$ca] = $this->course();
        [, $b,$cb] = $this->course();
        $own = $this->enrollment($student, $a, $ca);
        $foreign = $this->enrollment($student, $b, $cb);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'INDIVIDUAL', 'enrollment_id' => $foreign->id, ...$this->interval()])->assertNotFound();
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'INDIVIDUAL', 'enrollment_id' => $own->id, 'availability_id' => $this->availability($b)->id, ...$this->interval()])->assertNotFound();
        $this->assertSame(0, Session::count());
    }

    public function test_same_teacher_other_subject_enrollment_cannot_join_group(): void
    {
        [, $student] = $this->student();
        [$user,$teacher,$course] = $this->course();
        $other = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => Subject::create(['name' => 'Other', 'status' => 'active'])->id, 'price' => 10, 'status' => 'active']);
        $group = Group::create(['teacher_subject_id' => $course->id, 'name' => 'Group', 'capacity' => 3, 'status' => 'active']);
        $e = $this->enrollment($student, $teacher, $other);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/teacher/groups/'.$group->id.'/enrollments', ['enrollment_id' => $e->id])->assertUnprocessable();
    }

    public function test_only_source_account_and_device_may_approve_transfer(): void
    {
        [$user,$student] = $this->student();
        [$otherUser] = $this->student();
        $a = $this->device();
        $b = $this->device();
        $source = DB::transaction(fn () => app(DeviceBindings::class)->activate($student, $a));
        $transfer = DeviceTransferRequest::create(['student_id' => $student->id, 'source_student_device_id' => $source->id, 'target_device_id' => $b->id, 'token_hash' => str_repeat('a', 64), 'status' => 'pending', 'expires_at' => now()->addHours(72)]);
        $token = $user->createToken('student_device_'.$source->id.'_session_1')->accessToken;
        $user->withAccessToken($token);
        $this->assertTrue(Gate::forUser($user)->allows('decide', $transfer));
        $this->assertFalse(Gate::forUser($otherUser)->allows('decide', $transfer));
        $user->withAccessToken($user->createToken('student_device_999_session_1')->accessToken);
        $this->assertFalse(Gate::forUser($user)->allows('decide', $transfer));
        $this->assertSame('pending', $transfer->fresh()->status);
    }

    public function test_group_session_must_be_covered_by_each_members_enrollment(): void
    {
        [, $student] = $this->student();
        [$user,$teacher,$course] = $this->course();
        $group = Group::create(['teacher_subject_id' => $course->id, 'name' => 'Group', 'capacity' => 3, 'status' => 'active']);
        $enrollment = $this->enrollment($student, $teacher, $course, ['expires_at' => now()->addHour()]);
        GroupEnrollment::create(['group_id' => $group->id, 'enrollment_id' => $enrollment->id, 'status' => 'active']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/teacher/sessions', ['type' => 'GROUP', 'group_id' => $group->id, ...$this->interval()])->assertUnprocessable();
        $this->assertDatabaseCount('sessions', 0);
    }
}
