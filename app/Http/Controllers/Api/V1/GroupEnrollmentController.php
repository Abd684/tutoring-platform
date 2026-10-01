<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupEnrollment;
use App\Models\TeacherSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GroupEnrollmentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = GroupEnrollment::query()->with([
            'group:id,teacher_subject_id,name,capacity,status',
            'enrollment:id,student_id,teacher_id,status',
        ]);

        $query->when($request->filled('group_id'), fn ($q) => $q->where('group_id', $request->integer('group_id')))
            ->when($request->filled('enrollment_id'), fn ($q) => $q->where('enrollment_id', $request->integer('enrollment_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'enrollment_id' => [
                'required', 'integer', 'exists:enrollments,id',
                Rule::unique('group_enrollments', 'enrollment_id')->where(fn ($q) => $q->where('group_id', $request->integer('group_id'))),
            ],
            'status' => ['sometimes', Rule::in(['active', 'left', 'removed'])],
            'joined_at' => ['nullable', 'date'],
            'left_at' => ['nullable', 'date', 'after_or_equal:joined_at'],
        ]);

        $result = DB::transaction(function () use ($validated) {
            $group = Group::query()->lockForUpdate()->findOrFail($validated['group_id']);
            $enrollment = Enrollment::findOrFail($validated['enrollment_id']);

            if ($group->status === 'closed') {
                return ['error' => 'Closed groups cannot accept enrollments.'];
            }

            if ($enrollment->status !== 'active') {
                return ['error' => 'Only active enrollments can join a group.'];
            }

            $teacherSubject = $group->teacherSubject()->firstOrFail();
            if ((int) $teacherSubject->teacher_id !== (int) $enrollment->teacher_id) {
                return ['error' => 'Enrollment teacher does not match the group teacher.'];
            }

            if (($validated['status'] ?? 'active') === 'active') {
                $activeCount = GroupEnrollment::query()
                    ->where('group_id', $group->id)
                    ->where('status', 'active')
                    ->count();

                if ($activeCount >= $group->capacity) {
                    return ['error' => 'Group capacity has been reached.'];
                }
            }

            $payload = $validated;
            $payload['joined_at'] ??= now();

            return ['model' => GroupEnrollment::create($payload)];
        });

        if (isset($result['error'])) {
            return $this->businessError($result['error']);
        }

        return $this->success($result['model']->load(['group', 'enrollment']), 'Group enrollment created successfully.', 201);
    }

    /** POST /api/v1/teacher/groups/{id}/enrollments */
    public function addToTeacherGroup(Request $request, int $id): JsonResponse
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
            'enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
        ]);

        $result = DB::transaction(function () use ($teacher, $validated, $id) {
            $group = Group::query()->lockForUpdate()->with('teacherSubject')->findOrFail($id);
            $teacherSubject = $group->teacherSubject;

            if ((int) $teacherSubject->teacher_id !== (int) $teacher->id) {
                return ['error' => ['This group does not belong to the authenticated teacher.', 'GROUP_FORBIDDEN', 403]];
            }

            if ($group->status === 'closed') {
                return ['error' => ['Closed groups cannot accept enrollments.', 'GROUP_CLOSED', 422]];
            }

            $enrollment = Enrollment::findOrFail($validated['enrollment_id']);
            if ($enrollment->status !== 'active') {
                return ['error' => ['Only active enrollments can join a group.', 'ENROLLMENT_NOT_ACTIVE', 422]];
            }

            if ((int) $enrollment->teacher_id !== (int) $teacher->id) {
                return ['error' => ['Enrollment teacher does not match the group teacher.', 'ENROLLMENT_TEACHER_MISMATCH', 422]];
            }

            if ($enrollment->enrollable_type !== TeacherSubject::class || (int) $enrollment->enrollable_id !== (int) $group->teacher_subject_id) {
                return ['error' => ['The enrollment must be an active full-subject enrollment for this group teacher subject.', 'ENROLLMENT_SUBJECT_MISMATCH', 422]];
            }

            $existing = GroupEnrollment::query()
                ->where('group_id', $group->id)
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === 'active') {
                return ['error' => ['This enrollment is already active in the group.', 'ALREADY_IN_GROUP', 409]];
            }

            $activeCount = GroupEnrollment::query()
                ->where('group_id', $group->id)
                ->where('status', 'active')
                ->count();

            if ($activeCount >= $group->capacity) {
                return ['error' => ['Group capacity has been reached.', 'GROUP_CAPACITY_REACHED', 409]];
            }

            if ($existing) {
                $existing->update([
                    'status' => 'active',
                    'joined_at' => now(),
                    'left_at' => null,
                ]);
                $model = $existing->fresh();
            } else {
                $model = GroupEnrollment::create([
                    'group_id' => $group->id,
                    'enrollment_id' => $enrollment->id,
                    'status' => 'active',
                    'joined_at' => now(),
                    'left_at' => null,
                ]);
            }

            return ['model' => $model->load(['group', 'enrollment'])];
        });

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];
            return response()->json(['status' => false, 'message' => $message, 'code' => $code], $status);
        }

        return $this->success($result['model'], 'Enrollment added to group successfully.', 201);
    }

    /** DELETE /api/v1/teacher/groups/{id}/enrollments/{enrollment} */
    public function removeFromTeacherGroup(Request $request, int $id, int $enrollment): JsonResponse
    {
        $teacher = $request->user()?->teacher;
        if (! $teacher) {
            return response()->json([
                'status' => false,
                'message' => 'Teacher account not found.',
                'code' => 'TEACHER_PROFILE_NOT_FOUND',
            ], 403);
        }

        $result = DB::transaction(function () use ($teacher, $id, $enrollment) {
            $group = Group::query()->lockForUpdate()->with('teacherSubject')->findOrFail($id);
            if ((int) $group->teacherSubject->teacher_id !== (int) $teacher->id) {
                return ['error' => ['This group does not belong to the authenticated teacher.', 'GROUP_FORBIDDEN', 403]];
            }

            $groupEnrollment = GroupEnrollment::query()
                ->where('group_id', $group->id)
                ->where('enrollment_id', $enrollment)
                ->lockForUpdate()
                ->first();

            if (! $groupEnrollment) {
                return ['error' => ['The enrollment is not linked to this group.', 'GROUP_ENROLLMENT_NOT_FOUND', 404]];
            }

            if ($groupEnrollment->status !== 'active') {
                return ['error' => ['The enrollment is already inactive in this group.', 'GROUP_ENROLLMENT_NOT_ACTIVE', 409]];
            }

            $groupEnrollment->update([
                'status' => 'removed',
                'left_at' => now(),
            ]);

            return ['model' => $groupEnrollment->fresh()->load(['group', 'enrollment'])];
        });

        if (isset($result['error'])) {
            [$message, $code, $status] = $result['error'];
            return response()->json(['status' => false, 'message' => $message, 'code' => $code], $status);
        }

        return $this->success($result['model'], 'Enrollment removed from group successfully.');
    }

    public function show(GroupEnrollment $groupEnrollment): JsonResponse
    {
        return $this->success($groupEnrollment->load(['group', 'enrollment']));
    }

    public function update(Request $request, GroupEnrollment $groupEnrollment): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'left', 'removed'])],
            'joined_at' => ['nullable', 'date'],
            'left_at' => ['nullable', 'date'],
        ]);

        $result = DB::transaction(function () use ($validated, $groupEnrollment) {
            $group = Group::query()->lockForUpdate()->findOrFail($groupEnrollment->group_id);

            $becomingActive = ($validated['status'] ?? $groupEnrollment->status) === 'active' && $groupEnrollment->status !== 'active';
            if ($becomingActive) {
                $activeCount = GroupEnrollment::query()
                    ->where('group_id', $group->id)
                    ->where('status', 'active')
                    ->where('id', '!=', $groupEnrollment->id)
                    ->count();

                if ($activeCount >= $group->capacity) {
                    return ['error' => 'Group capacity has been reached.'];
                }
            }

            if (in_array($validated['status'] ?? null, ['left', 'removed'], true) && !array_key_exists('left_at', $validated)) {
                $validated['left_at'] = now();
            }

            $groupEnrollment->update($validated);

            return ['model' => $groupEnrollment->fresh()];
        });

        if (isset($result['error'])) {
            return $this->businessError($result['error']);
        }

        return $this->success($result['model'], 'Group enrollment updated successfully.');
    }

    public function destroy(GroupEnrollment $groupEnrollment): JsonResponse
    {
        $groupEnrollment->delete();

        return $this->success(message: 'Group enrollment deleted successfully.');
    }
}
