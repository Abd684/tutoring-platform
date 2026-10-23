<?php

namespace Tests\Feature;

use App\Models\Answer;
use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Governortate;
use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Region;
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

class StudentReadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function student(): array
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);

        return [$user, Student::create(['user_id' => $user->id, 'status' => 'active', 'grade' => '10'])];
    }

    private function course(string $name = 'أحمد الشامي'): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'specialization' => 'اللغة العربية', 'status' => 'active']);
        $subject = Subject::create(['name' => 'اللغة العربية', 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'status' => 'active', 'price' => 30]);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'Unit', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'التشبيه وأركانه', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'title' => 'اختبار تدريبي', 'type' => 'quiz', 'status' => 'published']);
        $quiz = Quiz::create(['content_id' => $content->id, 'status' => 'published', 'attempts_allowed' => 3, 'duration_minutes' => 30]);

        return compact('user', 'teacher', 'subject', 'course', 'unit', 'lesson', 'content', 'quiz');
    }

    private function enroll(Student $student, array $fixture, array $extra = [], $item = null): Enrollment
    {
        $item ??= $fixture['course'];

        return Enrollment::create(array_replace(['student_id' => $student->id, 'teacher_id' => $fixture['teacher']->id, 'enrollable_type' => $item->getMorphClass(),
            'enrollable_id' => $item->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(7)], $extra));
    }

    private function window(array $fixture, string $status = 'active', string $timezone = 'UTC'): TeacherAvailability
    {
        return TeacherAvailability::create(['teacher_id' => $fixture['teacher']->id, 'date' => now()->addDay()->toDateString(),
            'start_time' => '10:00', 'end_time' => '12:00', 'timezone' => $timezone, 'recurrence_type' => 'none', 'status' => $status]);
    }

    private function calendar(array $fixture, array $params = []): string
    {
        return '/api/v1/teachers/'.$fixture['teacher']->id.'/calendar?'.http_build_query(array_replace([
            'from' => now()->addDay()->startOfDay()->addHours(10)->toIso8601String(),
            'to' => now()->addDay()->startOfDay()->addHours(12)->toIso8601String()], $params));
    }

    private function group(array $fixture, Student $student, array $extra = []): array
    {
        $group = Group::create(['teacher_subject_id' => $fixture['course']->id, 'name' => 'مجموعتي', 'capacity' => 5, 'status' => 'active']);
        $enrollment = $this->enroll($student, $fixture);
        $membership = GroupEnrollment::create(array_replace(['group_id' => $group->id, 'enrollment_id' => $enrollment->id, 'status' => 'active', 'joined_at' => now()], $extra));

        return [$group, $enrollment, $membership];
    }

    public function test_directory_lists_active_teachers_without_enrollments_and_has_no_private_fields(): void
    {
        $f = $this->course();
        $hidden = $this->course('Hidden');
        $hidden['user']->update(['status' => 'suspended']);
        $response = $this->getJson('/api/v1/teachers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['teacher']->id)
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'active');
        $this->assertSame(['id', 'name', 'specialization', 'avatar_url', 'status', 'region_id'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString($f['user']->email, $response->getContent());
        $this->getJson('/api/v1/teachers/directory?search='.urlencode('الشامي'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/teachers?search=does-not-exist')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_directory_filters_are_optional_explicit_and_validated(): void
    {
        $f = $this->course();
        $other = $this->course('Other');
        $province = Governortate::create(['name' => 'Province', 'status' => 'active']);
        $region = Region::create(['governortate_id' => $province->id, 'name' => 'Region', 'status' => 'active']);
        $f['user']->update(['region_id' => $region->id]);
        $this->getJson('/api/v1/teachers?region_id='.$region->id.'&subject_id='.$f['subject']->id.'&specialization='.urlencode('العربية'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/teachers?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/teachers?grade=10')->assertUnprocessable()->assertJsonValidationErrors('grade');
        $this->getJson('/api/v1/teachers?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/teachers?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/teachers', [])->assertStatus(405);
    }

    public function test_featured_is_separate_from_full_directory_and_supports_empty_results(): void
    {
        $f = $this->course();
        $this->getJson('/api/v1/teachers/featured')->assertOk()->assertJsonPath('data.0.active_students_count', 0);
        $f['teacher']->update(['status' => 'suspended']);
        $this->getJson('/api/v1/teachers/featured')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/teachers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_calendar_separates_booked_and_bookable_and_never_exposes_other_student(): void
    {
        [$user,$student] = $this->student();
        [$otherUser,$other] = $this->student();
        $f = $this->course();
        $this->enroll($student, $f);
        $e = $this->enroll($other, $f);
        $a = $this->window($f);
        $session = Session::create(['teacher_id' => $f['teacher']->id, 'availability_id' => $a->id, 'enrollment_id' => $e->id, 'type' => 'INDIVIDUAL', 'capacity' => 1,
            'starts_at' => now()->addDay()->startOfDay()->addHours(10), 'ends_at' => now()->addDay()->startOfDay()->addHours(11), 'status' => 'scheduled']);
        Sanctum::actingAs($user);
        $response = $this->getJson($this->calendar($f))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.reason', 'booked')->assertJsonPath('data.0.bookable', false)
            ->assertJsonPath('data.0.session_id', null)->assertJsonPath('data.1.reason', 'available')->assertJsonPath('data.1.availability_id', $a->id);
        $this->assertStringNotContainsString($otherUser->email, $response->getContent());
        foreach ($response->json('data') as $period) {
            $this->assertArrayNotHasKey('student_id', $period);
            $this->assertArrayNotHasKey('enrollment_id', $period);
        }
        $this->getJson($this->calendar($f, ['per_page' => 1, 'page' => 2]))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.reason', 'available');
    }

    public function test_calendar_distinguishes_no_eligibility_closed_and_outside_schedule(): void
    {
        [$user] = $this->student();
        $f = $this->course();
        $a = $this->window($f);
        Sanctum::actingAs($user);
        $this->getJson($this->calendar($f))->assertOk()->assertJsonPath('data.0.reason', 'not_eligible')->assertJsonPath('meta.has_schedule', true)->assertJsonPath('meta.has_eligible_enrollment_in_period', false);
        $a->update(['status' => 'disabled']);
        $this->getJson($this->calendar($f))->assertOk()->assertJsonPath('data.0.reason', 'closed');
        $a->delete();
        $this->getJson($this->calendar($f))->assertOk()->assertJsonPath('data.0.reason', 'outside_schedule')->assertJsonPath('meta.has_schedule', false);
    }

    public function test_calendar_handles_fully_booked_day_and_eligibility_boundaries(): void
    {
        [$user,$student] = $this->student();
        $f = $this->course();
        $a = $this->window($f);
        $e = $this->enroll($student, $f, ['expires_at' => now()->addDay()->startOfDay()->addHours(11)]);
        Sanctum::actingAs($user);
        $this->getJson($this->calendar($f))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.bookable', true)->assertJsonPath('data.1.reason', 'not_eligible');
        Session::create(['teacher_id' => $f['teacher']->id, 'availability_id' => $a->id, 'enrollment_id' => $e->id, 'type' => 'INDIVIDUAL', 'capacity' => 1,
            'starts_at' => now()->addDay()->startOfDay()->addHours(10), 'ends_at' => now()->addDay()->startOfDay()->addHours(12), 'status' => 'scheduled']);
        $response = $this->getJson($this->calendar($f))->assertOk();
        foreach ($response->json('data') as $period) {
            $this->assertSame('booked', $period['reason']);
            $this->assertFalse($period['bookable']);
        }
    }

    public function test_calendar_timezone_and_range_contract(): void
    {
        [$user,$student] = $this->student();
        $f = $this->course();
        $this->window($f, 'active', 'Asia/Damascus');
        $this->enroll($student, $f);
        Sanctum::actingAs($user);
        $date = now()->addDay()->toDateString();
        $response = $this->getJson($this->calendar($f, ['from' => $date.'T07:00:00Z', 'to' => $date.'T09:00:00Z']))->assertOk()->assertJsonPath('data.0.reason', 'available');
        $this->assertStringContainsString('07:00:00', $response->json('data.0.starts_at'));
        $this->getJson($this->calendar($f, ['from' => $date.'T07:00:00Z', 'to' => $date.'T09:00:00Z', 'timezone' => 'Asia/Damascus']))->assertOk()->assertJsonPath('data.0.timezone', 'Asia/Damascus');
        $this->getJson($this->calendar($f, ['to' => now()->addDays(40)->toIso8601String()]))->assertUnprocessable();
        $this->getJson($this->calendar($f, ['timezone' => 'Invalid/Zone']))->assertUnprocessable();
    }

    public function test_published_quiz_ids_and_counts_are_consistent_and_fallback_is_protected(): void
    {
        [$user,$student] = $this->student();
        $f = $this->course();
        Quiz::create(['content_id' => $f['content']->id, 'status' => 'draft', 'attempts_allowed' => 1]);
        $this->getJson('/api/v1/lessons/'.$f['lesson']->id.'/contents')->assertOk()->assertJsonPath('data.0.quizzes_count', 1)->assertJsonPath('data.0.quizzes.0.id', $f['quiz']->id)->assertJsonCount(1, 'data.0.quizzes');
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/contents/'.$f['content']->id.'/quizzes')->assertNotFound();
        $this->enroll($student, $f);
        $this->getJson('/api/v1/contents/'.$f['content']->id.'/quizzes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['quiz']->id);
        $f['quiz']->update(['status' => 'draft']);
        $this->getJson('/api/v1/contents/'.$f['content']->id.'/quizzes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/lessons/'.$f['lesson']->id.'/contents')->assertOk()->assertJsonPath('data.0.quizzes_count', 0)->assertJsonCount(0, 'data.0.quizzes');
    }

    public function test_my_quizzes_scopes_attempts_and_pending_grades_to_authenticated_student(): void
    {
        [$user,$student] = $this->student();
        [, $other] = $this->student();
        $f = $this->course();
        $this->enroll($student, $f);
        $foreign = $this->course('Other');
        $attempt = QuizAttempt::create(['quiz_id' => $f['quiz']->id, 'student_id' => $student->id, 'started_at' => now()->subMinutes(10), 'submitted_at' => now(), 'status' => 'submitted', 'score' => 0]);
        $question = Question::create(['quiz_id' => $f['quiz']->id, 'body' => 'Q', 'type' => 'mcq', 'points' => 1]);
        Answer::create(['attempt_id' => $attempt->id, 'question_id' => $question->id, 'status' => 'pending', 'score' => null]);
        $otherAttempt = QuizAttempt::create(['quiz_id' => $f['quiz']->id, 'student_id' => $other->id, 'started_at' => now()->subMinutes(5), 'submitted_at' => now(), 'status' => 'graded', 'score' => 99]);
        QuizAttempt::create(['quiz_id' => $foreign['quiz']->id, 'student_id' => $other->id, 'started_at' => now()->subMinutes(5), 'submitted_at' => now(), 'status' => 'graded', 'score' => 55]);
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/student/my-quizzes?student_id='.$other->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quiz_id', $f['quiz']->id)
            ->assertJsonPath('data.0.attempts_used', 1)->assertJsonPath('data.0.attempts_remaining', 2)->assertJsonPath('data.0.latest_attempt.grading_pending', true)->assertJsonPath('data.0.latest_attempt.score', null);
        $this->getJson('/api/v1/student/my-quizzes/'.$f['quiz']->id.'/attempts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.attempt_id', $attempt->id);
        $this->getJson('/api/v1/student/my-quizzes/'.$foreign['quiz']->id.'/attempts')->assertNotFound();
        $this->getJson('/api/v1/student/my-quizzes?status=pending_grading')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_my_quizzes_retains_only_own_history_after_expiry_or_archive(): void
    {
        [$user,$student] = $this->student();
        $f = $this->course();
        $e = $this->enroll($student, $f);
        $attempt = QuizAttempt::create(['quiz_id' => $f['quiz']->id, 'student_id' => $student->id, 'started_at' => now()->subMinutes(5), 'submitted_at' => now(), 'status' => 'graded', 'score' => 0]);
        $e->update(['expires_at' => now()->subSecond()]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/student/my-quizzes?scope=available')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student/my-quizzes?scope=history')->assertOk()->assertJsonPath('data.0.can_access', false)->assertJsonPath('data.0.latest_attempt.score', 0)->assertJsonPath('data.0.latest_attempt.grading_pending', false);
        $f['quiz']->update(['status' => 'archived']);
        $this->getJson('/api/v1/student/my-quizzes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_start', false);
        $this->getJson('/api/v1/quizzes/'.$f['quiz']->id.'/data')->assertNotFound();
    }

    public function test_my_quizzes_reports_expired_active_attempt_and_validates_filters(): void
    {
        [$user,$student] = $this->student();
        $f = $this->course();
        $this->enroll($student, $f);
        QuizAttempt::create(['quiz_id' => $f['quiz']->id, 'student_id' => $student->id, 'status' => 'in_progress', 'started_at' => now()->subHour()]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/student/my-quizzes?status=in_progress')->assertOk()->assertJsonPath('data.0.active_attempt.time_expired', true)->assertJsonPath('data.0.can_resume', false);
        $this->getJson('/api/v1/student/my-quizzes?status=not_started')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student/my-quizzes?status=unknown')->assertUnprocessable();
    }

    public function test_group_list_is_own_only_and_members_are_minimal_and_deduplicated(): void
    {
        [$user,$student] = $this->student();
        [$otherUser,$other] = $this->student();
        $f = $this->course();
        [$group] = $this->group($f, $student);
        [$foreign] = $this->group($f, $other);
        $otherEnrollment = $this->enroll($other, $f);
        GroupEnrollment::create(['group_id' => $group->id, 'enrollment_id' => $otherEnrollment->id, 'status' => 'active']);
        $duplicate = $this->enroll($other, $f);
        GroupEnrollment::create(['group_id' => $group->id, 'enrollment_id' => $duplicate->id, 'status' => 'active']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/student/groups?student_id='.$other->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.group_id', $group->id)->assertJsonPath('data.0.active_members_count', 2)->assertJsonPath('data.0.remaining_seats', 3);
        $response = $this->getJson('/api/v1/student/groups/'.$group->id.'/members')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['member_id', 'display_name', 'avatar_url'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString($otherUser->email, $response->getContent());
        $this->assertStringNotContainsString($otherUser->phone, $response->getContent());
        $this->getJson('/api/v1/student/groups/'.$foreign->id.'/members')->assertNotFound();
    }

    public function test_expired_left_and_closed_groups_are_history_without_member_access(): void
    {
        [$user,$student] = $this->student();
        $f = $this->course();
        [$group,$enrollment,$membership] = $this->group($f, $student);
        Sanctum::actingAs($user);
        $enrollment->update(['expires_at' => now()->subSecond()]);
        $this->getJson('/api/v1/student/groups')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student/groups?scope=history')->assertOk()->assertJsonPath('data.0.membership_status', 'expired')->assertJsonPath('data.0.can_view_members', false);
        $this->getJson('/api/v1/student/groups/'.$group->id.'/members')->assertNotFound();
        $enrollment->update(['expires_at' => now()->addDay()]);
        $membership->update(['status' => 'left']);
        $this->getJson('/api/v1/student/groups?scope=all')->assertOk()->assertJsonPath('data.0.membership_status', 'left');
        $membership->update(['status' => 'active']);
        $group->update(['status' => 'closed']);
        $this->getJson('/api/v1/student/groups')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student/groups?scope=history')->assertOk()->assertJsonPath('data.0.status', 'closed');
        $this->getJson('/api/v1/student/groups/'.$group->id.'/members')->assertNotFound();
    }

    public function test_read_endpoints_require_active_student_and_do_not_allow_crud(): void
    {
        $f = $this->course();
        $urls = ['/api/v1/student/my-quizzes', '/api/v1/student/groups', $this->calendar($f)];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        Sanctum::actingAs($f['user']);
        foreach ($urls as $url) {
            $this->getJson($url)->assertForbidden();
        }
        [$user,$student] = $this->student();
        $student->update(['status' => 'suspended']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/student/groups')->assertForbidden();
    }
}
