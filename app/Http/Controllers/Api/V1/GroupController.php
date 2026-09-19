<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use App\Models\TeacherSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GroupController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Group::query()
            ->with(['teacherSubject.subject:id,name'])
            ->withCount(['groupEnrollments as active_enrollments_count' => fn ($q) => $q->where('status', 'active')]);

        $query->when($request->filled('teacher_subject_id'), fn ($q) => $q->where('teacher_subject_id', $request->integer('teacher_subject_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'));

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'],
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', Rule::in(['forming', 'active', 'closed'])],
        ]);

        $teacherSubject = TeacherSubject::findOrFail($validated['teacher_subject_id']);
        if (!$teacherSubject->group_enabled) {
            return $this->businessError('Groups are disabled for this teacher subject.');
        }
        if ($teacherSubject->max_group_size !== null && $validated['capacity'] > $teacherSubject->max_group_size) {
            return $this->businessError('Group capacity exceeds the teacher subject maximum group size.');
        }

        $group = Group::create($validated);

        return $this->success($group, 'Group created successfully.', 201);
    }

    public function show(Group $group): JsonResponse
    {
        return $this->success($group->load('teacherSubject.subject:id,name')->loadCount([
            'groupEnrollments as active_enrollments_count' => fn ($q) => $q->where('status', 'active'),
        ]));
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $validated = $request->validate([
            'teacher_subject_id' => ['sometimes', 'integer', 'exists:teacher_subjects,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', Rule::in(['forming', 'active', 'closed'])],
        ]);

        $teacherSubjectId = $validated['teacher_subject_id'] ?? $group->teacher_subject_id;
        $capacity = $validated['capacity'] ?? $group->capacity;
        $teacherSubject = TeacherSubject::findOrFail($teacherSubjectId);

        if (!$teacherSubject->group_enabled) {
            return $this->businessError('Groups are disabled for this teacher subject.');
        }
        if ($teacherSubject->max_group_size !== null && $capacity > $teacherSubject->max_group_size) {
            return $this->businessError('Group capacity exceeds the teacher subject maximum group size.');
        }

        $activeCount = $group->groupEnrollments()->where('status', 'active')->count();
        if ($capacity < $activeCount) {
            return $this->businessError('Group capacity cannot be lower than the current active enrollment count.');
        }

        $group->update($validated);

        return $this->success($group->fresh(), 'Group updated successfully.');
    }

    public function destroy(Group $group): JsonResponse
    {
        $group->delete();

        return $this->success(message: 'Group deleted successfully.');
    }
}
