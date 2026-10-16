<?php

namespace App\Services;

use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IndividualBooking
{
    public function contains(TeacherAvailability $availability, Carbon $start, Carbon $end): bool
    {
        $local = $start->copy()->setTimezone($availability->timezone);
        $day = $local->toDateString();
        if ($availability->status !== 'active'
            || ($availability->recurrence_type === 'weekly'
                ? $local->dayOfWeek !== $availability->weekday
                : $availability->date?->toDateString() !== $day)) {
            return false;
        }
        $from = Carbon::parse($day.' '.$availability->start_time, $availability->timezone);
        $to = Carbon::parse($day.' '.$availability->end_time, $availability->timezone);

        return $start->gt(now()) && $end->gt($start) && $start->gte($from) && $end->lte($to);
    }

    public function book(Student $student, Teacher $teacher, array $data): Session
    {
        return DB::transaction(function () use ($student, $teacher, $data) {
            // All schedule writers serialize on the teacher, then participating students.
            Teacher::whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $availability = TeacherAvailability::where('teacher_id', $teacher->id)->whereKey($data['availability_id'])->lockForUpdate()->firstOrFail();
            $start = Carbon::parse($data['starts_at']);
            $end = Carbon::parse($data['ends_at']);
            if (! $this->contains($availability, $start, $end)) {
                throw ValidationException::withMessages(['starts_at' => 'Time must be within an active teacher availability.']);
            }
            $session = new Session(array_merge($data, ['teacher_id' => $teacher->id, 'type' => 'INDIVIDUAL',
                'group_id' => null, 'capacity' => 1, 'status' => 'scheduled']));
            $this->authorizeEnrollment($student, $session);
            app(SessionRules::class)->validate($session);
            $session->save();

            return $session;
        }, 3);
    }

    private function authorizeEnrollment(Student $student, Session $session): void
    {
        if (! app(SessionEligibility::class)->enrollments($student->id, $session)->whereKey($session->enrollment_id)->exists()) {
            throw ValidationException::withMessages(['enrollment_id' => 'The enrollment is not eligible for this student and teacher.']);
        }
    }
}
