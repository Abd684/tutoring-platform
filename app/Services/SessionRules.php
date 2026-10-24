<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Session;
use App\Models\Student;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SessionRules
{
    public function validate(array $state): array
    {
        $fail = fn ($key, $message) => throw ValidationException::withMessages([$key => [$message]]);
        $start = Carbon::parse($state['starts_at']);
        $end = Carbon::parse($state['ends_at']);
        $state['starts_at'] = $start->copy()->setTimezone(config('app.timezone'));
        $state['ends_at'] = $end->copy()->setTimezone(config('app.timezone'));
        if ($end->lte($start)) {
            $fail('ends_at', 'End must follow start.');
        }
        if ($state['type'] === 'GROUP') {
            if (empty($state['group_id']) || ! empty($state['enrollment_id'])) {
                $fail('group_id', 'Group sessions require a group and no individual enrollment.');
            }
            $group = Group::whereKey($state['group_id'])->lockForUpdate()->firstOrFail();
            Gate::authorize('manage', $group);
            if ($group->status === 'closed') {
                $fail('group_id', 'Group is closed.');
            }
            foreach ($group->groupEnrollments()->where('status', 'active')->with('enrollment')->get() as $membership) {
                $member = $membership->enrollment;
                if (! $member || ! $this->active($member, $start, $end)
                    || $member->enrollable_type !== (new TeacherSubject)->getMorphClass()
                    || (int) $member->enrollable_id !== (int) $group->teacher_subject_id
                    || (int) $member->teacher_id !== (int) $state['teacher_id']) {
                    $fail('group_id', 'Every active member must have a matching full-subject enrollment covering the session.');
                }
            }
            $state['capacity'] = $state['capacity'] ?? $group->capacity;
            if ($state['capacity'] > $group->capacity) {
                $fail('capacity', 'Session capacity exceeds group capacity.');
            }
        } else {
            if (empty($state['enrollment_id']) || ! empty($state['group_id'])) {
                $fail('enrollment_id', 'Individual sessions require an enrollment and no group.');
            }
            $enrollment = Enrollment::findOrFail($state['enrollment_id']);
            if ($enrollment->enrollable_type === 'paid_booking') {
                $fail('enrollment_id', 'Paid lesson sessions are created and rescheduled only by their payment workflow.');
            }
            Gate::authorize('manage', $enrollment);
            if (! $this->active($enrollment, $start, $end) || (int) $enrollment->teacher_id !== (int) $state['teacher_id']) {
                $fail('enrollment_id', 'Enrollment is not valid for the session.');
            }
            $state['capacity'] = 1;
        }
        if (! empty($state['availability_id'])) {
            $availability = TeacherAvailability::findOrFail($state['availability_id']);
            Gate::authorize('update', $availability);
            if (! app(AvailabilityBooking::class)->contains($availability, $start, $end)) {
                $fail('availability_id', 'Session is outside active availability.');
            }
        }

        return $state;
    }

    public function active(Enrollment $enrollment, Carbon $start, Carbon $end): bool
    {
        return $enrollment->status === 'active'
            && (! $enrollment->starts_at || $enrollment->starts_at->lte($start))
            && (! $enrollment->expires_at || $enrollment->expires_at->gte($end))
            && $enrollment->student?->status === 'active' && $enrollment->student?->user?->status === 'active'
            && $enrollment->teacher?->status === 'active' && $enrollment->teacher?->user?->status === 'active';
    }

    public function conflicts(array $state, ?int $except = null): bool
    {
        $query = Session::whereIn('status', ['scheduled', 'ongoing'])->where('starts_at', '<', $state['ends_at'])->where('ends_at', '>', $state['starts_at'])
            ->when($except, fn ($q) => $q->whereKeyNot($except));
        if ((clone $query)->where('teacher_id', $state['teacher_id'])->exists()) {
            return true;
        }
        $ids = ! empty($state['enrollment_id']) ? [Enrollment::findOrFail($state['enrollment_id'])->student_id]
            : Group::findOrFail($state['group_id'])->groupEnrollments()->where('status', 'active')->with('enrollment')->get()->pluck('enrollment.student_id')->filter()->all();
        Student::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if (app(PaidLessonBookings::class)->holdConflicts($state['teacher_id'], $ids, $state['starts_at'], $state['ends_at'])) {
            return true;
        }

        return $query->where(fn ($q) => $q->whereHas('enrollment', fn ($q) => $q->whereIn('student_id', $ids))
            ->orWhereHas('group.groupEnrollments', fn ($q) => $q->where('status', 'active')->whereHas('enrollment', fn ($q) => $q->whereIn('student_id', $ids))))->exists();
    }
}
