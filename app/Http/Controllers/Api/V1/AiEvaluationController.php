<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\AiEvaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiEvaluationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AiEvaluation::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $aiEvaluation = AiEvaluation::create($request->validate([
            'answer_id' => ['required', 'integer', 'exists:answers,id'],
            'model' => ['required', 'string', 'max:255'],
            'suggested_score' => ['nullable', 'numeric', 'min:0'],
            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'explanation' => ['nullable', 'string'],
            'teacher_score' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($aiEvaluation, 'Ai Evaluation created successfully.', 201);
    }

    public function show(AiEvaluation $aiEvaluation): JsonResponse
    {
        return $this->success($aiEvaluation);
    }

    public function update(Request $request, AiEvaluation $aiEvaluation): JsonResponse
    {
        $aiEvaluation->update($request->validate([
            'answer_id' => ['sometimes', 'integer', 'exists:answers,id'],
            'model' => ['sometimes', 'string', 'max:255'],
            'suggested_score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'explanation' => ['sometimes', 'nullable', 'string'],
            'teacher_score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($aiEvaluation->fresh(), 'Ai Evaluation updated successfully.');
    }

    public function destroy(AiEvaluation $aiEvaluation): JsonResponse
    {
        $aiEvaluation->delete();

        return $this->success(message: 'Ai Evaluation deleted successfully.');
    }
}

