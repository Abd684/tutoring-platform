<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\PaidBooking;
use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use Carbon\Carbon;

class TeacherCalendar
{
    /** All returned busy periods are anonymous; the booking source is availability, not a pre-created session. */
    public function periods(Teacher $teacher, Student $student, Carbon $from, Carbon $to, string $timezone): array
    {
        $windows = [];
        foreach (TeacherAvailability::where('teacher_id', $teacher->id)->whereIn('status', ['active', 'disabled'])->orderBy('id')->get() as $availability) {
            for ($day = $from->copy()->setTimezone($availability->timezone)->startOfDay(); $day->lt($to); $day->addDay()) {
                $date = $day->toDateString();
                if ($availability->recurrence_type === 'weekly') {
                    if ($availability->weekday !== $day->dayOfWeek || ($availability->date && $date < $availability->date->toDateString())) {
                        continue;
                    }
                } elseif (! $availability->date || $date !== $availability->date->toDateString()) {
                    continue;
                }
                $start = Carbon::parse($date.' '.$availability->start_time, $availability->timezone)->utc();
                $end = Carbon::parse($date.' '.$availability->end_time, $availability->timezone)->utc();
                if ($end->lte($start) || $end->lte($from) || $start->gte($to)) {
                    continue;
                }
                $windows[] = ['from' => max($from->timestamp, $start->timestamp), 'to' => min($to->timestamp, $end->timestamp), 'model' => $availability];
            }
        }
        $base = Session::whereIn('status', ['scheduled', 'ongoing', 'completed'])->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        $teacherBusy = (clone $base)->where('teacher_id', $teacher->id)->get(['starts_at', 'ends_at', 'type', 'status']);
        $studentBusy = (clone $base)->where(fn ($q) => $q->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))
            ->orWhereHas('group.groupEnrollments', fn ($q) => $q->where('status', 'active')->whereHas('enrollment', fn ($q) => $q->where('student_id', $student->id))))->get(['starts_at', 'ends_at']);
        $holds = PaidBooking::holding()->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        $teacherBusy = $teacherBusy->concat((clone $holds)->where('teacher_id', $teacher->id)->get());
        $studentBusy = $studentBusy->concat((clone $holds)->where('student_id', $student->id)->get());
        $enrollments = Enrollment::where('student_id', $student->id)->where('teacher_id', $teacher->id)->where('status', 'active')->where('enrollable_type', '!=', 'paid_booking')
            ->with(['student.user', 'teacher.user'])->get();
        $points = [$from->timestamp, $to->timestamp];
        foreach ($windows as $window) {
            $points[] = $window['from'];
            $points[] = $window['to'];
        }
        foreach ($teacherBusy->concat($studentBusy) as $session) {
            $points[] = max($from->timestamp, $session->starts_at->timestamp);
            $points[] = min($to->timestamp, $session->ends_at->timestamp);
        }
        foreach ($enrollments as $enrollment) {
            foreach ([$enrollment->starts_at, $enrollment->expires_at] as $boundary) {
                if ($boundary && $boundary->gt($from) && $boundary->lt($to)) {
                    $points[] = $boundary->timestamp;
                }
            }
        }
        for ($day = $from->copy()->setTimezone($timezone)->startOfDay()->addDay(); $day->lt($to); $day->addDay()) {
            $points[] = $day->timestamp;
        }
        $now = now()->addMinute()->startOfMinute()->timestamp;
        if ($now > $from->timestamp && $now < $to->timestamp) {
            $points[] = $now;
        }
        $points = array_values(array_unique($points));
        sort($points);
        $periods = [];
        $eligibleInPeriod = false;
        for ($i = 0; $i < count($points) - 1; $i++) {
            [$a,$b] = [$points[$i], $points[$i + 1]];
            if ($a >= $b) {
                continue;
            }
            $start = Carbon::createFromTimestampUTC($a);
            $end = Carbon::createFromTimestampUTC($b);
            $cover = array_values(array_filter($windows, fn ($w) => $w['from'] <= $a && $w['to'] >= $b));
            $active = array_values(array_filter($cover, fn ($w) => $w['model']->status === 'active'));
            $busy = $teacherBusy->first(fn ($s) => $s->starts_at->timestamp < $b && $s->ends_at->timestamp > $a);
            $ownConflict = $studentBusy->contains(fn ($s) => $s->starts_at->timestamp < $b && $s->ends_at->timestamp > $a);
            $ids = $enrollments->filter(fn ($e) => app(SessionRules::class)->active($e, $start, $end))->pluck('id')->values();
            $eligibleInPeriod = $eligibleInPeriod || $ids->isNotEmpty();
            $reason = match (true) {
                (bool) $busy => 'booked', $active === [] && $cover !== [] => 'closed', $active === [] => 'outside_schedule',
                $a < $now => 'past', $ids->isEmpty() => 'not_eligible', $ownConflict => 'student_conflict', default => 'available',
            };
            $bookable = $reason === 'available';
            $availability = $bookable ? $active[0]['model'] : null;
            $periods[] = [
                'starts_at' => $start->setTimezone($timezone)->toIso8601String(), 'ends_at' => $end->setTimezone($timezone)->toIso8601String(),
                'timezone' => $timezone, 'status' => $bookable ? 'available' : ($reason === 'booked' ? 'booked' : 'unavailable'),
                'reason' => $reason, 'bookable' => $bookable, 'session_id' => null, 'session_type' => $busy?->type ?? ($active !== [] ? 'INDIVIDUAL' : null),
                'session_status' => $busy?->status, 'availability_id' => $availability?->id, 'availability_timezone' => $availability?->timezone,
                'eligible_enrollment_ids' => $bookable ? $ids : [],
            ];
        }

        return ['periods' => $periods, 'has_schedule' => $windows !== [], 'has_eligible_enrollment_in_period' => $eligibleInPeriod];
    }
}
