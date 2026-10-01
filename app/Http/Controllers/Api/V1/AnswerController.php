<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Answer;
use App\Models\QuizAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnswerController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Answer::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $answer = Answer::create($request->validate([
            'attempt_id' => ['required', 'integer', 'exists:quiz_attempts,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'answer_text' => ['nullable', 'string'],
            'score' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($answer, 'Answer created successfully.', 201);
    }

    public function show(Answer $answer): JsonResponse
    {
        return $this->success($answer);
    }

    public function submitForAttempt(Request $request, QuizAttempt $quizAttempt): JsonResponse
    {
        $student = $request->user()->student;
        abort_if($student === null || $student->status !== 'active', 403, 'Active student profile required.');
        abort_unless((int) $quizAttempt->student_id === (int) $student->id, 404);

        if ($quizAttempt->status !== 'in_progress' || $quizAttempt->submitted_at !== null) {
            return $this->businessError('This quiz attempt no longer accepts answers.');
        }

        if ($quizAttempt->quiz->duration_minutes !== null
            && $quizAttempt->started_at->copy()->addMinutes($quizAttempt->quiz->duration_minutes)->isPast()) {
            return $this->businessError('The quiz attempt time has expired.');
        }

        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'answer_text' => ['present', 'nullable', 'string'],
        ]);

        $questionBelongsToQuiz = $quizAttempt->quiz->questions()
            ->whereKey($validated['question_id'])
            ->exists();
        abort_unless($questionBelongsToQuiz, 422, 'The selected question does not belong to this quiz.');

        $answer = Answer::updateOrCreate(
            [
                'attempt_id' => $quizAttempt->id,
                'question_id' => $validated['question_id'],
            ],
            [
                'answer_text' => $validated['answer_text'],
                'score' => null,
                'status' => 'pending',
            ],
        );

        return $this->success(
            $answer->fresh(),
            $answer->wasRecentlyCreated ? 'Answer submitted successfully.' : 'Answer updated successfully.',
            $answer->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function update(Request $request, Answer $answer): JsonResponse
    {
        $answer->update($request->validate([
            'attempt_id' => ['sometimes', 'integer', 'exists:quiz_attempts,id'],
            'question_id' => ['sometimes', 'integer', 'exists:questions,id'],
            'answer_text' => ['sometimes', 'nullable', 'string'],
            'score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($answer->fresh(), 'Answer updated successfully.');
    }

    public function destroy(Answer $answer): JsonResponse
    {
        $answer->delete();

        return $this->success(message: 'Answer deleted successfully.');
    }
}

