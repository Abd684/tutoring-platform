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

