<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Student;
use Illuminate\Validation\ValidationException;

class GroupMembershipRules
{
    public function validate(Group $group, Student $student): void
    {
        Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
        if ($student->status !== 'active' || $student->user?->status !== 'active') {
            throw ValidationException::withMessages(['student_id' => 'Student must be active.']);
        }
        foreach ($group->sessions()->whereIn('status', ['scheduled', 'ongoing'])->get() as $session) {
            abort_if($session->capacity <= $group->groupStudents()->where('status', 'active')->where('student_id', '!=', $student->id)->count(), 409, 'Scheduled session capacity reached.');
            abort_if(app(PaidLessonBookings::class)->holdConflicts($session->teacher_id, [$student->id], $session->starts_at, $session->ends_at)
                || app(SessionEligibility::class)->conflicts($student->id, $session)->where(fn ($q) => $q->where('type', '!=', 'group')->orWhere('type_id', '!=', $group->id))->exists(), 409, 'Student has an overlapping session or paid hold.');
        }
    }
}
