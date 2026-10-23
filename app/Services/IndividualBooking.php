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
        return $start->gt(now()) && app(AvailabilityBooking::class)->contains($availability, $start, $end);
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
            // Keep the legacy endpoint's 422 validation contract while sharing
            // overlap and enrollment validity rules with the new booking API.
            $rules = app(SessionRules::class);
            if (! $rules->active($session->enrollment, $start, $end) || $rules->conflicts($session->getAttributes())) {
                throw ValidationException::withMessages(['starts_at' => 'The enrollment or requested interval is no longer available.']);
            }
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
