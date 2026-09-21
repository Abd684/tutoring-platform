<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Answer;
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

