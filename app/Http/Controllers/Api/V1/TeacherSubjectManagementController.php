<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TeacherSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherSubjectManagementController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewOwnAny', TeacherSubject::class);

        return $this->paginated(TeacherSubject::query()
            ->where('teacher_id', $request->user()->teacher->id)
            ->with('subject:id,name,status')
            ->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('createOwn', TeacherSubject::class);

        $teacherId = $request->user()->teacher->id;
        $validated = $request->validate($this->rules($teacherId));
        $validated['teacher_id'] = $teacherId;
        if (! ($validated['group_enabled'] ?? false)) {
            $validated['max_group_size'] = null;
        }

        $course = TeacherSubject::create($validated);

        return $this->success($course->fresh()->load('subject:id,name,status'), 'Teacher subject created successfully.', 201);
    }

    public function show(TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('manage', $teacherSubject);

        return $this->success($teacherSubject->load('subject:id,name,status'));
    }

    public function update(Request $request, TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('manage', $teacherSubject);

        $validated = $request->validate($this->rules($request->user()->teacher->id, $teacherSubject));
        if (! ($validated['group_enabled'] ?? $teacherSubject->group_enabled)) {
            $validated['max_group_size'] = null;
        }
        $teacherSubject->update($validated);

        return $this->success($teacherSubject->fresh()->load('subject:id,name,status'), 'Teacher subject updated successfully.');
    }

    public function destroy(TeacherSubject $teacherSubject): JsonResponse
    {
        $this->authorize('manage', $teacherSubject);
        $teacherSubject->delete();

        return $this->success(message: 'Teacher subject deleted successfully.');
    }

    private function rules(int $teacherId, ?TeacherSubject $course = null): array
    {
        $unique = Rule::unique('teacher_subjects', 'subject_id')
            ->where(fn ($query) => $query->where('teacher_id', $teacherId));
        if ($course !== null) {
            $unique->ignore($course->id);
        }

        return [
            'subject_id' => [$course === null ? 'required' : 'sometimes', 'integer', 'exists:subjects,id', $unique],
            'price' => [$course === null ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'group_enabled' => ['sometimes', 'boolean'],
            'max_group_size' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
