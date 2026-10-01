<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentQuizAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_get_quiz_start_attempt_and_submit_answer(): void
    {
        [$user, $student] = $this->student();
        [$quiz, $question] = $this->quiz();
        Sanctum::actingAs($user);

        $this->getJson("/api/v1/quizzes/{$quiz->id}/data")
            ->assertOk()
            ->assertJsonPath('data.quiz.questions.0.id', $question->id)
            ->assertJsonPath('data.attempts_remaining', 2);

        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")
            ->assertCreated()
            ->assertJsonPath('data.attempt.student_id', $student->id)
            ->json('data.attempt.id');

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $question->id,
            'answer_text' => '4',
        ])->assertCreated();

        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $question->id,
            'answer_text' => 'Four',
        ])->assertOk();

        $this->assertDatabaseHas('answers', [
            'attempt_id' => $attemptId,
            'question_id' => $question->id,
            'answer_text' => 'Four',
        ]);
    }

    public function test_attempt_limit_and_attempt_ownership_are_enforced(): void
    {
        [$user] = $this->student();
        [$otherUser] = $this->student();
        [$quiz, $question] = $this->quiz(['attempts_allowed' => 1]);
        Sanctum::actingAs($user);

        $attemptId = $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")
            ->assertCreated()
            ->json('data.attempt.id');
        $this->postJson("/api/v1/quizzes/{$quiz->id}/attempts")->assertUnprocessable();

        Sanctum::actingAs($otherUser);
        $this->postJson("/api/v1/quiz-attempts/{$attemptId}/answers", [
            'question_id' => $question->id,
            'answer_text' => '4',
        ])->assertNotFound();
    }

    private function student(): array
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $user->id, 'status' => 'active']);

        return [$user, $student];
    }

    private function quiz(array $attributes = []): array
    {
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $subject = Subject::create(['name' => 'Math', 'status' => 'active']);
        $teacherSubject = TeacherSubject::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'price' => 10,
            'status' => 'active',
        ]);
        $unit = Unit::create(['teacher_subject_id' => $teacherSubject->id, 'title' => 'Unit']);
        $lesson = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson']);
        $content = Content::create([
            'lesson_id' => $lesson->id,
            'type' => 'quiz',
            'title' => 'Math quiz',
            'status' => 'published',
        ]);
        $quiz = Quiz::create(array_merge([
            'content_id' => $content->id,
            'duration_minutes' => 30,
            'attempts_allowed' => 2,
            'status' => 'published',
        ], $attributes));
        $question = Question::create([
            'quiz_id' => $quiz->id,
            'type' => 'mcq',
            'body' => '2 + 2?',
            'points' => 1,
            'rubric_json' => ['options' => ['3', '4']],
            'order_no' => 1,
        ]);

        return [$quiz, $question];
    }
}
