<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Quiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Quiz::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $quiz = Quiz::create($request->validate([
            'content_id' => ['required', 'integer', 'exists:contents,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'attempts_allowed' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($quiz, 'Quiz created successfully.', 201);
    }

    public function show(Quiz $quiz): JsonResponse
    {
        return $this->success($quiz);
    }

    public function studentData(Request $request, Quiz $quiz): JsonResponse
    {
        $student = $request->user()->student;
        abort_if($student === null || $student->status !== 'active', 403, 'Active student profile required.');
        abort_unless($quiz->status === 'published', 404);

        $quiz->load(['content:id,title,description', 'questions' => fn ($query) => $query
            ->select(['id', 'quiz_id', 'type', 'body', 'points', 'rubric_json', 'order_no'])
            ->orderBy('order_no')
            ->orderBy('id')]);

        $attemptsUsed = $quiz->attempts()->where('student_id', $student->id)->count();
        $activeAttempt = $quiz->attempts()
            ->where('student_id', $student->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        return $this->success([
            'quiz' => $quiz,
            'attempts_used' => $attemptsUsed,
            'attempts_remaining' => max(0, (int) $quiz->attempts_allowed - $attemptsUsed),
            'active_attempt' => $activeAttempt,
        ]);
    }

    public function update(Request $request, Quiz $quiz): JsonResponse
    {
        $quiz->update($request->validate([
            'content_id' => ['sometimes', 'integer', 'exists:contents,id'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'attempts_allowed' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($quiz->fresh(), 'Quiz updated successfully.');
    }

    public function destroy(Quiz $quiz): JsonResponse
    {
        $quiz->delete();

        return $this->success(message: 'Quiz deleted successfully.');
    }
}

