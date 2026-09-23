<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SubscriptionPlan;
use App\Models\TeacherSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TeacherSubscriptionController extends ApiController
{
    public function subscribe(Request $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $validatedData = $request->validate([
            'auto_renew' => ['sometimes', 'boolean'],
        ]);

        if ($subscriptionPlan->status !== 'active') {
            return $this->businessError('This subscription plan is not available.', 422);
        }

        $teacher = $request->user()->teacher;

        if (! $teacher || $teacher->status !== 'active') {
            return $this->businessError('The teacher account must be activated before subscribing.', 403);
        }

        $result = DB::transaction(function () use ($teacher, $subscriptionPlan, $validatedData): array {
            $now = now();

            TeacherSubscription::query()
                ->where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->where('ends_at', '<=', $now)
                ->update(['status' => 'expired']);

            $currentSubscription = TeacherSubscription::query()
                ->where('teacher_id', $teacher->id)
                ->where('status', 'active')
                ->where('ends_at', '>', $now)
                ->lockForUpdate()
                ->first();

            if ($currentSubscription?->plan_id === $subscriptionPlan->id) {
                return ['subscription' => $currentSubscription, 'action' => 'unchanged'];
            }

            if ($currentSubscription) {
                $currentSubscription->update(['status' => 'cancelled']);
            }

            $endsAt = $subscriptionPlan->billing_cycle === 'yearly'
                ? $now->copy()->addYearNoOverflow()
                : $now->copy()->addMonthNoOverflow();

            $subscription = TeacherSubscription::create([
                'teacher_id' => $teacher->id,
                'plan_id' => $subscriptionPlan->id,
                'starts_at' => $now,
                'ends_at' => $endsAt,
                'auto_renew' => $validatedData['auto_renew'] ?? false,
                'status' => 'active',
            ]);

            return [
                'subscription' => $subscription,
                'action' => $currentSubscription ? 'upgraded' : 'subscribed',
            ];
        });

        if ($result['action'] === 'unchanged') {
            return $this->businessError('You are already subscribed to this plan.', 409);
        }

        $message = $result['action'] === 'upgraded'
            ? 'Subscription upgraded successfully.'
            : 'Subscription created successfully.';

        return $this->success(
            $result['subscription']->load('plan'),
            $message,
            $result['action'] === 'subscribed' ? 201 : 200
        );
    }

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
