<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TeacherSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherSubscriptionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = TeacherSubscription::query()->with([
            'teacher.user:id,name,email,status',
            'plan:id,name,price,billing_cycle,status',
        ]);

        $query->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->integer('teacher_id')))
            ->when($request->filled('plan_id'), fn ($q) => $q->where('plan_id', $request->integer('plan_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return $this->paginated($query->orderByDesc('starts_at')->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $subscription = TeacherSubscription::create($request->validate([
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'auto_renew' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'expired', 'cancelled'])],
        ]));

        return $this->success($subscription->load(['teacher.user:id,name,email', 'plan']), 'Teacher subscription created successfully.', 201);
    }

    public function show(TeacherSubscription $teacherSubscription): JsonResponse
    {
        return $this->success($teacherSubscription->load(['teacher.user:id,name,email,status', 'plan']));
    }

    public function update(Request $request, TeacherSubscription $teacherSubscription): JsonResponse
    {
        $startsAt = $request->input('starts_at', $teacherSubscription->starts_at?->toDateTimeString());

        $teacherSubscription->update($request->validate([
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'plan_id' => ['sometimes', 'integer', 'exists:subscription_plans,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:'.$startsAt],
            'auto_renew' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'expired', 'cancelled'])],
        ]));

        return $this->success($teacherSubscription->fresh()->load('plan'), 'Teacher subscription updated successfully.');
    }

    public function destroy(TeacherSubscription $teacherSubscription): JsonResponse
    {
        if ($teacherSubscription->status === 'active') {
            return $this->businessError('Active subscriptions should be cancelled instead of deleted.', 409);
        }

        $teacherSubscription->delete();

        return $this->success(message: 'Teacher subscription deleted successfully.');
    }
}
