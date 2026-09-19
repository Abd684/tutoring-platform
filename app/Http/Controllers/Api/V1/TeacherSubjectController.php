<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TeacherSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherSubjectController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = TeacherSubject::query()
            ->with(['teacher.user:id,name,email', 'subject:id,name,status']);

        $query->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->has('group_enabled'), fn ($q) => $q->where('group_enabled', $request->boolean('group_enabled')));

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'subject_id' => [
                'required', 'integer', 'exists:subjects,id',
                Rule::unique('teacher_subjects', 'subject_id')->where(fn ($q) => $q->where('teacher_id', $request->integer('teacher_id'))),
            ],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'group_enabled' => ['sometimes', 'boolean'],
            'max_group_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]);

        if (!($validated['group_enabled'] ?? false)) {
            $validated['max_group_size'] = null;
        }

        $model = TeacherSubject::create($validated)->load(['teacher.user:id,name,email', 'subject:id,name,status']);

        return $this->success($model, 'Teacher subject created successfully.', 201);
    }

    public function show(TeacherSubject $teacherSubject): JsonResponse
    {
        return $this->success($teacherSubject->load([
            'teacher.user:id,name,email',
            'subject:id,name,status',
        ])->loadCount(['units', 'groups']));
    }

    public function update(Request $request, TeacherSubject $teacherSubject): JsonResponse
    {
        $teacherId = $request->integer('teacher_id', $teacherSubject->teacher_id);

        $validated = $request->validate([
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'subject_id' => [
                'sometimes', 'integer', 'exists:subjects,id',
                Rule::unique('teacher_subjects', 'subject_id')
                    ->where(fn ($q) => $q->where('teacher_id', $teacherId))
                    ->ignore($teacherSubject->id),
            ],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'group_enabled' => ['sometimes', 'boolean'],
            'max_group_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]);

        $effectiveGroupEnabled = array_key_exists('group_enabled', $validated)
            ? (bool) $validated['group_enabled']
            : (bool) $teacherSubject->group_enabled;

        if (!$effectiveGroupEnabled) {
            $validated['max_group_size'] = null;
        }

        $teacherSubject->update($validated);

        return $this->success($teacherSubject->fresh()->load(['teacher.user:id,name,email', 'subject:id,name,status']), 'Teacher subject updated successfully.');
    }

    public function destroy(TeacherSubject $teacherSubject): JsonResponse
    {
        $teacherSubject->delete();

        return $this->success(message: 'Teacher subject deleted successfully.');
    }
}
