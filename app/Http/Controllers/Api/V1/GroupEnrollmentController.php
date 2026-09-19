<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupEnrollment;
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
