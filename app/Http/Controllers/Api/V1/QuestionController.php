<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Question::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $question = Question::create($request->validate([
            'quiz_id' => ['required', 'integer', 'exists:quizzes,id'],
            'type' => ['required', 'string', 'max:50'],
            'body' => ['required', 'string'],
            'points' => ['required', 'numeric', 'min:0'],
            'rubric_json' => ['nullable', 'array'],
            'order_no' => ['required', 'integer', 'min:0'],
        ]));

        return $this->success($question, 'Question created successfully.', 201);
    }

    public function show(Question $question): JsonResponse
    {
        return $this->success($question);
    }

    public function update(Request $request, Question $question): JsonResponse
    {
        $question->update($request->validate([
            'quiz_id' => ['sometimes', 'integer', 'exists:quizzes,id'],
            'type' => ['sometimes', 'string', 'max:50'],
            'body' => ['sometimes', 'string'],
            'points' => ['sometimes', 'numeric', 'min:0'],
            'rubric_json' => ['sometimes', 'nullable', 'array'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
        ]));

        return $this->success($question->fresh(), 'Question updated successfully.');
    }

    public function destroy(Question $question): JsonResponse
    {
        $question->delete();

        return $this->success(message: 'Question deleted successfully.');
    }
}

