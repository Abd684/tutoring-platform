<?php

namespace App\Services;

use App\Models\Individual;
use App\Models\PaidBooking;
use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AvailabilityBooking
{
    public function contains(TeacherAvailability $availability, Carbon $start, Carbon $end): bool
    {
        if ($availability->status !== 'active' || $start->gte($end)) {
            return false;
        }
        $local = $start->copy()->setTimezone($availability->timezone);
        $date = $local->toDateString();
        if ($availability->recurrence_type === 'weekly') {
            if ($local->dayOfWeek !== $availability->weekday) {
                return false;
            }
            if ($availability->date && $date < $availability->date->toDateString()) {
                return false;
            }
        } elseif (! $availability->date || $date !== $availability->date->toDateString()) {
            return false;
        }
        $windowStart = Carbon::parse($date.' '.$availability->start_time, $availability->timezone);
        $windowEnd = Carbon::parse($date.' '.$availability->end_time, $availability->timezone);

        return $start->gte($windowStart) && $end->lte($windowEnd);
    }

    public function book(Student $student, TeacherAvailability $availability, array $data): Session
    {
        return DB::transaction(function () use ($student, $availability, $data) {
            // A stable teacher lock also serializes bookings from overlapping availability rows.
            Teacher::whereKey($availability->teacher_id)->lockForUpdate()->firstOrFail();
            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $availability = TeacherAvailability::whereKey($availability->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('book', $availability);
            $individual = Individual::whereKey($data['individual_id'])->where('student_id', $student->id)->lockForUpdate()->firstOrFail();
            abort_if(PaidBooking::where('individual_id', $individual->id)->exists(), 409, 'Paid teaching must use its payment workflow.');
            $start = Carbon::parse($data['starts_at'])->utc();
            $end = Carbon::parse($data['ends_at'])->utc();
            if ($start->lte(now()) || ! $this->contains($availability, $start, $end)
                || (int) $individual->teacherSubject?->teacher_id !== (int) $availability->teacher_id
                || ! app(SessionRules::class)->active($individual, $start, $end)) {
                throw ValidationException::withMessages(['individual_id' => ['Active individual teaching and a future available interval are required.']]);
            }
            $state = ['teacher_id' => $availability->teacher_id, 'availability_id' => $availability->id,
                'type_id' => $individual->id, 'type' => 'individual', 'capacity' => 1,
                'starts_at' => $start, 'ends_at' => $end, 'status' => 'scheduled'];
            abort_if(app(SessionRules::class)->conflicts($state), 409, 'Teacher or student already has an overlapping session.');

            return Session::create($state);
        }, 3);
    }

    public function slots(Teacher $teacher, Student $student, Carbon $from, Carbon $to): array
    {
        $result = [];
        $individuals = Individual::whereDoesntHave('paidBookings')->where('student_id', $student->id)->where('status', 'active')->whereHas('teacherSubject', fn ($q) => $q->where('teacher_id', $teacher->id))->get();
        if ($individuals->isEmpty()) {
            return [];
        }
        $busy = Session::whereIn('status', ['scheduled', 'ongoing'])->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->where(fn ($q) => $q->where('teacher_id', $teacher->id)->orWhere(fn ($q) => $q->forStudent($student->id)))->get();
        $busy = $busy->concat(PaidBooking::holding()->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->where(fn ($q) => $q->where('teacher_id', $teacher->id)->orWhere('student_id', $student->id))->get());
        foreach (TeacherAvailability::where('teacher_id', $teacher->id)->where('status', 'active')->get() as $availability) {
            for ($day = $from->copy()->setTimezone($availability->timezone)->startOfDay(); $day->lt($to); $day->addDay()) {
                $start = Carbon::parse($day->toDateString().' '.$availability->start_time, $availability->timezone)->utc();
                $end = Carbon::parse($day->toDateString().' '.$availability->end_time, $availability->timezone)->utc();
                if (! $this->contains($availability, $start, $end) || $end->lte($from) || $start->gte($to)) {
                    continue;
                }
                $ranges = [[max($start->timestamp, $from->timestamp), min($end->timestamp, $to->timestamp)]];
                foreach ($busy as $session) {
                    $next = [];
                    foreach ($ranges as [$a,$b]) {
                        $x = $session->starts_at->timestamp;
                        $y = $session->ends_at->timestamp;
                        if ($x >= $b || $y <= $a) {
                            $next[] = [$a, $b];

                            continue;
                        }
                        if ($a < $x) {
                            $next[] = [$a, $x];
                        }
                        if ($y < $b) {
                            $next[] = [$y, $b];
                        }
                    }
                    $ranges = $next;
                }
                // Split at individual teaching date boundaries so partial validity never hides a bookable interval.
                foreach ($ranges as [$a,$b]) {
                    $points = [$a, $b];
                    foreach ($individuals as $individual) {
                        foreach ([$individual->starts_at, $individual->ends_at] as $boundary) {
                            if ($boundary && $boundary->timestamp > $a && $boundary->timestamp < $b) {
                                $points[] = $boundary->timestamp;
                            }
                        }
                    }
                    sort($points);
                    $points = array_values(array_unique($points));
                    for ($i = 0; $i < count($points) - 1; $i++) {
                        $s = Carbon::createFromTimestampUTC($points[$i]);
                        $e = Carbon::createFromTimestampUTC($points[$i + 1]);
                        $ids = $individuals->filter(fn ($individual) => app(SessionRules::class)->active($individual, $s, $e))->pluck('id')->values();
                        if ($ids->isNotEmpty()) {
                            $result[] = ['availability_id' => $availability->id, 'starts_at' => $s->toIso8601String(), 'ends_at' => $e->toIso8601String(), 'eligible_individual_ids' => $ids];
                        }
                    }
                }
            }
        }
        usort($result, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return $result;
    }
}
