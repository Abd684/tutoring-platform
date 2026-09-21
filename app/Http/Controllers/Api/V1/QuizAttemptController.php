<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\QuizAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizAttemptController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = QuizAttempt::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
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
        return $this->success($quizAttempt);
    }

    public function update(Request $request, QuizAttempt $quizAttempt): JsonResponse
    {
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
        $quizAttempt->delete();

        return $this->success(message: 'Quiz Attempt deleted successfully.');
    }
}

