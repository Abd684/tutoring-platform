<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LessonController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Lesson::query()->with(['unit:id,teacher_subject_id,title,status']);

        $query->when($request->filled('unit_id'), fn ($q) => $q->where('unit_id', $request->integer('unit_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->string('search').'%'));

        return $this->paginated($query->orderBy('order_no')->orderBy('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $lesson = Lesson::create($request->validate([
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ]));

        return $this->success($lesson, 'Lesson created successfully.', 201);
    }

    public function show(Lesson $lesson): JsonResponse
    {
        return $this->success($lesson->load('unit:id,teacher_subject_id,title,status')->loadCount('contents'));
    }

    public function update(Request $request, Lesson $lesson): JsonResponse
    {
        $lesson->update($request->validate([
            'unit_id' => ['sometimes', 'integer', 'exists:units,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ]));

        return $this->success($lesson->fresh(), 'Lesson updated successfully.');
    }

    public function destroy(Lesson $lesson): JsonResponse
    {
        $lesson->delete();

        return $this->success(message: 'Lesson deleted successfully.');
    }
}
