<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Group;
use App\Models\TeacherSubject;
use App\Services\ListSearch;
use App\Services\TeachingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GroupController extends ApiController
{
    public function indexForTeacher(Request $request): JsonResponse
    {
        $this->authorize('viewOwnAny', Group::class);

        $query = Group::query()
            ->whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $request->user()->teacher->id))
            ->with(['teacherSubject.subject:id,name'])
            ->withCount(['groupStudents as active_members_count' => fn ($q) => $q->where('status', 'active')]);

        $query->when($request->filled('teacher_subject_id'), fn ($q) => $q->where('teacher_subject_id', $request->integer('teacher_subject_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return $this->paginated($query->orderByDesc('id')->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'own_groups'))->paginate($this->perPage($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Group::class);

        $query = Group::query()
            ->with(['teacherSubject.subject:id,name'])
            ->withCount(['groupStudents as active_members_count' => fn ($q) => $q->where('status', 'active')]);

        $query->when($request->filled('teacher_subject_id'), fn ($q) => $q->where('teacher_subject_id', $request->integer('teacher_subject_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return $this->paginated($query->orderByDesc('id')->tap(fn ($query) => app(ListSearch::class)->apply($query, $request, 'own_groups'))->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Group::class);

        $validated = $request->validate([
            'teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'],
            'teaching_scope_type' => ['sometimes', 'in:subject,unit,lesson'], 'teaching_scope_id' => ['required_with:teaching_scope_type', 'integer'],
            'session_price' => ['required', 'numeric', 'between:0,9999999999.99'], 'starts_at' => ['nullable', 'date'], 'ends_at' => array_merge(['nullable', 'date'], $request->filled('starts_at') ? ['after:starts_at'] : []),
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', Rule::in(['forming', 'active', 'closed'])],
        ]);

        $teacherSubject = TeacherSubject::findOrFail($validated['teacher_subject_id']);
        if ($teacherSubject->max_group_size !== null && $validated['capacity'] > $teacherSubject->max_group_size) {
            return $this->businessError('Group capacity exceeds the teacher subject maximum group size.');
        }

        $group = Group::create($validated);

        return $this->success($group, 'Group created successfully.', 201);
    }

    /** POST /api/v1/teacher/groups - teacher identity comes from Bearer token. */
    public function storeForTeacher(Request $request): JsonResponse
    {
        $teacher = $request->user()?->teacher;
        if (! $teacher) {
            return response()->json([
                'status' => false,
                'message' => 'Teacher account not found.',
                'code' => 'TEACHER_PROFILE_NOT_FOUND',
            ], 403);
        }

        $validated = $request->validate([
            'teacher_subject_id' => ['required', 'integer', 'exists:teacher_subjects,id'],
            'teaching_scope_type' => ['sometimes', 'in:subject,unit,lesson'], 'teaching_scope_id' => ['required_with:teaching_scope_type', 'integer'],
            'session_price' => ['required', 'numeric', 'between:0,9999999999.99'], 'starts_at' => ['nullable', 'date'], 'ends_at' => array_merge(['nullable', 'date'], $request->filled('starts_at') ? ['after:starts_at'] : []),
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', Rule::in(['forming', 'active', 'closed'])],
        ]);

        $teacherSubject = TeacherSubject::findOrFail($validated['teacher_subject_id']);

        if ((int) $teacherSubject->teacher_id !== (int) $teacher->id) {
            return response()->json([
                'status' => false,
                'message' => 'This teacher subject does not belong to the authenticated teacher.',
                'code' => 'TEACHER_SUBJECT_FORBIDDEN',
            ], 403);
        }

        if ($teacherSubject->status !== 'active') {
            return response()->json([
                'status' => false,
                'message' => 'Groups can only be created for an active teacher subject.',
                'code' => 'TEACHER_SUBJECT_INACTIVE',
            ], 422);
        }

        if ($teacherSubject->max_group_size !== null && $validated['capacity'] > $teacherSubject->max_group_size) {
            return response()->json([
                'status' => false,
                'message' => 'Group capacity exceeds the teacher subject maximum group size.',
                'code' => 'GROUP_CAPACITY_EXCEEDED',
            ], 422);
        }

        $this->authorize('manage', $teacherSubject);

        app(TeachingScope::class)->resolve($teacherSubject, $validated['teaching_scope_type'] ?? 'subject', $validated['teaching_scope_id'] ?? $teacherSubject->id);
        $validated['teaching_scope_type'] ??= 'subject';
        $validated['teaching_scope_id'] ??= $teacherSubject->id;
        $validated['status'] ??= 'forming';
        $group = Group::create($validated);

        return $this->success(
            $group->load('teacherSubject.subject:id,name'),
            'Group created successfully.',
            201
        );
    }

    public function show(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        return $this->success($group->load('teacherSubject.subject:id,name')->loadCount([
            'groupStudents as active_members_count' => fn ($q) => $q->where('status', 'active'),
        ]));
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $this->authorize('update', $group);

        $validated = $request->validate([
            'teacher_subject_id' => ['sometimes', 'integer', 'exists:teacher_subjects,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'status' => ['sometimes', Rule::in(['forming', 'active', 'closed'])],
        ]);

        $teacherSubjectId = $validated['teacher_subject_id'] ?? $group->teacher_subject_id;
        $capacity = $validated['capacity'] ?? $group->capacity;
        $teacherSubject = TeacherSubject::findOrFail($teacherSubjectId);

        if ($teacherSubject->max_group_size !== null && $capacity > $teacherSubject->max_group_size) {
            return $this->businessError('Group capacity exceeds the teacher subject maximum group size.');
        }

        $activeCount = $group->groupStudents()->where('status', 'active')->count();
        if ($capacity < $activeCount) {
            return $this->businessError('Group capacity cannot be lower than the current active enrollment count.');
        }

        $group->update($validated);

        return $this->success($group->fresh(), 'Group updated successfully.');
    }

    public function destroy(Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        $group->delete();

        return $this->success(message: 'Group deleted successfully.');
    }
}
