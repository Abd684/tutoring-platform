<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LearningCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $user->id, 'status' => 'active']);
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Learning test', 'status' => 'active']);
        $offering = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'price' => 10, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $offering->id, 'title' => 'Unit', 'status' => 'published']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson', 'status' => 'published']);
        $content = Content::create(['lesson_id' => $lesson->id, 'title' => 'Video', 'type' => 'video', 'status' => 'published']);
        $otherContent = Content::create(['lesson_id' => $lesson->id, 'title' => 'PDF', 'type' => 'pdf', 'status' => 'published']);
        $enrollment = Enrollment::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollable_type' => TeacherSubject::class, 'enrollable_id' => $offering->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addDay()]);
        Sanctum::actingAs($user);

        return compact('user', 'student', 'teacherUser', 'teacher', 'offering', 'unit', 'lesson', 'content', 'otherContent', 'enrollment');
    }

    public function test_completion_is_explicit_idempotent_and_scoped_to_student(): void
    {
        extract($this->fixture());
        $url = "/api/v1/student/teacher-subjects/{$offering->id}/progress";
        $this->getJson("/api/v1/contents/{$content->id}")->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.progress_percentage', 0);
        $completedAt = $this->postJson("/api/v1/student/contents/{$content->id}/complete", ['student_id' => 999])
            ->assertOk()->assertJsonPath('data.completed', true)->json('data.completed_at');
        $this->postJson("/api/v1/student/contents/{$content->id}/complete")
            ->assertOk()->assertJsonPath('data.completed_at', $completedAt);
        $this->assertDatabaseCount('content_completions', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('data.total_contents', 2)
            ->assertJsonPath('data.completed_contents', 1)->assertJsonPath('data.progress_percentage', 50);
        $this->assertDatabaseCount('attendance', 0);
        $otherUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $otherUser->id, 'status' => 'active']);
        Sanctum::actingAs($otherUser);
        $this->getJson($url)->assertNotFound();
        $this->postJson("/api/v1/student/contents/{$content->id}/complete")->assertNotFound();
    }

    public function test_expired_pending_and_draft_content_are_rejected(): void
    {
        extract($this->fixture());
        $url = "/api/v1/student/contents/{$content->id}/complete";
        $enrollment->update(['expires_at' => now()->subMinute()]);
        $this->postJson($url)->assertNotFound();
        $enrollment->update(['expires_at' => null, 'status' => 'pending']);
        $this->postJson($url)->assertNotFound();
        $enrollment->update(['status' => 'active']);
        $content->update(['status' => 'draft']);
        $this->postJson($url)->assertNotFound();
    }

    public function test_unit_and_content_enrollments_and_empty_progress(): void
    {
        extract($this->fixture());
        foreach ([[Unit::class, $unit->id], [Content::class, $content->id]] as [$type, $id]) {
            $enrollment->update(['enrollable_type' => $type, 'enrollable_id' => $id]);
            $this->postJson("/api/v1/student/contents/{$content->id}/complete")->assertOk();
            $this->getJson("/api/v1/student/teacher-subjects/{$offering->id}/progress")->assertOk();
        }
        $this->postJson("/api/v1/student/contents/{$otherContent->id}/complete")->assertNotFound();
        $content->update(['status' => 'archived']);
        $otherContent->update(['status' => 'archived']);
        $this->getJson("/api/v1/student/teacher-subjects/{$offering->id}/progress")
            ->assertOk()->assertJsonPath('data.total_contents', 0)->assertJsonPath('data.progress_percentage', 0);
    }

    public function test_avatar_lifecycle_for_student_and_teacher(): void
    {
        extract($this->fixture());
        Storage::fake('public');
        foreach ([$user, $teacherUser] as $account) {
            Sanctum::actingAs($account);
            $this->assertNull($account->avatar_url);
            $firstUrl = $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('avatar.png')], ['Accept' => 'application/json'])
                ->assertOk()->json('data.avatar_url');
            $oldPath = $account->fresh()->avatar_path;
            $this->get($firstUrl)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $newUrl = $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('new.jpg')], ['Accept' => 'application/json'])
                ->assertOk()->json('data.avatar_url');
            $this->assertNotEquals($firstUrl, $newUrl);
            Storage::disk('public')->assertMissing($oldPath);
            $this->get($firstUrl)->assertNotFound();
            $this->deleteJson('/api/v1/profile/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
            $this->get($newUrl)->assertNotFound();
            $this->deleteJson('/api/v1/profile/avatar')->assertOk();
        }
    }

    public function test_invalid_avatars_and_inactive_accounts_are_rejected(): void
    {
        extract($this->fixture());
        Storage::fake('public');
        $this->postJson('/api/v1/profile/avatar')->assertUnprocessable();
        $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('large.jpg')->size(5121)], ['Accept' => 'application/json'])->assertUnprocessable();
        $user->update(['status' => 'suspended']);
        Sanctum::actingAs($user);
        $this->deleteJson('/api/v1/profile/avatar')->assertForbidden();
    }

    public function test_quiz_ids_submission_ownership_repeat_and_answer_lock(): void
    {
        extract($this->fixture());
        $quiz = Quiz::create(['content_id' => $content->id, 'status' => 'published', 'attempts_allowed' => 2]);
        $question = Question::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'body' => 'Question', 'points' => 2]);
        $this->getJson("/api/v1/lessons/{$lesson->id}/contents")->assertOk()->assertJsonPath('data.0.quizzes.0.id', $quiz->id);
        $this->getJson("/api/v1/contents/{$content->id}")->assertOk()->assertJsonPath('data.quizzes.0.id', $quiz->id);
        $attempt = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertCreated()->json('data.attempt_id');
        $this->postJson("/api/v1/quiz-attempts/{$attempt}/answers", ['question_id' => $question->id, 'answer_text' => 'answer'])->assertCreated();
        $result = $this->postJson("/api/v1/quiz-attempts/{$attempt}/submit", ['score' => 999])
            ->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.score', null)->assertJsonPath('data.grading_pending', true);
        $this->postJson("/api/v1/quiz-attempts/{$attempt}/submit")->assertOk()->assertJsonPath('data.submitted_at', $result->json('data.submitted_at'));
        $this->postJson("/api/v1/quiz-attempts/{$attempt}/answers", ['question_id' => $question->id, 'answer_text' => 'changed'])->assertUnprocessable();
        Sanctum::actingAs($teacherUser);
        $this->postJson("/api/v1/quiz-attempts/{$attempt}/submit")->assertForbidden();
        $other = User::factory()->create(['role' => 'student', 'status' => 'active']);
        Student::create(['user_id' => $other->id, 'status' => 'active']);
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/quiz-attempts/{$attempt}/submit")->assertNotFound();
    }

    public function test_expired_attempt_can_be_submitted_without_accepting_late_answers(): void
    {
        extract($this->fixture());
        $quiz = Quiz::create(['content_id' => $content->id, 'status' => 'published', 'duration_minutes' => 1, 'attempts_allowed' => 1]);
        $question = Question::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'body' => 'Q', 'points' => 2]);
        $attempt = QuizAttempt::create(['quiz_id' => $quiz->id, 'student_id' => $student->id, 'started_at' => now()->subMinutes(2), 'status' => 'in_progress']);
        $this->postJson("/api/v1/quiz-attempts/{$attempt->id}/answers", ['question_id' => $question->id, 'answer_text' => 'late'])->assertUnprocessable();
        $this->postJson("/api/v1/quiz-attempts/{$attempt->id}/submit")->assertOk()->assertJsonPath('data.score', 0)->assertJsonPath('data.grading_pending', false);
    }

    public function test_featured_teachers_count_distinct_active_students(): void
    {
        extract($this->fixture());
        Enrollment::create(['student_id' => $student->id, 'teacher_id' => $teacher->id, 'enrollable_type' => Content::class, 'enrollable_id' => $content->id, 'status' => 'active']);
        $pendingUser = User::factory()->create(['role' => 'student']);
        $pendingStudent = Student::create(['user_id' => $pendingUser->id]);
        Enrollment::create(['student_id' => $pendingStudent->id, 'teacher_id' => $teacher->id, 'enrollable_type' => Content::class, 'enrollable_id' => $content->id, 'status' => 'pending']);
        $this->getJson('/api/v1/teachers/featured?per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $teacher->id)->assertJsonPath('data.0.active_students_count', 1)
            ->assertJsonPath('data.0.avatar_url', null)->assertJsonPath('meta.per_page', 1);
        $teacher->update(['status' => 'suspended']);
        $this->getJson('/api/v1/teachers/featured')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_authentication_is_required(): void
    {
        $this->postJson('/api/v1/profile/avatar')->assertUnauthorized();
        $this->deleteJson('/api/v1/profile/avatar')->assertUnauthorized();
        $this->postJson('/api/v1/student/contents/999/complete')->assertUnauthorized();
        $this->getJson('/api/v1/student/teacher-subjects/999/progress')->assertUnauthorized();
        $this->postJson('/api/v1/quiz-attempts/999/submit')->assertUnauthorized();
    }

    public function test_featured_order_excludes_expired_and_future_enrollments(): void
    {
        extract($this->fixture());
        $otherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $otherTeacher = Teacher::create(['user_id' => $otherUser->id, 'status' => 'active']);
        foreach ([0, 1] as $index) {
            $sUser = User::factory()->create(['role' => 'student']);
            $s = Student::create(['user_id' => $sUser->id, 'status' => 'active']);
            Enrollment::create(['student_id' => $s->id, 'teacher_id' => $otherTeacher->id, 'enrollable_type' => Content::class, 'enrollable_id' => $content->id, 'status' => 'active']);
            Enrollment::create(['student_id' => $s->id, 'teacher_id' => $teacher->id, 'enrollable_type' => Content::class, 'enrollable_id' => $content->id, 'status' => 'active', 'expires_at' => now()->subMinute()]);
        }
        $this->getJson('/api/v1/teachers/featured')->assertOk()->assertJsonPath('data.0.id', $otherTeacher->id)
            ->assertJsonPath('data.0.active_students_count', 2)->assertJsonPath('data.1.active_students_count', 1);
        $enrollment->update(['starts_at' => now()->addDay()]);
        $this->getJson('/api/v1/teachers/featured')->assertOk()->assertJsonPath('data.1.active_students_count', 0);
    }

    public function test_existing_profile_and_teacher_responses_include_avatar(): void
    {
        extract($this->fixture());
        Storage::fake('public');
        $url = $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('avatar.png')], ['Accept' => 'application/json'])->assertOk()->json('data.avatar_url');
        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.avatar_url', $url);
        Sanctum::actingAs($teacherUser);
        $teacherUrl = $this->post('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('avatar.png')], ['Accept' => 'application/json'])->assertOk()->json('data.avatar_url');
        $this->getJson("/api/v1/teachers/{$teacher->id}")->assertOk()->assertJsonPath('data.avatar_url', $teacherUrl);
        $this->getJson('/api/v1/teachers/featured')->assertOk()->assertJsonPath('data.0.avatar_url', $teacherUrl);
    }
}
