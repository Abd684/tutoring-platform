<?php

// deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuizAttemptController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', QuizAttempt::class);

        $query = QuizAttempt::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', QuizAttempt::class);

        $quizAttempt = QuizAttempt::create($request->validate([
            'quiz_id' => ['required', 'integer', 'exists:quizzes,id'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'started_at' => ['nullable', 'date'],
            'submitted_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'score' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($quizAttempt, 'Quiz Attempt created successfully.', 201);
    }

    public function show(QuizAttempt $quizAttempt): JsonResponse
    {
        $this->authorize('view', $quizAttempt);

        return $this->success($quizAttempt);
    }

    public function start(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('accessStudent', QuizAttempt::class);

        $student = $request->user()->student;
        abort_if($student === null || $student->status !== 'active', 403, 'Active student profile required.');
        abort_unless($quiz->status === 'published', 404);
        $this->authorize('access', $quiz);

        $result = DB::transaction(function () use ($quiz, $student): array {
            $lockedQuiz = Quiz::query()->lockForUpdate()->findOrFail($quiz->id);
            $attemptsUsed = QuizAttempt::query()
                ->where('quiz_id', $lockedQuiz->id)
                ->where('student_id', $student->id)
                ->count();

            if ($attemptsUsed >= (int) $lockedQuiz->attempts_allowed) {
                return ['error' => 'No quiz attempts remaining.'];
            }

            $attempt = QuizAttempt::create([
                'quiz_id' => $lockedQuiz->id,
                'student_id' => $student->id,
                'started_at' => now(),
                'status' => 'in_progress',
            ]);

            return [
                'attempt' => $attempt,
                'attempt_id' => $attempt->id, 'quiz_id' => $quiz->id,
                'attempts_remaining' => max(0, (int) $lockedQuiz->attempts_allowed - $attemptsUsed - 1),
            ];
        });

        if (isset($result['error'])) {
            return $this->businessError($result['error']);
        }

        return $this->success($result, 'Quiz attempt started successfully.', 201);
    }

    public function update(Request $request, QuizAttempt $quizAttempt): JsonResponse
    {
        $this->authorize('update', $quizAttempt);

        $quizAttempt->update($request->validate([
            'quiz_id' => ['sometimes', 'integer', 'exists:quizzes,id'],
            'student_id' => ['sometimes', 'integer', 'exists:students,id'],
            'started_at' => ['sometimes', 'nullable', 'date'],
            'submitted_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:started_at'],
            'score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($quizAttempt->fresh(), 'Quiz Attempt updated successfully.');
    }

    public function destroy(QuizAttempt $quizAttempt): JsonResponse
    {
        $this->authorize('delete', $quizAttempt);

        $quizAttempt->delete();

        return $this->success(message: 'Quiz Attempt deleted successfully.');
    }

    public function submit(Request $request, QuizAttempt $quizAttempt): JsonResponse
    {
        $student = $request->user()->student;
        abort_if($request->user()->status !== 'active' || ! $student || $student->status !== 'active', 403, 'Active student profile required.');
        abort_unless((int) $quizAttempt->student_id === (int) $student->id, 404);
        $this->authorize('access', $quizAttempt->quiz);
        $attempt = DB::transaction(function () use ($quizAttempt): QuizAttempt {
            $attempt = QuizAttempt::query()->lockForUpdate()->findOrFail($quizAttempt->id);
            if ($attempt->submitted_at !== null) {
                return $attempt;
            }
            abort_unless($attempt->status === 'in_progress', 422, 'This attempt cannot be submitted.');
            $questions = $attempt->quiz->questions()->get(['id', 'points']);
            $answers = $attempt->answers()->get()->keyBy('question_id');
            // Unanswered questions earn zero. Pending answers must be reviewed first.
            $pending = $answers->contains(fn ($answer) => $answer->status === 'pending' || $answer->score === null);
            $score = $pending ? null : $questions->sum(fn ($question) => min(
                (float) $question->points,
                max(0, (float) ($answers->get($question->id)?->score ?? 0)),
            ));
            $attempt->update([
                'submitted_at' => now(),
                'status' => 'submitted',
                'score' => $score,
            ]);

            return $attempt->fresh();
        });

        return $this->success([
            'attempt_id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'status' => $attempt->status,
            'submitted_at' => $attempt->submitted_at->toIso8601String(),
            'score' => $attempt->score === null ? null : (float) $attempt->score,
            'grading_pending' => $attempt->score === null,
        ], 'Quiz attempt submitted successfully.');
    }
}
