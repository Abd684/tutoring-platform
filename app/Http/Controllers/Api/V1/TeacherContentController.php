<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Content;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherContentController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $teacherId = $request->user()?->teacher?->id;
        abort_if($teacherId === null, 403, 'Teacher profile not found.');

        $validated = $request->validate([
            'lesson_id' => [
                'required',
                'integer',
                Rule::exists('lessons', 'id')->where(fn ($query) => $query
                    ->whereExists(fn ($owned) => $owned
                        ->selectRaw('1')
                        ->from('units')
                        ->join('teacher_subjects', 'teacher_subjects.id', '=', 'units.teacher_subject_id')
                        ->whereColumn('units.id', 'lessons.unit_id')
                        ->where('teacher_subjects.teacher_id', $teacherId))),
            ],
            'type' => ['required', Rule::in(['video', 'pdf', 'quiz', 'link'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'learning_objectives' => ['nullable', 'array', 'max:100'],
            'learning_objectives.*' => ['string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'published_at' => ['nullable', 'date'],
        ]);

        $this->authorize('manage', Lesson::findOrFail($validated['lesson_id']));

        $content = Content::create($validated);

        return $this->success($content, 'Content created successfully.', 201);
    }

    public function update(Request $request, Content $content): JsonResponse
    {
        $this->authorize('manage', $content);
        $content->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'learning_objectives' => ['sometimes', 'nullable', 'array', 'max:100'],
            'learning_objectives.*' => ['string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'order_no' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
        ]));

        return $this->success($content->fresh());
    }
}
