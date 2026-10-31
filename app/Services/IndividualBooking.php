<?php

namespace App\Services;

use App\Models\Session;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class IndividualBooking
{
    public function contains(TeacherAvailability $availability, Carbon $start, Carbon $end): bool
    {
        return $start->gt(now()) && app(AvailabilityBooking::class)->contains($availability, $start, $end);
    }

    public function book(Student $student, Teacher $teacher, array $data): Session
    {
        $availability = TeacherAvailability::where('teacher_id', $teacher->id)->findOrFail($data['availability_id']);
        try {
            return app(AvailabilityBooking::class)->book($student, $availability, $data);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
            throw ValidationException::withMessages(['starts_at' => $error->getMessage()]);
        }
    }
}
