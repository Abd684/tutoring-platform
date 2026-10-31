<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Individual;
use App\Models\PaidBooking;
use App\Models\Session;
use App\Models\Student;
use App\Models\TeacherAvailability;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SessionRules
{
    public function validate(array $state, bool $administrator = false): array
    {
        if ($administrator) {
            Gate::authorize('administer', Session::class);
        }
        $fail = fn ($key, $message) => throw ValidationException::withMessages([$key => [$message]]);
        $start = Carbon::parse($state['starts_at']);
        $end = Carbon::parse($state['ends_at']);
        $state['starts_at'] = $start->copy()->setTimezone(config('app.timezone'));
        $state['ends_at'] = $end->copy()->setTimezone(config('app.timezone'));
        if ($end->lte($start)) {
            $fail('ends_at', 'End must follow start.');
        }
        if (! in_array($state['type'] ?? null, ['group', 'individual'], true) || empty($state['type_id'])) {
            $fail('type_id', 'A group or individual target is required.');
        }
        $target = ($state['type'] === 'group' ? Group::query() : Individual::query())->whereKey($state['type_id'])->lockForUpdate()->firstOrFail();
        if (! $administrator) {
            Gate::authorize('manage', $target);
        }
        $course = $target->teacherSubject;
        if ((int) $course?->teacher_id !== (int) $state['teacher_id'] || $course?->status !== 'active'
            || $course->teacher?->status !== 'active' || $course->teacher?->user?->status !== 'active') {
            $fail('type_id', 'Target must belong to this active teacher and subject.');
        }
        if ($target instanceof Group) {
            if (! in_array($target->status, ['active', 'forming'], true)) {
                $fail('type_id', 'Group is closed.');
            }
            $state['capacity'] = $state['capacity'] ?? $target->capacity;
            if ($target->groupStudents()->where('status', 'active')->whereHas('student', fn ($q) => $q->where('status', '!=', 'active')->orWhereHas('user', fn ($q) => $q->where('status', '!=', 'active')))->exists()) {
                $fail('type_id', 'Group contains an inactive student.');
            }
            $members = $target->groupStudents()->where('status', 'active')->count();
            if ($state['capacity'] > $target->capacity || $state['capacity'] < $members) {
                $fail('capacity', 'Capacity must cover members without exceeding group capacity.');
            }
        } else {
            abort_if(PaidBooking::where('individual_id', $target->id)->exists(), 409, 'Paid teaching must use the paid booking workflow.');
            if (! $this->active($target, $start, $end)) {
                $fail('type_id', 'Individual teaching is inactive or outside its dates.');
            }
            $state['capacity'] = 1;
        }
        if (! empty($state['availability_id'])) {
            $availability = TeacherAvailability::findOrFail($state['availability_id']);
            if (! $administrator) {
                Gate::authorize('update', $availability);
            }
            if ((int) $availability->teacher_id !== (int) $state['teacher_id'] || ! app(AvailabilityBooking::class)->contains($availability, $start, $end)) {
                $fail('availability_id', 'Session is outside active availability.');
            }
        }

        return $state;
    }

    public function active(Individual $individual, Carbon $start, Carbon $end): bool
    {
        return $individual->status === 'active' && (! $individual->starts_at || $individual->starts_at->lte($start))
            && (! $individual->ends_at || $individual->ends_at->gte($end))
            && $individual->student?->status === 'active' && $individual->student?->user?->status === 'active'
            && $individual->teacherSubject?->status === 'active' && $individual->teacherSubject?->teacher?->status === 'active'
            && $individual->teacherSubject?->teacher?->user?->status === 'active';
    }

    public function conflicts(array $state, ?int $except = null): bool
    {
        $query = Session::whereIn('status', ['scheduled', 'ongoing'])->where('starts_at', '<', $state['ends_at'])->where('ends_at', '>', $state['starts_at'])->when($except, fn ($q) => $q->whereKeyNot($except));
        $ids = (new Session($state))->participantIds();
        Student::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

        return (clone $query)->where('teacher_id', $state['teacher_id'])->exists()
            || app(PaidLessonBookings::class)->holdConflicts($state['teacher_id'], $ids, $state['starts_at'], $state['ends_at'])
            || $query->forStudents($ids)->exists();
    }
}
