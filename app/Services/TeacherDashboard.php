<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Session;
use App\Models\Teacher;
use App\Models\TeacherSubscription;

class TeacherDashboard
{
    public function current(Teacher $teacher): ?TeacherSubscription
    {
        return $teacher->subscriptions()->where('status', 'active')->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())->with('plan')->orderByDesc('starts_at')->orderByDesc('id')->first();
    }

    public function subscription(TeacherSubscription $subscription): array
    {
        $effective = $subscription->status;
        if ($effective === 'active') {
            $effective = $subscription->ends_at->lte(now()) ? 'expired' : ($subscription->starts_at->gt(now()) ? 'scheduled' : 'active');
        }

        return [...$subscription->toArray(), 'effective_status' => $effective];
    }

    public function usage(Teacher $teacher): array
    {
        $subscription = $this->current($teacher);
        $groups = Group::whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $teacher->id));
        $sessions = Session::where('teacher_id', $teacher->id);
        $students = Enrollment::where('teacher_id', $teacher->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('student', fn ($q) => $q->where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active')))
            ->distinct()->count('student_id');

        return [
            'subscription_id' => $subscription?->id,
            'generated_at' => now()->toIso8601String(),
            'groups' => ['used' => (clone $groups)->count(), 'active' => (clone $groups)->whereIn('status', ['active', 'forming'])->count(),
                'limit' => null, 'remaining' => null, 'limit_status' => 'not_configured', 'scope' => 'all_time', 'unit' => 'groups'],
            'sessions' => ['used' => (clone $sessions)->count(), 'scheduled' => (clone $sessions)->whereIn('status', ['scheduled', 'ongoing'])->count(),
                'completed' => (clone $sessions)->where('status', 'completed')->count(), 'cancelled' => (clone $sessions)->where('status', 'cancelled')->count(),
                'limit' => null, 'remaining' => null, 'limit_status' => 'not_configured', 'scope' => 'all_time', 'unit' => 'sessions'],
            'ai_reports' => ['supported' => false, 'used' => null, 'limit' => $subscription?->plan?->max_ai_usage,
                'remaining' => null, 'limit_status' => 'usage_not_tracked', 'scope' => null, 'unit' => 'reports',
                'reason' => 'No dated subscription usage ledger or report quota accounting is implemented.'],
            'students' => ['used' => $students, 'limit' => $subscription?->plan?->max_students,
                'remaining' => $subscription?->plan?->max_students === null ? null : max(0, $subscription->plan->max_students - $students),
                'scope' => 'active_enrollments', 'unit' => 'distinct_students'],
            'plan_limits' => $subscription?->plan?->only(['max_students', 'max_storage', 'max_ai_usage', 'max_group_size']),
            'quota_enforcement' => false,
        ];
    }
}
